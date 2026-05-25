<?php
/**
 * 街口支付（JKoPay）後台測試連線 REST 端點 — v2.39.5
 *
 * Endpoint:
 *   POST /wp-json/ys-ecommerce-headless/v1/admin/jkopay/test-connection
 *
 * 用途：
 *   後台「街口支付設定頁」按下「測試連線」按鈕後即時驗證
 *   api_key + secret_key（HMAC）設定是否正確。
 *
 * 驗證原理：
 *   送一筆 dummy probe 到 /platform/inquiry，其中 platform_order_id 是不可能存在的隨機 token。
 *
 *   - 街口若收得到 HMAC 驗章成功 → 回 HTTP 200 + result != '000'（通常是「找不到訂單」） → AUTH PASS
 *   - 街口若 HMAC / api_key 不對 → 回 HTTP 401 / 403 / 對應錯誤碼 → AUTH FAIL
 *   - 連線失敗（DNS / TLS）→ HTTP 0 → 網路錯誤
 *
 * 不會在街口端建立任何真實交易（inquiry 不會建單）。
 *
 * Auth：
 *   - manage_options + X-WP-Nonce（沿用 v2.30.0 YSAdminRestAuth）
 *
 * @package YangSheep\Ecommerce\Api\Admin
 * @since   2.39.5
 */

namespace YangSheep\YSCartJkopay\Api\Admin;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Api\Storefront\YSRouteRegistrar as StorefrontRouteRegistrar;
use YangSheep\YSCartJkopay\Gateway\Jkopay\YSJkopayClient;
use YangSheep\Ecommerce\Security\YSRateLimiter;
use YangSheep\Ecommerce\Utils\YSLogger;

class YSJkopayTestConnectionController {

    /** REST namespace（與其他 admin route 共用） */
    public const NAMESPACE = StorefrontRouteRegistrar::NAMESPACE;

    /**
     * 認證失敗時可能出現的 response code（黑名單）
     *
     * 街口正常會回 result='000' 表 OK，
     * 但 inquiry 找不到訂單時也會回 200 + result != '000'（通常 'EXXXX' 或 'XXX' 字樣）。
     *
     * 真正 auth 失敗時，response 中常見如下 token：
     */
    private const AUTH_FAILED_HINTS = [
        'AUTH_ERROR',
        'INVALID_SIGNATURE',
        'INVALID_API_KEY',
        'UNAUTHORIZED',
        'API_KEY_NOT_FOUND',
        'SIGNATURE_VERIFY_FAILED',
    ];

