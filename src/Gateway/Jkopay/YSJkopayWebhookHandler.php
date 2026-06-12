<?php
/**
 * 街口支付 Webhook 處理器
 *
 * 提供給 YSJkopayCallbackController 呼叫，負責：
 *   - 訂單查找（依 platform_order_id = order_number）
 *   - 狀態映射（v2.38.1 G2：街口真實 spec 為整數 0 / 100 / 101 / 102 → ys_ec 訂單狀態）
 *   - Idempotent guard：相同 trade_no + status 直接 noop
 *   - 透過 YSPaymentLifecycleService 統一推進訂單狀態
 *   - 觸發 `ys_jkopay_payment_status_changed` 動作 hook
 *
 * 所有更新都走 YSOrder model + YSPaymentLifecycleService，
 * 確保符合 v2.28.0 之後的 lifecycle 集中化原則。
 *
 * @package YangSheep\YSCartJkopay\Gateway\Jkopay
 * @since   2.38.0
 */

namespace YangSheep\YSCartJkopay\Gateway\Jkopay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\DTOs\YSPaymentDetailDTO;
use YangSheep\Ecommerce\Models\YSOrder;
use YangSheep\Ecommerce\Services\Payment\YSPaymentLifecycleService;
use YangSheep\Ecommerce\Utils\YSLogger;

class YSJkopayWebhookHandler {

    /** Meta key — 儲存街口端的交易序號 */
    public const META_TRADE_NO = '_ys_jkopay_trade_no';

    /** Meta key — 儲存最近一次處理過的街口狀態，用於 idempotent guard */
    public const META_LAST_STATUS = '_ys_jkopay_last_status';

    /**
     * 街口狀態碼對應描述（v2.38.1 G2：整數）
     *
     * 依街口官方 OnlinePay 通知協定：
     *   - 0   = success   付款成功
     *   - 100 = pending   等待付款
     *   - 101 = failed    付款失敗
     *   - 102 = refunded  已退款（informational；refund 由 process_refund 主動觸發）
     */
    private const STATUS_LABELS = [
        0   => 'success（付款成功）',
        100 => 'pending（等待付款）',
        101 => 'failed（付款失敗）',
        102 => 'refunded（已退款）',
    ];

    /**
     * 整數狀態碼 → 內部 action 名稱（給 switch 用）
     *
     * @since 2.38.1
     */
    public const STATUS_MAP = [
        0   => 'success',
        100 => 'pending',
        101 => 'failed',
        102 => 'refunded',
    ];

    /**
     * 對外暴露的 status mapper（測試用）
     *
     * @since 2.38.1
     */
    public static function map_status( int $code ): string {
        return self::STATUS_MAP[ $code ] ?? 'unknown';
    }

