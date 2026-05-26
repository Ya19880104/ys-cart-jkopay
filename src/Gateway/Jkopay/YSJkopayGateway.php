<?php
/**
 * 街口支付 OnlinePay 閘道
 *
 * 直接串接街口支付平台（非透過 PayUni / Shopline 二房東）。
 *
 * 流程：
 *   1. 顧客選擇街口 → process_payment() 呼叫 client::create_entry()
 *   2. 街口回傳 payment_url（網頁版）或 qr_img（APP 版；v2.39.6 P3-A 修正：
 *      原本僅讀 qrcode_url，但官方 spec 使用 qr_img）
 *   3. 顧客完成付款後街口背景 POST 到 callback URL
 *   4. YSJkopayCallbackController 驗 HMAC + 呼叫 YSJkopayWebhookHandler::handle()
 *
 * @package YangSheep\YSCartJkopay\Gateway\Jkopay
 * @since   2.38.0
 */

namespace YangSheep\YSCartJkopay\Gateway\Jkopay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Gateways\YSGatewayInterface;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\Ecommerce\Utils\YSPriceHelper;
use YangSheep\Ecommerce\YSEcommerce;
use YangSheep\YSCartJkopay\Plugin;

class YSJkopayGateway implements YSGatewayInterface {

    /** 閘道 ID（前後台與註冊表用） */
    public const GATEWAY_ID = 'ys_ec_jkopay';

    /** 預設 QR Code 有效時間（秒） */
    private const DEFAULT_QR_VALID_TIME = 600;

    /** Lazy-loaded API client，避免 boot 時無謂初始化 */
    private ?YSJkopayClient $client_instance = null;

    /**
     * 取得 Client（lazy）
     */
    protected function get_client(): YSJkopayClient {
        if ( null === $this->client_instance ) {
            $this->client_instance = new YSJkopayClient();
        }
        return $this->client_instance;
    }

    // =========================================================================
    // YSGatewayInterface 必要實作
    // =========================================================================

    public function get_id(): string {
        return self::GATEWAY_ID;
    }

    public function get_title(): string {
        return '街口支付';
    }

    public function get_description(): string {
        return '使用街口支付 APP 掃碼或網頁付款。';
    }

    public function get_icon(): string {
        return 'dashicons-money-alt';
    }

    /**
     * 自訂 SVG icon（街口品牌色 #D71A22）
     */
    public function get_icon_html(): string {
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#D71A22" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . '<rect x="5" y="2" width="14" height="20" rx="2"/>'
             . '<path d="M9 7h6"/>'
             . '<path d="M12 18h.01"/>'
             . '</svg>';
    }

    public function is_enabled(): bool {
        if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
            return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_method_enabled(
                'payment',
                self::GATEWAY_ID,
                Plugin::manifest()
            );
        }

