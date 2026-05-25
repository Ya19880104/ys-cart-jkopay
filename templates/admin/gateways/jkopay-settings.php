<?php
/**
 * 街口支付後台設定頁
 *
 * 由 YSJkopaySettings::render_page() 呼叫，期望已注入下列變數：
 *
 * @var array  $settings           當前設定值（以 alias 為 key，例如 enabled / test_mode / store_id ...）
 *                                  注意 v2.41.0 P1-A 起 api_key / secret_key 一律為 ''（空字串），
 *                                  以 api_key_is_set / secret_key_is_set 旗標判斷是否已設定。
 * @var string $callback_url       Webhook callback 完整 URL（複製到街口商家後台用）
 * @var string $nonce_action       表單 nonce action 名稱
 * @var bool   $api_key_is_set     api_key 是否已存於資料庫（僅判斷有無，不洩漏值）
 * @var bool   $secret_key_is_set  secret_key 是否已存於資料庫
 *
 * @package YangSheep\Ecommerce\Gateways\Jkopay
 * @since   2.38.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
    return;
}

// 安全：必要變數 fallback
$settings     = isset( $settings )     && is_array( $settings ) ? $settings : [];
$callback_url = isset( $callback_url ) && is_string( $callback_url ) ? $callback_url : '';
$nonce_action = isset( $nonce_action ) && is_string( $nonce_action ) ? $nonce_action : 'ys_ec_jkopay_save_settings';

$enabled       = (string) ( $settings['enabled']       ?? '0' );
$test_mode     = (string) ( $settings['test_mode']     ?? '1' );
$store_id      = (string) ( $settings['store_id']      ?? '' );
$qr_valid_time = (string) ( $settings['qr_valid_time'] ?? '600' );
$payment_type  = (string) ( $settings['payment_type']  ?? 'onetime' );

// v2.41.0 P1-A：api_key / secret_key 永不從 $settings 取「值」回顯到 input，
// 改以 _is_set 旗標判斷是否要顯示「已設定」標記，並把 input value 強制留空。
$api_key_is_set    = isset( $api_key_is_set )    ? (bool) $api_key_is_set    : (bool) ( $settings['api_key_is_set']    ?? false );
$secret_key_is_set = isset( $secret_key_is_set ) ? (bool) $secret_key_is_set : (bool) ( $settings['secret_key_is_set'] ?? false );

$saved = isset( $_GET['ys_ec_jkopay_saved'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_GET['ys_ec_jkopay_saved'] ) );
?>
<div class="ysca-jkopay-settings">

    <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( '街口支付設定已儲存。', 'ys-cart' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( '1' === $test_mode && '1' === $enabled ) : ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( '目前為測試（UAT）模式：', 'ys-cart' ); ?></strong>
                <?php esc_html_e( '所有交易都送往 uat-api.jkopay.com，不會產生真實扣款。正式上線前請取消勾選「測試模式」。', 'ys-cart' ); ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ysca-surface ysca-card ysca-card--soft">
        <?php wp_nonce_field( $nonce_action ); ?>
        <input type="hidden" name="action" value="ys_ec_jkopay_save_settings" />

        <div class="ysca-form-grid">

            <div class="ysca-field">
                    <label class="ysca-field__label" for="ys_ec_jkopay_enabled"><?php esc_html_e( '啟用街口支付', 'ys-cart' ); ?></label>
                    <label class="ysca-checkbox-line">
                        <input type="checkbox"
                            id="ys_ec_jkopay_enabled"
                            name="ys_ec_jkopay_enabled"
                            value="1"
                            <?php checked( '1', $enabled ); ?> />
                        <?php esc_html_e( '在結帳頁顯示街口支付選項', 'ys-cart' ); ?>
                    </label>
            </div>

            <div class="ysca-field">
                    <label class="ysca-field__label" for="ys_ec_jkopay_test_mode"><?php esc_html_e( '測試模式（UAT）', 'ys-cart' ); ?></label>
                    <label class="ysca-checkbox-line">
                        <input type="checkbox"
                            id="ys_ec_jkopay_test_mode"
                            name="ys_ec_jkopay_test_mode"
                            value="1"
                            <?php checked( '1', $test_mode ); ?> />
                        <?php esc_html_e( '送單到 uat-api.jkopay.com（測試環境）', 'ys-cart' ); ?>
                    </label>
                    <p class="ysca-field__hint">
                        <?php esc_html_e( '上線前請務必取消勾選，並使用正式環境的 store_id / api_key / secret_key。', 'ys-cart' ); ?>
                    </p>
            </div>

            <div class="ysca-field">
                    <label class="ysca-field__label" for="ys_ec_jkopay_store_id"><?php esc_html_e( '商店 ID（store_id）', 'ys-cart' ); ?></label>
                    <input type="text"
                        id="ys_ec_jkopay_store_id"
                        name="ys_ec_jkopay_store_id"
                        value="<?php echo esc_attr( $store_id ); ?>"
                        class="ysca-input"
                        autocomplete="off"
                        maxlength="36"
                        pattern="[0-9a-fA-F\-]{36}" />
                    <p class="ysca-field__hint"><?php esc_html_e( '街口端核發的商家代號（36 字 UUID 格式）。', 'ys-cart' ); ?></p>
            </div>

            <div class="ysca-field">
                    <label class="ysca-field__label" for="ys_ec_jkopay_api_key">
                        <?php esc_html_e( 'API Key', 'ys-cart' ); ?>
                        <?php if ( $api_key_is_set ) : ?>
                            <span class="ysca-badge ysca-badge--success ysca-secret-saved-badge">
                                <?php esc_html_e( '✓ 已設定', 'ys-cart' ); ?>
                            </span>
                        <?php endif; ?>
                    </label>
                    <?php /* v2.41.0 P1-A：value 強制為空，避免把已加密儲存的金鑰解密後寫進 DOM。 */ ?>
                    <input type="password"
                        id="ys_ec_jkopay_api_key"
                        name="ys_ec_jkopay_api_key"
                        value=""
                        class="ysca-input"
                        autocomplete="new-password"
                        spellcheck="false"
                        placeholder="<?php echo $api_key_is_set
                            ? esc_attr__( '已設定，留空保留現有值；輸入新值即更新', 'ys-cart' )
                            : esc_attr__( '請貼上街口商家核發的 API Key', 'ys-cart' ); ?>" />
                    <button type="button"
                        class="ysca-btn ysca-btn--ghost ysca-btn--sm ys-ec-password-toggle"
                        data-target="ys_ec_jkopay_api_key"
                        aria-label="<?php esc_attr_e( '顯示密碼', 'ys-cart' ); ?>">
                        <?php esc_html_e( '顯示', 'ys-cart' ); ?>
                    </button>
                    <p class="ysca-field__hint">
                        <?php if ( $api_key_is_set ) : ?>
                            <strong><?php esc_html_e( '已設定。', 'ys-cart' ); ?></strong>
                            <?php esc_html_e( '為了安全，金鑰不會顯示於此處。如需更新，請輸入新值；留空則維持現有值。', 'ys-cart' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( '會放在每次 API 請求的 api-key 標頭。', 'ys-cart' ); ?>
                        <?php endif; ?>
                    </p>
            </div>

            <div class="ysca-field">
                    <label class="ysca-field__label" for="ys_ec_jkopay_secret_key">
                        <?php esc_html_e( 'Secret Key', 'ys-cart' ); ?>
                        <?php if ( $secret_key_is_set ) : ?>
                            <span class="ysca-badge ysca-badge--success ysca-secret-saved-badge">
                                <?php esc_html_e( '✓ 已設定', 'ys-cart' ); ?>
                            </span>
                        <?php endif; ?>
                    </label>
                    <?php /* v2.41.0 P1-A：value 強制為空，避免把 HMAC secret 解密後寫進 DOM。 */ ?>
                    <input type="password"
                        id="ys_ec_jkopay_secret_key"
                        name="ys_ec_jkopay_secret_key"
                        value=""
                        class="ysca-input"
                        autocomplete="new-password"
                        spellcheck="false"
                        placeholder="<?php echo $secret_key_is_set
                            ? esc_attr__( '已設定，留空保留現有值；輸入新值即更新', 'ys-cart' )
                            : esc_attr__( '請貼上街口商家核發的 Secret Key', 'ys-cart' ); ?>" />
                    <button type="button"
                        class="ysca-btn ysca-btn--ghost ysca-btn--sm ys-ec-password-toggle"
                        data-target="ys_ec_jkopay_secret_key"
                        aria-label="<?php esc_attr_e( '顯示密碼', 'ys-cart' ); ?>">
                        <?php esc_html_e( '顯示', 'ys-cart' ); ?>
                    </button>
                    <p class="ysca-field__hint">
                        <?php if ( $secret_key_is_set ) : ?>
                            <strong><?php esc_html_e( '已設定。', 'ys-cart' ); ?></strong>
                            <?php esc_html_e( '為了安全，金鑰不會顯示於此處。如需更新，請輸入新值；留空則維持現有值。', 'ys-cart' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( '用於計算 HMAC-SHA256 摘要。請妥善保密，切勿分享給第三方。', 'ys-cart' ); ?>
                        <?php endif; ?>
                    </p>
            </div>

            <div class="ysca-field">
                    <label class="ysca-field__label" for="ys_ec_jkopay_payment_type"><?php esc_html_e( '付款型態', 'ys-cart' ); ?></label>
                    <?php
                    // v2.38.1 G1：街口真實 spec 用 onetime / regular。向下相容 v2.38.0 舊值
                    // （onepage→onetime / app→regular），讓既有設定 render 時仍能正確 selected。
                    $payment_type_is_onetime = in_array( $payment_type, [ 'onetime', 'onepage' ], true );
                    $payment_type_is_regular = in_array( $payment_type, [ 'regular', 'app' ], true );
                    ?>
                    <select id="ys_ec_jkopay_payment_type" name="ys_ec_jkopay_payment_type" class="ysca-select">
                        <option value="onetime" <?php selected( $payment_type_is_onetime, true ); ?>>
                            <?php esc_html_e( '一次付款（onetime）', 'ys-cart' ); ?>
                        </option>
                        <option value="regular" <?php selected( $payment_type_is_regular, true ); ?>>
                            <?php esc_html_e( '定期扣款（regular）', 'ys-cart' ); ?>
                        </option>
                    </select>
                    <p class="ysca-field__hint">
                        <?php esc_html_e( '一次付款為標準消費；定期扣款用於訂閱型商品（會在街口端帶出授權確認流程）。', 'ys-cart' ); ?>
                    </p>
            </div>

            <div class="ysca-field">
                    <label class="ysca-field__label" for="ys_ec_jkopay_qr_valid_time"><?php esc_html_e( 'QR Code 有效時間（秒）', 'ys-cart' ); ?></label>
                    <input type="number"
                        id="ys_ec_jkopay_qr_valid_time"
                        name="ys_ec_jkopay_qr_valid_time"
                        value="<?php echo esc_attr( $qr_valid_time ); ?>"
                        min="60"
                        max="1800"
                        step="30"
                        class="ysca-input" />
                    <p class="ysca-field__hint"><?php esc_html_e( '範圍 60 ~ 1800 秒，預設 600 秒（10 分鐘）。', 'ys-cart' ); ?></p>
            </div>

            <div class="ysca-field">
                <span class="ysca-field__label"><?php esc_html_e( 'Webhook 回調網址', 'ys-cart' ); ?></span>
                    <code class="ysca-code-pill ysca-input-full">
                        <?php echo esc_html( $callback_url ); ?>
                    </code>
                    <p class="ysca-field__hint">
                        <?php esc_html_e( '請將此網址設定到街口商家後台的 Result URL；街口會以 POST + HMAC 摘要送出付款結果。', 'ys-cart' ); ?>
                    </p>
            </div>

        </div>

        <p class="ysca-inline-actions ysca-inline-actions--start">
            <button type="submit" class="ysca-btn ysca-btn--primary">
                <?php esc_html_e( '儲存街口支付設定', 'ys-cart' ); ?>
            </button>
        </p>
    </form>

    <?php
    // v2.39.5：測試連線（settings 已存在時才顯示，避免在「完全沒設」狀態下誤導使用者）
    // 即使 api_key / secret_key 已設定但 store_id 未填，仍渲染按鈕，
    // 後端 controller 會回 incomplete_config 並列出缺項。
    if ( '' !== $store_id || $api_key_is_set || $secret_key_is_set ) :
        ?>
        <section class="ysca-surface ysca-card ysca-card--soft ysca-jkopay-test-connection-card">
            <header class="ysca-card__head">
                <h2 class="ysca-card__title">
                <span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                <?php esc_html_e( '測試連線', 'ys-cart' ); ?>
                </h2>
            </header>
            <div>
                <p>
                    <?php esc_html_e( '點擊下方按鈕即時驗證 API Key + Secret Key 設定。', 'ys-cart' ); ?>
                    <?php esc_html_e( '系統會送一筆 dummy probe 到 /platform/inquiry，依街口回應判斷 HMAC 簽章與 API Key 是否正確；不會建立任何真實交易。', 'ys-cart' ); ?>
                </p>
                <p class="ysca-inline-actions ysca-inline-actions--start">
                    <button type="button"
                        class="ysca-btn ysca-btn--ghost"
                        id="ys-ec-jkopay-test-connection">
                        <?php esc_html_e( '測試連線', 'ys-cart' ); ?>
                    </button>
                    <span id="ys-ec-jkopay-test-status" class="ysca-field__hint"></span>
                </p>
                <p class="ysca-field__hint">
                    <?php esc_html_e( '若顯示「✓ 連線正常」表示設定有效；若顯示「✗ 認證失敗」請檢查 API Key / Secret Key 是否完整且未被截斷。', 'ys-cart' ); ?>
                </p>
            </div>
        </section>
    <?php endif; ?>