    /**
     * 主要入口 — 由 REST controller 呼叫
     *
     * @param int   $order_id YS 訂單 ID
     * @param array $payload  decoded webhook payload，至少含 tradeNo / status
     * @return array { success: bool, action: string, message: string }
     */
    public static function handle( int $order_id, array $payload ): array {
        $order = YSOrder::find( $order_id );
        if ( ! $order ) {
            YSLogger::warning( 'jkopay_webhook', '訂單不存在', [ 'order_id' => $order_id ] );
            return [ 'success' => false, 'action' => 'missing_order', 'message' => '訂單不存在' ];
        }

        $trade_no    = (string) ( $payload['tradeNo']     ?? $payload['trade_no']     ?? '' );
        // v2.38.1 G2：街口真實 status 是整數（0/100/101/102），不再是字串 S/F/P/R。
        // 用 -1 sentinel 表示「未提供」(因為 0 是合法成功值，不能用 0 當 sentinel)。
        $status      = isset( $payload['status'] ) ? (int) $payload['status'] : -1;
        $platform_id = (string) ( $payload['platform_order_id'] ?? '' );

        if ( -1 === $status ) {
            return [ 'success' => false, 'action' => 'missing_status', 'message' => '缺少 status 欄位' ];
        }

        // ── Idempotent guard ──
        // 同一筆 tradeNo + status 已處理過 → 直接回 noop（街口會在失敗時最多重試 12 次）
        $existing_detail = json_decode( (string) ( $order->payment_detail ?? '{}' ), true ) ?: [];
        $stored_trade_no    = (string) ( $existing_detail[ self::META_TRADE_NO ]   ?? '' );
        // v2.38.1 G2：last_status 改存整數（向下相容：舊值 'S'/'F'/... 用 string === false negative
        // 是可接受的，因為 v2.38.0 的整體欄位都改了，不會跨版本造成混淆）
        $stored_last_status = isset( $existing_detail[ self::META_LAST_STATUS ] )
            ? (int) $existing_detail[ self::META_LAST_STATUS ]
            : -1;

        if ( '' !== $stored_trade_no
            && $stored_trade_no === $trade_no
            && $stored_last_status === $status
        ) {
            YSLogger::info( 'jkopay_webhook', 'Idempotent noop — 同 trade_no + status 已處理', [
                'order_id' => $order_id,
                'trade_no' => $trade_no,
                'status'   => $status,
            ] );
            return [
                'success' => true,
                'action'  => 'idempotent_noop',
                'message' => 'already processed',
            ];
        }

        // ── 建立 DTO（用於 lifecycle service merge） ──
        $detail_dto = self::build_detail_dto( $payload, $trade_no, $status );

        // ── 立刻寫入 trade_no + last_status（即使 transition 被擋住，也要記錄這次看過） ──
        // 這層寫入用 YSOrder::update 直接 set，是 DTO 之外的 provider-specific bookkeeping
        $existing_detail[ self::META_TRADE_NO ]    = $trade_no;
        $existing_detail[ self::META_LAST_STATUS ] = $status;        // 整數
        $existing_detail['ys_jkopay_platform_order_id'] = $platform_id;
        $existing_detail['ys_jkopay_status_label']      = self::STATUS_LABELS[ $status ] ?? (string) $status;
        $existing_detail['ys_jkopay_callback_at']       = current_time( 'mysql' );

        YSOrder::update( $order_id, [
            'gateway_trade_no' => $trade_no ?: ( $order->gateway_trade_no ?? '' ),
            'payment_detail'   => wp_json_encode( $existing_detail ),
        ] );

        // ── 狀態映射 → 推進（v2.38.1 G2：整數對應 STATUS_MAP）──
        $action = 'no_transition';
        $mapped = self::STATUS_MAP[ $status ] ?? 'unknown';

        switch ( $mapped ) {
            case 'success':                    // 0 — 付款成功
                // v2.39.x 安全：若 payload 取不到 final_price，paid_amount 會缺席 →
                // 核心金額守衛 fail-open（不核對金額）。這是高風險情境，明確記 warning
                // 供營運稽核（仍照街口成功通知推進，避免合法訂單卡死，但留下軌跡）。
                if ( null === self::extract_paid_amount( $payload ) ) {
                    YSLogger::warning( 'jkopay_webhook', '付款成功但 payload 缺 final_price，金額守衛無法核對', [
                        'order_id'    => $order_id,
                        'trade_no'    => $trade_no,
                        'order_total' => (float) ( $order->total ?? 0 ),
                    ] );
                }
                $result = YSPaymentLifecycleService::mark_paid(
                    $order_id,
                    $detail_dto,
                    'webhook_jkopay'
                );
                $action = $result['success'] ? 'mark_paid' : 'mark_paid_rejected';
                break;

            case 'failed':                     // 101 — 付款失敗
                $result = YSPaymentLifecycleService::mark_failed(
                    $order_id,
                    $detail_dto->add_note( '街口支付付款失敗（webhook）', 'warning' ),
                    'webhook_jkopay',
                    'failed'
                );
                $action = $result['success'] ? 'mark_failed' : 'mark_failed_rejected';
                break;

            case 'pending':                    // 100 — 等待付款
                $result = YSPaymentLifecycleService::mark_pending_offline(
                    $order_id,
                    $detail_dto,
                    'webhook_jkopay_pending'
                );
                $action = $result['success'] ? 'mark_pending_offline' : 'mark_pending_offline_rejected';
                break;

            case 'refunded':                   // 102 — 退款 informational only
                // refund 流程由 process_refund 主動觸發；這裡只 log，不轉狀態
                YSLogger::info( 'jkopay_webhook', '街口端通知退款完成', [
                    'order_id' => $order_id,
                    'trade_no' => $trade_no,
                ] );
                $action = 'refund_logged';
                break;

            case 'unknown':
            default:
                YSLogger::warning( 'jkopay_webhook', '未知狀態碼', [
                    'order_id' => $order_id,
                    'status'   => $status,
                ] );
                break;
        }

        // ── 觸發第三方 hook ──
        /**
         * 街口支付狀態變更
         *
         * v2.38.1 起 status 為整數（0/100/101/102），mapped 為 success/failed/pending/refunded/unknown
         *
         * @since 2.38.0
         *
         * @param int    $order_id 訂單 ID
         * @param int    $status   街口端狀態整數（0/100/101/102）
         * @param array  $payload  原始 webhook payload
         * @param string $action   本次處理結果（mark_paid / mark_failed / idempotent_noop / ...）
         * @param string $mapped   對應內部 action（success / pending / failed / refunded / unknown）
         */
        do_action( 'ys_jkopay_payment_status_changed', $order_id, $status, $payload, $action, $mapped );

        YSLogger::info( 'jkopay_webhook', 'webhook 處理完成', [
            'order_id' => $order_id,
            'trade_no' => $trade_no,
            'status'   => $status,
            'mapped'   => $mapped,
            'action'   => $action,
        ] );

        return [
            'success' => true,
            'action'  => $action,
            'mapped'  => $mapped,
            'message' => 'processed',
        ];
    }

