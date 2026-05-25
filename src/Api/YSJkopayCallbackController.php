<?php
/**
 * 街口支付 Webhook REST 端點
 *
 * 路由：POST /wp-json/ys-ecommerce-headless/v1/payment/jkopay/callback
 *
 * 安全鏈（v2.39.7 P2-B v2 重排）：
 *   1. IP allowlist（YSWebhookGuard，可選）
 *   2. 必要欄位存在（digest header + body）
 *   3. Body size cap（防 memory exhaustion DoS — cheap）
 *   4. HMAC 簽章驗證 FIRST（hash_equals — timing safe，~1ms）
 *      → 失敗：strict rate limit（10/min/IP）擋 brute force/spam，回 401
 *      → 通過：verified burst rate limit（300/min/IP）給合法重試空間
 *   5. 找得到對應訂單
 *   6. 委由 YSJkopayWebhookHandler 做 idempotent + lifecycle 推進
 *      （idempotency 在 handler 層用 _ys_jkopay_trade_no + _ys_jkopay_last_status 守，
 *       不在 callback 層用 replay guard，避免擋掉街口的合法重試）
 *
 * 為什麼 HMAC 在 rate limit 之前？
 *   v2.39.6 把 rate limit 設成 60/min/IP（pre-HMAC）+ 300/min/IP（post-HMAC）。
 *   但只要 pre-HMAC 60/min 被擋住，post-HMAC burst 300/min 永遠進不來。
 *   街口在 12 次重試 burst 中會撞到 60/min 上限導致 verified callback 被擋。
 *   解：HMAC 失敗才是真正的 spam 訊號，先 HMAC 再判 rate limit bucket。
 *
 * 永遠回應 HTTP 200 + `{ "result": "000" }` 給街口（除非簽章不對才回 401），
 * 避免街口因為非 200 開始 12 次重試。
 *
 * @package YangSheep\Ecommerce\Api
 * @since   2.38.0
 */

namespace YangSheep\YSCartJkopay\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\YSCartJkopay\Gateway\Jkopay\YSJkopayClient;
use YangSheep\YSCartJkopay\Gateway\Jkopay\YSJkopayWebhookHandler;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Security\YSRateLimiter;
use YangSheep\Ecommerce\Security\YSWebhookGuard;
use YangSheep\Ecommerce\Utils\YSLogger;

class YSJkopayCallbackController {

    /** REST namespace（與 Storefront 共用） */
    public const REST_NAMESPACE = 'ys-ecommerce-headless/v1';

    /**
     * v2.39.7 P2-B v2：HMAC 失敗時的 strict bucket（anti-bruteforce / anti-spam）。
     *
     * 預設 10/min/IP — 比合法 verified 流量（300/min）嚴格 30×，
     * 攻擊者帶錯 signature 多次嘗試會迅速被擋。可透過 filter 調整。
     */
    private const RATE_LIMIT_KEY_INVALID = 'jkopay_callback_invalid_hmac';

    /**
     * v2.39.6 P2-B / v2.39.7 P2-B v2：HMAC 通過後的 verified-callback burst bucket。
     *
     * 街口在收不到 200 reply 時會合法重試最多 12 次（2^n 退避），
     * 預設 300/min/IP 給 verified 流量足夠的 burst 容忍空間。
     * 可透過 ys_ec_jkopay_verified_callback_burst filter 調整。
     */
    private const RATE_LIMIT_KEY_VERIFIED = 'jkopay_callback_verified_hmac';

    /**
     * v2.39.7 P2-B v2：body size cap（防 memory exhaustion DoS）。
     *
     * 街口正常 callback payload < 4KB；64KB 已經非常寬鬆。
     * 在 HMAC 之前檢查（cheap = strlen），不讓 attacker 用大 body 拖垮 HMAC 計算。
     */
    private const MAX_BODY_BYTES = 65536;

    /** Webhook context（IP allowlist filter 共用名稱） */
    private const REPLAY_CONTEXT = 'jkopay_webhook';

