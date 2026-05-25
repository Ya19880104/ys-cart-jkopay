<?php
/**
 * 街口支付 OnlinePay API 通訊層
 *
 * 處理所有與街口支付平台 API 的 HTTP 通訊、HMAC 簽章、錯誤處理。
 *
 * 認證模型：
 *   - api-key 標頭：商家 API Key
 *   - digest 標頭：HMAC-SHA256( raw_body, secret_key ) 之 hex 字串
 *   - Content-Type: application/json
 *
 * 端點：
 *   - 正式：https://api.jkopay.com
 *   - UAT： https://uat-api.jkopay.com
 *   - POST /platform/entry    建立交易
 *   - POST /platform/inquiry  查詢狀態
 *   - POST /platform/refund   退款
 *
 * @package YangSheep\YSCartJkopay\Gateway\Jkopay
 * @since   2.38.0
 */

namespace YangSheep\YSCartJkopay\Gateway\Jkopay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Utils\YSCrypto;
use YangSheep\Ecommerce\Utils\YSLogger;
use YangSheep\Ecommerce\YSEcommerce;

class YSJkopayClient {

    /** 正式環境 API 網域 */
    private const API_URL_PROD = 'https://api.jkopay.com';

    /** UAT 環境 API 網域 */
    private const API_URL_UAT = 'https://uat-api.jkopay.com';

    /** API 連線逾時秒數 */
    private const HTTP_TIMEOUT = 30;

    /** @var string 商店 ID（街口端商家代號） */
    private string $store_id;

    /** @var string 商家 API Key（會放到 api-key 標頭） */
    private string $api_key;

    /** @var string 商家 Secret Key（用於 HMAC 簽章） */
    private string $secret_key;

    /** @var bool 是否為測試模式（UAT） */
    private bool $test_mode;

    /**
     * v2.39.5：最近一次 request() 的 raw HTTP 狀態碼
     *
     * 用途：admin 測試連線端點需要 HTTP-level 信息來判斷 auth 是否成功
     * （街口正常會回 200 + result != '000'，但 401/403 才是 auth 真失敗）。
     *
     * 0 = 尚未送過任何請求 / 連線失敗（is_wp_error）
     *
     * @var int
     */
    private int $last_http_status = 0;

    /**
     * 建構子 — 從設定表讀取必要參數
     *
     * 同時支援傳入陣列（測試用）與從 wp_option/ys_ec_settings 讀取。
     *
     * @param array $overrides 可選：覆寫設定（測試用）
     *                         keys: store_id, api_key, secret_key, test_mode
     */
    public function __construct( array $overrides = [] ) {
        $ec               = YSEcommerce::get_instance();

        // v2.38.0 hotfix：簡化 test_mode 規範化
        // 接受 string '1' / '0'（從設定讀回時）以及 bool true / false（測試覆寫時）。
        // 原來 `|| true === ...` 是 dead code（第一段已涵蓋），且無法處理 false override。
        $raw_tm           = $overrides['test_mode'] ?? $ec->get_setting( 'ys_ec_jkopay_test_mode', '1' );
        $this->test_mode  = ( '1' === (string) $raw_tm ) || ( true === $raw_tm );
        $this->store_id   = (string) ( $overrides['store_id']   ?? $ec->get_setting( 'ys_ec_jkopay_store_id',   '' ) );

        $raw_api_key      = (string) ( $overrides['api_key']    ?? $ec->get_setting( 'ys_ec_jkopay_api_key',    '' ) );
        $raw_secret_key   = (string) ( $overrides['secret_key'] ?? $ec->get_setting( 'ys_ec_jkopay_secret_key', '' ) );

        // 與 Shopline 一致：嘗試以 YSCrypto 解密；解密失敗就視為 plaintext fallback
        $this->api_key    = $raw_api_key    !== '' ? ( YSCrypto::decrypt_from_storage( $raw_api_key )    ?: $raw_api_key )    : '';
        $this->secret_key = $raw_secret_key !== '' ? ( YSCrypto::decrypt_from_storage( $raw_secret_key ) ?: $raw_secret_key ) : '';
    }