    /**
     * 建立 DTO（用於餵給 YSPaymentLifecycleService）
     *
     * 沒有為街口 specific 寫 factory，借用 from_legacy_array() + gateway_id_hint。
     *
     * v2.39.x 安全修正：補上 `paid_amount`（街口回傳的訂單實際消費金額）。
     * 核心 YSPaymentLifecycleService::transition_to 在進入 processing 時，會用
     * paid_amount_matches_order 守衛比對「實付金額 vs order->total」；但該守衛在
     * paid_amount <= 0 時 fail-open（return true）。先前本 handler 沒帶 paid_amount，
     * 守衛因此變成 no-op，webhook 一收到付款成功就照原價開單入帳、完全不核對金額。
     * 補上正確的 paid_amount 即可自動啟用核心既有的金額守衛（不需動 core）。
     */
    private static function build_detail_dto( array $payload, string $trade_no, string $status ): YSPaymentDetailDTO {
        $legacy = [
            'payment_type'     => 'jkopay',
            'trade_status'     => $status,
            'trade_no'         => $trade_no,
            'gateway_trade_no' => $trade_no,
            'response_code'    => (string) ( $payload['result']   ?? '' ),
            'response_message' => (string) ( $payload['code_msg'] ?? '' ),
        ];

        // 街口 result_url callback 的金額在 transaction.final_price（訂單實際消費金額，
        // = redeem_amount + debit_amount，與送單時的 final_price / order->total 同為整數元 TWD）。
        $paid_amount = self::extract_paid_amount( $payload );
        if ( null !== $paid_amount ) {
            $legacy['paid_amount'] = $paid_amount;
        }

        return YSPaymentDetailDTO::from_legacy_array( $legacy, 'ys_ec_jkopay' );
    }

    /**
     * 從街口 callback payload 取出「訂單實際消費金額」(final_price)。
     *
     * 街口 result_url callback 結構為 `{ "transaction": { ..., "final_price": "1000" } }`，
     * final_price 為字串型態、單位為元（TWD 整數，與 order->total 同單位，無需換算）。
     *
     * 防禦性：街口 spec 雖標 final_price 為必要欄位，但若實際 payload 缺欄位或非數值，
     * 回傳 null（而非 0）——讓 caller 不要把 paid_amount 設為 0，以免觸發核心守衛的
     * fail-open（paid_amount <= 0 → return true）反而讓金額核對失效。
     *
     * @param array $payload handler 收到的 payload（callback controller 已塞入完整 transaction）
     * @return float|null 取得到的金額（> 0）；取不到時為 null
     */
    private static function extract_paid_amount( array $payload ): ?float {
        $transaction = is_array( $payload['transaction'] ?? null ) ? $payload['transaction'] : [];

        // 主要欄位 final_price；保留 total_price 作為極少數舊 payload 的後備。
        $raw = $transaction['final_price']
            ?? $transaction['total_price']
            ?? $payload['final_price']
            ?? $payload['total_price']
            ?? null;

        if ( null === $raw || ! is_numeric( $raw ) ) {
            return null;
        }

        $amount = (float) $raw;
        return $amount > 0 ? $amount : null;
    }
}