    /**
     * 註冊 REST routes（給 YSRestBootstrap::register_routes() 呼叫）
     */
    public static function register_routes(): void {
        register_rest_route( self::REST_NAMESPACE, '/payment/jkopay/callback', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_callback' ],
            // permission 由 HMAC 驗證；此處放行給街口的 server-to-server 呼叫
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * 主要處理器
     *
     * v2.39.7 P2-B v2 — HMAC-first ordering：
     *   先做 cheap defense（IP allowlist → body sanity → body cap → HMAC verify），
     *   再依 HMAC 結果分流到 strict（invalid）或 burst（verified）rate limit bucket。
     *   這樣街口的合法 12-retry burst 不會被 pre-HMAC 全擋 60/min 卡住。
     */
    public static function handle_callback( \WP_REST_Request $request ): \WP_REST_Response {
        // ─── Step 1: IP allowlist（可選；管理員未設定 filter 時預設放行）───
        if ( ! YSWebhookGuard::verify_ip( self::REPLAY_CONTEXT ) ) {
            return self::reply_error( 'IP not allowed', 403 );
        }

        $body   = (string) $request->get_body();
        $digest = (string) $request->get_header( 'digest' );

        // ─── Step 2: Body / digest 必填（cheap header check）───
        if ( '' === $body || '' === $digest ) {
            YSLogger::warning( 'jkopay_webhook', '缺少 body 或 digest 標頭', [
                'has_body'   => '' !== $body,
                'has_digest' => '' !== $digest,
            ] );
            return self::reply_error( 'Missing body or digest', 400 );
        }

        // ─── Step 3: Body size cap（cheap strlen，防 HMAC 計算成本被大 body 拖垮）───
        if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
            YSLogger::warning( 'jkopay_webhook', 'Body too large', [
                'size' => strlen( $body ),
                'cap'  => self::MAX_BODY_BYTES,
            ] );
            return self::reply_error( 'Body too large', 413 );
        }

        // ─── Step 4: Gateway 設定檢查（沒設定就無法 verify）───
        $client = new YSJkopayClient();
        if ( ! $client->is_configured() ) {
            YSLogger::error( 'jkopay_webhook', '收到 webhook 但設定不完整，拒絕' );
            return self::reply_error( 'Gateway not configured', 503 );
        }

        // ─── Step 5: HMAC 驗章 FIRST（hash_equals — timing safe，~1ms）───
        // 注意：不要在 verify 之前做任何 expensive state read（DB query、order lookup），
        //       否則 attacker 可以用 invalid HMAC 觸發 expensive query → DoS。
        $is_valid_hmac = $client->verify_signature( $body, $digest );

        if ( ! $is_valid_hmac ) {
            // ─── Step 5a: HMAC 失敗 → strict rate limit bucket（anti-bruteforce）───
            // 預設 10/min/IP — 攻擊者帶錯 signature 多嘗試會迅速被擋。
            $invalid_max    = (int) apply_filters( 'ys_ec_jkopay_invalid_hmac_rate_limit', 10 );
            $invalid_window = (int) apply_filters( 'ys_ec_jkopay_invalid_hmac_window', 60 );
            if ( $invalid_max < 1 ) {
                $invalid_max = 10;
            }
            if ( $invalid_window < 1 ) {
                $invalid_window = 60;
            }

            if ( ! YSRateLimiter::check( self::RATE_LIMIT_KEY_INVALID, $invalid_max, $invalid_window ) ) {
                YSLogger::warning( 'jkopay_webhook', 'Invalid-HMAC strict rate limit hit', [
                    'ip'     => YSRateLimiter::get_client_ip(),
                    'max'    => $invalid_max,
                    'window' => $invalid_window,
                ] );
                return self::reply_error( 'Invalid HMAC rate limit exceeded', 429 );
            }

            YSLogger::error( 'jkopay_webhook', 'HMAC 驗證失敗', [
                'ip' => YSRateLimiter::get_client_ip(),
            ] );
            return self::reply_error( 'Invalid signature', 401 );
        }

        // ─── Step 5b: HMAC 通過 → verified burst rate limit bucket（容忍合法 12-retry）───
        // 預設 300/min/IP — 給街口 burst 重試足夠的容忍空間。
        // 兩個 limit 都是 per-IP（街口出口 IP 通常固定，不會有「跨客戶搶 quota」問題）。
        $verified_max    = (int) apply_filters( 'ys_ec_jkopay_verified_callback_burst', 300 );
        $verified_window = (int) apply_filters( 'ys_ec_jkopay_verified_callback_window', 60 );
        if ( $verified_max < 1 ) {
            $verified_max = 300;
        }
        if ( $verified_window < 1 ) {
            $verified_window = 60;
        }
        if ( ! YSRateLimiter::check( self::RATE_LIMIT_KEY_VERIFIED, $verified_max, $verified_window ) ) {
            YSLogger::warning( 'jkopay_webhook', 'Verified-callback burst limit hit', [
                'ip'     => YSRateLimiter::get_client_ip(),
                'max'    => $verified_max,
                'window' => $verified_window,
            ] );
            return self::reply_error( 'Verified callback rate limit exceeded', 429 );
        }

        // ─── Step 6: 解析 payload ───
        // v2.38.0 hotfix：原本在這裡呼叫 YSWebhookGuard::check_replay() 攔截重複 digest，
        // 但街口在沒收到 200 reply 時會合法重試最多 12 次（2^n 退避）。
        // 同 body = 同 digest → 重放守衛會把合法重試誤判為攻擊 → 街口反覆重試到放棄
        // → 訂單狀態最終卡住。
        // 解法：移除 callback 端的 replay guard，idempotency 改由 handler 層
        // 透過 _ys_jkopay_trade_no + _ys_jkopay_last_status meta 強制執行
        // （見 YSJkopayWebhookHandler::handle 的 idempotent_noop 分支）。
        // 仍保留所有其他安全檢查：HMAC 驗章、rate limit、IP allowlist、body length。

        $payload = json_decode( $body, true );
        if ( ! is_array( $payload ) ) {
            YSLogger::warning( 'jkopay_webhook', 'JSON 解析失敗' );
            return self::reply_error( 'Invalid JSON', 400 );
        }

        // v2.38.1 G2：街口真實 callback payload 為 `{ "transaction": { ... } }` 巢狀結構，
        // 必要欄位（platform_order_id / status / tradeNo）皆在 transaction 物件內。
        $transaction = $payload['transaction'] ?? null;
        if ( ! is_array( $transaction ) ) {
            YSLogger::warning( 'jkopay_webhook', '缺少 transaction 物件', [
                'top_level_keys' => array_keys( $payload ),
            ] );
            return self::reply_error( 'Missing transaction object', 422 );
        }

        $platform_order_id = (string) ( $transaction['platform_order_id'] ?? '' );
        $status            = isset( $transaction['status'] ) ? (int) $transaction['status'] : -1;
        $trade_no          = (string) ( $transaction['tradeNo'] ?? '' );

        if ( '' === $platform_order_id ) {
            // 後備：部分舊版 webhook 仍可能用 extra_info 帶 order_no
            $extra = isset( $transaction['extra_info'] ) && is_string( $transaction['extra_info'] )
                ? json_decode( $transaction['extra_info'], true )
                : null;
            if ( is_array( $extra ) ) {
                $platform_order_id = (string) ( $extra['order_no'] ?? '' );
            }
        }

        if ( '' === $platform_order_id ) {
            YSLogger::warning( 'jkopay_webhook', '缺少 platform_order_id' );
            return self::reply_error( 'Missing platform_order_id', 400 );
        }

        if ( -1 === $status ) {
            YSLogger::warning( 'jkopay_webhook', '缺少 status 欄位', [
                'platform_order_id' => $platform_order_id,
            ] );
            return self::reply_error( 'Missing status', 422 );
        }

        // ─── Step 7: 找訂單 ───
        $order = YSOrder::find_by_number( $platform_order_id );
        if ( ! $order ) {
            YSLogger::warning( 'jkopay_webhook', '找不到對應訂單', [
                'platform_order_id' => $platform_order_id,
            ] );
            // 仍回 200 — 避免街口因為「找不到訂單」一直重試
            return self::reply_success( 'order not found, no retry' );
        }

        // ─── Step 8: 委由 handler 推進 ───
        // v2.38.1 G2：把扁平化過的關鍵欄位 + 完整 transaction 物件一起傳，
        // handler 可以直接用 status/tradeNo，也保留巢狀資料供未來擴充。
        $handler_payload = [
            'tradeNo'           => $trade_no,
            'status'            => $status,
            'platform_order_id' => $platform_order_id,
            'transaction'       => $transaction,
            // 為了讓 handler::build_detail_dto 取得 result/code_msg，仍保留 top-level 欄位
            'result'            => (string) ( $payload['result']   ?? '' ),
            'code_msg'          => (string) ( $payload['code_msg'] ?? '' ),
        ];

        $result = YSJkopayWebhookHandler::handle( (int) $order->id, $handler_payload );

        YSLogger::info( 'jkopay_webhook', 'webhook 流程完成', [
            'order_id' => (int) $order->id,
            'action'   => $result['action'] ?? '',
        ] );

        return self::reply_success( (string) ( $result['action'] ?? 'ok' ) );
    }

    // =========================================================================
    // Reply helpers
    // =========================================================================

    /**
     * 街口正常回覆格式：HTTP 200 + JSON `{"result":"000"}`
     */
    private static function reply_success( string $action = 'ok' ): \WP_REST_Response {
        return new \WP_REST_Response( [
            'result'   => '000',
            'code_msg' => 'OK',
            'action'   => $action,
        ], 200 );
    }

    /**
     * 錯誤回覆 — 街口會在非 200 時重試（最多 12 次，2^n 退避），
     * 因此只在「真的需要街口重試或被拒絕」時回 4xx/5xx。
     */
    private static function reply_error( string $message, int $http_code ): \WP_REST_Response {
        return new \WP_REST_Response( [
            'result'   => '999',
            'code_msg' => $message,
        ], $http_code );
    }
}