    /**
     * 是否已完成必要設定（store/api/secret 三項皆有值）
     */
    public function is_configured(): bool {
        return $this->store_id !== '' && $this->api_key !== '' && $this->secret_key !== '';
    }

    /**
     * v2.39.5：列出未設定的必要欄位（給 admin UI「設定不完整」提示用）
     *
     * @return array<int,string> 例：['store_id', 'secret_key']
     */
    public function get_missing_settings(): array {
        $missing = [];
        if ( '' === $this->store_id )   { $missing[] = 'store_id'; }
        if ( '' === $this->api_key )    { $missing[] = 'api_key'; }
        if ( '' === $this->secret_key ) { $missing[] = 'secret_key'; }
        return $missing;
    }

    /**
     * 是否為測試（UAT）模式
     */
    public function is_test_mode(): bool {
        return $this->test_mode;
    }

    /**
     * v2.39.5：取得最近一次 request() 的 raw HTTP 狀態碼
     *
     * 0 = 尚未送過 / 連線失敗（is_wp_error，例如網路斷線、DNS 失敗）
     */
    public function get_last_http_status(): int {
        return $this->last_http_status;
    }

    /**
     * 取得當前環境基礎 URL
     */
    public function get_base_url(): string {
        return $this->test_mode ? self::API_URL_UAT : self::API_URL_PROD;
    }

    // =========================================================================
    // 簽章
    // =========================================================================

    /**
     * 計算 HMAC-SHA256 摘要（hex 字串）
     *
     * 用於 outbound request 的 digest 標頭，及 inbound webhook 的驗證。
     *
     * @param string $body 原始 request body（送出去的內容；驗 webhook 時為 raw POST body）
     * @return string lowercase hex 字串
     */
    public function sign_payload( string $body ): string {
        return hash_hmac( 'sha256', $body, $this->secret_key );
    }

    /**
     * 驗證 inbound webhook 的簽章
     *
     * 使用 hash_equals() 做 timing-safe 比較，避免被計時攻擊推算出正確值。
     *
     * @param string $body            街口 POST 過來的原始 body
     * @param string $expected_digest Header `digest` 的內容
     * @return bool true=驗證通過
     */
    public function verify_signature( string $body, string $expected_digest ): bool {
        if ( '' === $this->secret_key || '' === $expected_digest ) {
            return false;
        }
        $calculated = $this->sign_payload( $body );
        return hash_equals( $calculated, $expected_digest );
    }

    // =========================================================================
    // 公開 API：交易建立 / 查詢 / 退款
    // =========================================================================

    /**
     * 建立交易（取得付款網址 / QRCode）
     *
     * @param array $payload  完整 entry payload，至少要含 platform_order_id / total_price / final_price
     * @return array { success: bool, data: array|null, message: string }
     */
    public function create_entry( array $payload ): array {
        // 確保 store_id 一定帶上（避免 caller 忘記）
        $payload['store_id'] = $this->store_id;
        return $this->request( '/platform/entry', $payload );
    }

    /**
     * 查詢交易狀態
     *
     * @param string $platform_order_id 商家訂單編號
     * @return array { success: bool, data: array|null, message: string }
     */
    public function inquiry( string $platform_order_id ): array {
        return $this->request( '/platform/inquiry', [
            'store_id'          => $this->store_id,
            'platform_order_id' => $platform_order_id,
        ] );
    }

    /**
     * 退款
     *
     * v2.38.1 G3：街口真實 spec 欄位名為 `refund_order_id`（不是 `refund_id`）。
     * 每一筆部分退款都需要不同的 refund_order_id；街口端會用此 key 做 idempotent 去重。
     *
     * @param string $platform_order_id 商家原始訂單編號
     * @param string $refund_order_id   商家退款序號（idempotent key，街口端會去重）
     * @param int    $refund_amount     退款金額（整數元；街口為 TWD 整數）
     * @return array { success: bool, data: array|null, message: string }
     */
    public function refund( string $platform_order_id, string $refund_order_id, int $refund_amount ): array {
        return $this->request( '/platform/refund', [
            'store_id'          => $this->store_id,
            'platform_order_id' => $platform_order_id,
            'refund_order_id'   => $refund_order_id,
            'refund_amount'     => $refund_amount,
            'currency'          => 'TWD',
        ] );
    }