        $ec = YSEcommerce::get_instance();
        return $ec->get_setting( 'ys_ec_jkopay_enabled', '0' ) === '1';
    }

    public function is_available( array $order_data ): bool {
        if ( ! $this->is_enabled() || ! $this->get_client()->is_configured() ) {
            return false;
        }
        $total = (float) ( $order_data['total'] ?? $order_data['order_total'] ?? 0 );
        if ( $total > 0 ) {
            $min = $this->get_min_amount();
            $max = $this->get_max_amount();
            if ( $min > 0 && $total < $min ) {
                return false;
            }
            if ( $max > 0 && $total > $max ) {
                return false;
            }
        }
        return true;
    }

    public function get_min_amount(): float {
        return 1.0;
    }

    /**
     * 街口無官方上限，回 0 表示不限
     */
    public function get_max_amount(): float {
        return 0.0;
    }

    public function supports_token(): bool {
        return false;
    }

    public function process_token_charge( int $subscription_id, float $override_amount = 0.0 ): array {
        return [ 'success' => false, 'message' => '街口支付不支援 Token 定期扣款。' ];
    }

    public function get_settings_fields(): array {
        // 設定 UI 由 YSJkopaySettings + jkopay-settings.php 提供
        return [];
    }

    /**
     * 規範化 payment_type（v2.38.1 G1：對齊街口真實 spec onetime / regular）
     *
     * v2.38.0 用了錯的值 onepage / app，這裡向下相容自動轉換：
     *   - onepage → onetime
     *   - app     → regular
     *   - 其他無效值 → onetime（預設）
     */
    public static function normalize_payment_type( string $value ): string {
        $legacy_map = [
            'onepage' => 'onetime',
            'app'     => 'regular',
        ];
        if ( isset( $legacy_map[ $value ] ) ) {
            return $legacy_map[ $value ];
        }
        return in_array( $value, [ 'onetime', 'regular' ], true ) ? $value : 'onetime';
    }

    // =========================================================================
    // 付款流程
    // =========================================================================

    /**
     * 處理付款 — 呼叫街口 entry API，回傳跳轉 URL
     *
     * v2.39.6 P3-A：QR code URL 從 result_object 取出時嘗試多個欄位名（qr_img 為官方
     * spec 主名稱，qrcode_url / qr_image / qrCodeUrl 為 fallback）。回傳 key 仍維持
     * `qrcode_url` 不破壞既有 caller。
     *
     * @param int $order_id YS 訂單 ID
     * @return array { success: bool, redirect_url?: string, message?: string, payment_url?: string, qrcode_url?: string }
     */
    public function process_payment( int $order_id ): array {
        $order = YSOrder::find( $order_id );
        if ( ! $order ) {
            return [ 'success' => false, 'message' => '訂單不存在。' ];
        }

        $ec       = YSEcommerce::get_instance();
        $client   = $this->get_client();

        if ( ! $client->is_configured() ) {
            return [ 'success' => false, 'message' => '街口支付尚未完成設定。' ];
        }

        // 街口金額為整數元
        $amount = (int) round( YSPriceHelper::round( (float) $order->total ) );
        if ( $amount <= 0 ) {
            return [ 'success' => false, 'message' => '訂單金額不正確。' ];
        }

        // ── 計算 callback / return URL ──
        $callback_url = self::get_callback_url();

        // v2.21.7：發放 TKS session cookie（與其他金流一致），讓感謝頁能驗證
        if ( method_exists( YSOrder::class, 'issue_tks_token' ) ) {
            YSOrder::issue_tks_token( (int) $order_id );
        }
        $thankyou_url = add_query_arg( [
            'order' => $order_id,
            'key'   => YSOrder::generate_order_key( $order_id, $order->order_number ),
        ], home_url( '/thankyou/' ) );

        $valid_time   = (int) $ec->get_setting( 'ys_ec_jkopay_qr_valid_time', (string) self::DEFAULT_QR_VALID_TIME );
        if ( $valid_time <= 0 ) {
            $valid_time = self::DEFAULT_QR_VALID_TIME;
        }
        // v2.38.1 G1: 街口 spec 要求 valid_time 是 `yyyy-MM-dd HH:mm:ss` datetime，
        // 不是「秒數」。設定值仍保留為「過期秒數」（UI 直觀），這裡轉成 datetime 字串。
        $valid_time_str = wp_date( 'Y-m-d H:i:s', time() + $valid_time );

        // v2.38.1 G1: payment_type 真實 spec 是 onetime / regular，不是 onepage / app。
        // normalize_payment_type() 同時處理 v2.38.0 舊值 (onepage→onetime / app→regular)。
        $payment_type = self::normalize_payment_type(
            (string) $ec->get_setting( 'ys_ec_jkopay_payment_type', 'onetime' )
        );

        // v2.38.1 G1: store_id 真實 spec 是 36-char UUID，**不可截斷**。
        // 不在此處做截斷，僅做 sanitize（YSJkopaySettings::handle_save 已限制 maxlength=36）。
        // platform_order_id 仍保留 32 字限制（這是另一個欄位）。
        $platform_order_id = mb_substr( (string) $order->order_number, 0, 32 );

        $extra_info = wp_json_encode( [
            'order_no'  => $order->order_number,
            'order_id'  => (int) $order_id,
        ] );

        // v2.38.1 G1: 移除 confirm_url 欄位 — 街口 spec 規定它應指向 JSON callback endpoint，
        // 我們沒實作 confirm endpoint（result_url 已是 webhook callback），因此不應誤傳此欄位。
        $payload = [
            'platform_order_id'  => $platform_order_id,
            'currency'           => 'TWD',
            'total_price'        => $amount,
            'final_price'        => $amount,
            'valid_time'         => $valid_time_str,
            'result_url'         => $callback_url,
            'result_display_url' => $thankyou_url,
            'payment_type'       => $payment_type,
            'extra_info'         => is_string( $extra_info ) ? $extra_info : '',
        ];

        $result = $client->create_entry( $payload );

        if ( ! $result['success'] ) {
            YSLogger::error( 'jkopay', '建立交易失敗', [
                'order_id' => $order_id,
                'message'  => $result['message'],
            ] );
            return [
                'success' => false,
                'message' => $result['message'] ?: '街口支付建立交易失敗。',
            ];
        }

        $object       = is_array( $result['data']['result_object'] ?? null ) ? $result['data']['result_object'] : [];
        $trade_no     = (string) ( $object['tradeNo']     ?? '' );
        $payment_url  = (string) ( $object['payment_url'] ?? '' );
        // v2.39.6 P3-A：街口官方 spec 用 qr_img 為主，但歷史回應/其他環境可能使用
        // qrcode_url / qr_image / qrCodeUrl。defensive 嘗試多個名稱以避免 QR 取不到。
        // DB 仍存為 ys_jkopay_qrcode_url（向下相容既有 payment_detail 讀取端）。
        $qrcode_url   = (string) (
            $object['qr_img']     ??
            $object['qrcode_url'] ??
            $object['qr_image']   ??
            $object['qrCodeUrl']  ??
            ''
        );
        $first_status = (string) ( $object['status']      ?? 'P' );

        // 把 trade_no 與 payment URL 寫入訂單，方便後續對帳 / 客服查詢
        $payment_detail = json_decode( (string) ( $order->payment_detail ?? '{}' ), true ) ?: [];
        $payment_detail[ YSJkopayWebhookHandler::META_TRADE_NO ]    = $trade_no;
        $payment_detail[ YSJkopayWebhookHandler::META_LAST_STATUS ] = $first_status;
        $payment_detail['ys_jkopay_platform_order_id'] = $platform_order_id;
        $payment_detail['ys_jkopay_payment_url']       = $payment_url;
        $payment_detail['ys_jkopay_qrcode_url']        = $qrcode_url;
        $payment_detail['ys_jkopay_payment_type']      = $payment_type;
        $payment_detail['ys_jkopay_test_mode']         = $client->is_test_mode() ? '1' : '0';

        YSOrder::update( (int) $order_id, [
            'gateway_id'       => self::GATEWAY_ID,
            'payment_method'   => self::GATEWAY_ID,
            'gateway_trade_no' => $trade_no,
            'payment_detail'   => wp_json_encode( $payment_detail ),
        ] );

        $redirect_url = $payment_url ?: $qrcode_url ?: $thankyou_url;

        return [
            'success'      => true,
            'redirect_url' => $redirect_url,
            'payment_url'  => $payment_url,
            'qrcode_url'   => $qrcode_url,
            'message'      => '',
        ];
    }

    /**
     * 處理退款
     *
     * 街口支援部分退款（refund_amount 可小於 total_price），且街口以 refund_order_id
     * 做 idempotent key 去重（同 refund_order_id 視為同一筆退款，會直接拒絕重送）。
     *
     * v2.39.2 P1 修正（CODEX edge case）：
     *   v2.39.1 把 signature 改成 md5( order_id . '_' . amount . '_' . reason )，
     *   解決了 v2.39.0 的 retry 不穩問題。但這留下 edge case：
     *     若同一張單需要做兩次「同金額、同原因」的合法部分退款（例如員工分兩次操作、
     *     或對帳系統重新觸發），會被誤判成同一筆 retry，重用同一個 refund_order_id，
     *     第二次合法退款被街口拒絕（duplicate refund_order_id）。
     *
     *   修正策略：引入 caller-supplied refund_request_id 作為主 idempotency key。
     *     - $context['refund_request_id'] 由上層 handler 傳入（YSRefundHandler 每次
     *       generate UUID；caller 可選擇複用以表達「真正的 retry」）。
     *     - 主路徑 signature = md5( order_id . '_request_' . refund_request_id )
     *     - 沒帶 refund_request_id 的 legacy caller 走 fallback：
     *       signature = md5( order_id . '_legacy_' . amount . '_' . reason )
     *       行為與 v2.39.1 完全一致，向下相容無破壞。
     *
     * v2.41.0 P1-B 修正（CODEX 高風險，已在 v2.39.1 完成）：
     *   v2.39.0 用 count(refund_history) 做 signature → 同次重試在不同時間點算出
     *   不同 signature → 找不到既有 entry → 重用失敗 → 產生新 refund_order_id →
     *   街口執行第二次扣款 → 退兩次錢。已改用穩定輸入。
     *
     * v2.38.1 G3 既有行為保留：
     *   - 多次部分退款支援：refund history 存於 array `_ys_jkopay_refunds`
     *   - 向下相容 v2.38.0 留下的 single value `_ys_jkopay_refund_id`
     *
     * @param int    $order_id YS 訂單 ID
     * @param float  $amount   退款金額（元）
     * @param string $reason   退款原因（fallback 路徑會混入 signature）
     * @param array  $context  v2.39.2+；context['refund_request_id'] 為主 idempotency key
     */
    public function process_refund( int $order_id, float $amount, string $reason = '', array $context = [] ): array {
        $order = YSOrder::find( $order_id );
        if ( ! $order ) {
            return [ 'success' => false, 'message' => '訂單不存在。' ];
        }

        $payment_detail = json_decode( (string) ( $order->payment_detail ?? '{}' ), true ) ?: [];
        $platform_id    = (string) ( $payment_detail['ys_jkopay_platform_order_id']
            ?? $order->order_number
            ?? '' );

        if ( '' === $platform_id ) {
            return [ 'success' => false, 'message' => '找不到街口對應的訂單編號。' ];
        }

        $refund_amount = (int) round( $amount );
        if ( $refund_amount <= 0 ) {
            return [ 'success' => false, 'message' => '退款金額不正確。' ];
        }

        // ── 讀取既有退款歷史 ──
        $refund_history = $payment_detail['_ys_jkopay_refunds'] ?? [];
        if ( ! is_array( $refund_history ) ) {
            $refund_history = [];
        }

        // ── 向下相容 v2.38.0：把舊的 single value `_ys_jkopay_refund_id` 升級到 array ──
        if ( isset( $payment_detail['_ys_jkopay_refund_id'] ) && empty( $refund_history ) ) {
            $legacy_id = (string) $payment_detail['_ys_jkopay_refund_id'];
            if ( '' !== $legacy_id ) {
                $refund_history[] = [
                    'refund_order_id' => $legacy_id,
                    'amount'          => 0,                  // unknown legacy
                    'reason'          => '',
                    'signature'       => 'legacy_v2380',
                    'requested_at'    => null,
                ];
            }
        }

        // ── v2.39.2 P1：caller-supplied refund_request_id 為主 idempotency key ──
        // 主路徑：context['refund_request_id'] 由上層 YSRefundHandler 提供（每次呼叫
        // generate 唯一 UUID；若 caller 想表達「真正的 retry」可複用同一 ID）。
        // Fallback 路徑：未帶 refund_request_id 的 legacy caller 走 v2.39.1 行為，
        //                 amount + reason 作為 signature 來源（同 amount/reason 仍會
        //                 被視為 retry — 這是已知 v2.39.1 limitation，但向下相容無破壞）。
        $refund_request_id = isset( $context['refund_request_id'] )
            ? (string) $context['refund_request_id']
            : '';

        if ( '' !== $refund_request_id ) {
            // 主路徑：穩定 + 區分新退款
            $signature_input = $order_id . '_request_' . $refund_request_id;
        } else {
            // Fallback：v2.39.1 行為（amount + reason）
            $signature_input = $order_id . '_legacy_' . $refund_amount . '_' . $reason;
        }
        $refund_signature = md5( $signature_input );

        // 如果 history 中已有同 signature → 是重試 → 重用既有 refund_order_id
        $reused          = false;
        $refund_order_id = '';
        foreach ( $refund_history as $entry ) {
            if ( ( $entry['signature'] ?? '' ) === $refund_signature ) {
                $refund_order_id = (string) ( $entry['refund_order_id'] ?? '' );
                if ( '' !== $refund_order_id ) {
                    $reused = true;
                    break;
                }
            }
        }

        // 新一筆退款 → 產生新的 refund_order_id 並寫入 history
        if ( ! $reused ) {
            // v2.39.2：把 UUID 內的 dash 拿掉再 substr，確保 32-char 限制下仍有充足
            // 唯一性（去 dash 後 32 hex chars 提供 ~10^38 collision space）。
            $uuid_no_dash    = str_replace( '-', '', wp_generate_uuid4() );
            $refund_order_id = substr( 'YS' . $order_id . 'R' . $uuid_no_dash, 0, 32 );
            $refund_history[] = [
                'refund_order_id'   => $refund_order_id,
                'amount'            => $refund_amount,
                'reason'            => $reason,
                'refund_request_id' => $refund_request_id,
                'signature'         => $refund_signature,
                'requested_at'      => current_time( 'mysql' ),
            ];
            $payment_detail['_ys_jkopay_refunds'] = $refund_history;
            // 也保留舊欄位最近一次值，避免外部讀舊 key 找不到（只是 informational mirror）
            $payment_detail['_ys_jkopay_refund_id'] = $refund_order_id;
            YSOrder::update( (int) $order_id, [
                'payment_detail' => wp_json_encode( $payment_detail ),
            ] );
        }

        $result = $this->get_client()->refund( $platform_id, $refund_order_id, $refund_amount );

        if ( $result['success'] ) {
            YSLogger::info( 'jkopay', '退款成功', [
                'order_id'          => $order_id,
                'refund_order_id'   => $refund_order_id,
                'refund_amount'     => $refund_amount,
                'refund_request_id' => $refund_request_id,
                'reused'            => $reused,
            ] );
            return [
                'success'         => true,
                'transaction_id'  => $refund_order_id,
                'refund_order_id' => $refund_order_id,
                'message'         => '街口支付退款成功。',
            ];
        }

        YSLogger::error( 'jkopay', '退款失敗', [
            'order_id' => $order_id,
            'message'  => $result['message'],
        ] );
        return [
            'success' => false,
            'message' => $result['message'] ?: '街口支付退款失敗。',
        ];
    }

    // =========================================================================
    // 工具
    // =========================================================================

    /**
     * 取得 webhook callback URL（給管理員 UI 與 entry payload 共用）
     */
    public static function get_callback_url(): string {
        return rest_url( 'ys-ecommerce-headless/v1/payment/jkopay/callback' );
    }

    /**
     * 取得後台設定頁網址
     */
    public function get_settings_url(): string {
        return admin_url( 'admin.php?page=ys-provider-jkopay' );
    }
}