    /**
     * 註冊 REST routes（給 YSAdminRouteRegistrar::register_all() 呼叫）
     */
    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/admin/jkopay/test-connection', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'test_connection' ],
            'permission_callback' => [ \YangSheep\Ecommerce\Api\Admin\YSAdminRestAuth::class, 'permission_admin' ],
        ] );
    }

    /**
     * 主要處理器
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function test_connection( \WP_REST_Request $request ): \WP_REST_Response {
        unset( $request );

        // v2.39.5 self-review hardening：rate limit (10 calls / 60s per admin)
        // 防 admin 帳號被 cookie/session 劫持後狂打 JKoPay API（每次 inquiry 都要簽 HMAC + 連線）
        $user_id        = (int) get_current_user_id();
        $rate_limit_key = 'jkopay_test_conn_u' . $user_id;
        if ( class_exists( YSRateLimiter::class ) ) {
            $allowed = YSRateLimiter::check( $rate_limit_key, 10, 60 );
            if ( ! $allowed ) {
                return new \WP_REST_Response( [
                    'ok'      => false,
                    'success' => false,
                    'auth_ok' => false,
                    'error'   => 'rate_limit',
                    'message' => '測試連線過於頻繁，請稍後再試（每分鐘最多 10 次）',
                ], 429 );
            }
        }

        $client = new YSJkopayClient();

        // Step 1：設定完整性檢查
        if ( ! $client->is_configured() ) {
            return new \WP_REST_Response( [
                'ok'      => false,
                'success' => false,
                'auth_ok' => false,
                'error'   => 'incomplete_config',
                'missing' => $client->get_missing_settings(),
                'message' => '街口支付設定不完整。',
            ], 400 );
        }

        // Step 2：產生不可能存在的 probe token
        // 用 wp_generate_uuid4() 確保極低碰撞機率；前綴 TEST_PROBE_ 利於街口端 audit log 識別
        $probe_token = 'TEST_PROBE_' . wp_generate_uuid4();

        // Step 3：送 inquiry，量測 round-trip 時間
        $start = microtime( true );

        try {
            $resp = $client->inquiry( $probe_token );
        } catch ( \Throwable $e ) {
            YSLogger::error( 'jkopay_test', 'probe 例外', [
                'probe_token' => $probe_token,
                'message'     => $e->getMessage(),
            ] );
            return new \WP_REST_Response( [
                'ok'      => false,
                'success' => false,
                'auth_ok' => false,
                'error'   => 'exception',
                'message' => '測試連線發生例外：' . $e->getMessage(),
            ], 500 );
        }

        $rt_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        // Step 4：解析 response 判斷 auth 是否成功
        $http_status = $client->get_last_http_status();
        $resp_data   = is_array( $resp['data'] ?? null ) ? $resp['data'] : [];
        $resp_code   = (string) ( $resp_data['result'] ?? $resp_data['code'] ?? '' );
        $resp_msg    = (string) ( $resp_data['code_msg'] ?? $resp['message'] ?? '' );

        // Step 5：判定 auth_ok
        // 規則：
        //   - HTTP 0 → 網路失敗（連線斷線、DNS 失敗）
        //   - HTTP 4xx/5xx → 失敗
        //   - HTTP 200 + result code 含已知 auth 失敗關鍵字 → 失敗
        //   - HTTP 200 其他情況 → PASS（包含「訂單不存在」此類業務錯誤都算 auth 通過）
        $auth_ok = self::is_auth_pass( $http_status, $resp_code, $resp_msg );

        // 失敗時也回 HTTP 200（這是 admin probe 結果，不是 admin endpoint 本身失敗）
        // 讓前端 fetch().then 統一處理 success/auth_ok 欄位
        return new \WP_REST_Response( [
            'ok'               => true,
            'success'          => $auth_ok,
            'auth_ok'          => $auth_ok,
            'rt_ms'            => $rt_ms,
            'test_mode'        => $client->is_test_mode(),
            'http_status'      => $http_status,
            'response_code'    => $resp_code,
            'response_message' => mb_substr( $resp_msg, 0, 200 ),
            'probe_token'      => $probe_token, // 給 audit log 對照用
        ], 200 );
    }

    /**
     * 判定 auth 是否通過
     *
     * @param int    $http_status HTTP 狀態碼（0 表連線失敗）
     * @param string $resp_code   response 中的 result / code 欄位
     * @param string $resp_msg    response 訊息
     * @return bool
     */
    private static function is_auth_pass( int $http_status, string $resp_code, string $resp_msg ): bool {
        // 連線失敗 / 4xx / 5xx → 失敗
        if ( $http_status < 200 || $http_status >= 400 ) {
            return false;
        }

        // response code 直接命中黑名單 → 失敗
        $upper_code = strtoupper( $resp_code );
        if ( in_array( $upper_code, self::AUTH_FAILED_HINTS, true ) ) {
            return false;
        }

        // response message 包含已知 auth 錯誤關鍵字 → 失敗
        $upper_msg = strtoupper( $resp_msg );
        foreach ( self::AUTH_FAILED_HINTS as $hint ) {
            if ( '' !== $hint && false !== strpos( $upper_msg, $hint ) ) {
                return false;
            }
        }

        // HTTP 200 + 沒命中任何 auth fail hint → PASS
        // （即便 result != '000'，多數情況是業務性錯誤如「訂單不存在」，仍代表 auth 通過）
        return true;
    }
}