    // =========================================================================
    // 內部 HTTP 共用
    // =========================================================================

    /**
     * 發送 POST 請求到街口 API
     *
     * 自動處理：
     *   - 設定 api-key + digest 標頭（HMAC 自動簽）
     *   - SSL verify on
     *   - 30 秒 timeout
     *   - 統一錯誤格式
     *
     * @param string $endpoint  '/platform/entry' 等
     * @param array  $payload   要送出的 JSON payload
     * @return array { success, data, message }
     */
    private function request( string $endpoint, array $payload ): array {
        if ( ! $this->is_configured() ) {
            YSLogger::error( 'jkopay', 'API 設定不完整，拒絕送出', [
                'endpoint' => $endpoint,
            ] );
            return [
                'success' => false,
                'data'    => null,
                'message' => '街口支付設定不完整，請至後台設定 store_id / api_key / secret_key。',
            ];
        }

        $url  = $this->get_base_url() . $endpoint;
        $body = wp_json_encode( $payload );
        if ( false === $body ) {
            YSLogger::error( 'jkopay', 'wp_json_encode 失敗', [
                'endpoint'   => $endpoint,
                'last_error' => json_last_error_msg(),
            ] );
            return [
                'success' => false,
                'data'    => null,
                'message' => '請求參數編碼失敗。',
            ];
        }

        $digest = $this->sign_payload( $body );

        // 不要 log 任何 secret / digest / api_key 完整內容（避免 logs 表洩漏）
        YSLogger::info( 'jkopay', "API 請求: {$endpoint}", [
            'test_mode'          => $this->test_mode,
            'platform_order_id'  => (string) ( $payload['platform_order_id'] ?? '' ),
            'amount'             => (int) ( $payload['final_price'] ?? $payload['refund_amount'] ?? 0 ),
        ] );

        $response = wp_remote_post( $url, [
            'method'      => 'POST',
            'timeout'     => self::HTTP_TIMEOUT,
            'sslverify'   => true,
            'redirection' => 0,
            'headers'     => [
                'Content-Type' => 'application/json',
                'api-key'      => $this->api_key,
                'digest'       => $digest,
            ],
            'body'        => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            // v2.39.5：連線失敗時 last_http_status 歸 0（給 admin probe 區分「網路錯誤 vs auth 錯誤」用）
            $this->last_http_status = 0;
            YSLogger::error( 'jkopay', 'API HTTP 錯誤', [
                'endpoint' => $endpoint,
                'error'    => $response->get_error_message(),
            ] );
            return [
                'success' => false,
                'data'    => null,
                'message' => '連線失敗：' . $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        // v2.39.5：保留 raw HTTP 狀態（admin probe 用）
        $this->last_http_status = (int) $code;
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        YSLogger::info( 'jkopay', "API 回應: {$endpoint}", [
            'http_code' => $code,
            'result'    => $data['result']    ?? '',
            'code_msg'  => $data['code_msg']  ?? '',
        ] );

        if ( $code < 200 || $code >= 300 ) {
            return [
                'success' => false,
                'data'    => is_array( $data ) ? $data : null,
                'message' => is_array( $data )
                    ? ( $data['code_msg'] ?? "API 錯誤（HTTP {$code}）" )
                    : "API 錯誤（HTTP {$code}）",
            ];
        }

        // 街口協定：result === '000' 視為成功，其餘為失敗
        $result_code = (string) ( $data['result'] ?? '' );
        if ( '000' !== $result_code ) {
            return [
                'success' => false,
                'data'    => is_array( $data ) ? $data : null,
                'message' => (string) ( $data['code_msg'] ?? "街口錯誤碼 {$result_code}" ),
            ];
        }

        return [
            'success' => true,
            'data'    => is_array( $data ) ? $data : [],
            'message' => '',
        ];
    }
}
