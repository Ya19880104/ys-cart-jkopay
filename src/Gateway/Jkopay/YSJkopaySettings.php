<?php
/**
 * 街口支付後台設定
 *
 * 街口支付的設定值都存在 YS CART 自家的 ys_ec_settings 表，
 * 透過 YSEcommerce::get_setting/update_setting 存取（與其他金流一致）。
 *
 * 設定 key 命名（皆以 ys_ec_jkopay_ 為前綴）：
 *   - enabled       是否啟用     ('0'/'1')
 *   - test_mode     UAT 測試模式  ('0'/'1')，預設 '1'
 *   - store_id      街口商店 ID
 *   - api_key       商家 API Key
 *   - secret_key    商家 Secret Key（用於 HMAC）
 *   - qr_valid_time QR Code 有效時間（秒），預設 600
 *   - payment_type  付款型態：onetime（一次付款） / regular（定期扣款）
 *
 * 此類別負責：
 *   1. register()  — 註冊到 admin_init（提供給 YSEcommerce::init_admin 呼叫；目前先提供 API）
 *   2. handle_save() — 後台 POST 表單儲存
 *   3. render_page() — 顯示後台 UI（讀 jkopay-settings.php template）
 *
 * @package YangSheep\YSCartJkopay\Gateway\Jkopay
 * @since   2.38.0
 */

namespace YangSheep\YSCartJkopay\Gateway\Jkopay;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Admin\YSAdminApp;
use YangSheep\Ecommerce\Utils\YSCrypto;
use YangSheep\Ecommerce\YSEcommerce;
use YangSheep\YSCartJkopay\Plugin;

class YSJkopaySettings {

    /** 後台儲存表單 nonce action */
    private const NONCE_ACTION = 'ys_ec_jkopay_save_settings';

    /** 設定 key 完整對照表（用於 register/save 與測試 source-grep） */
    public const SETTING_KEYS = [
        'enabled'         => 'ys_ec_jkopay_enabled',
        'test_mode'       => 'ys_ec_jkopay_test_mode',
        'store_id'        => 'ys_ec_jkopay_store_id',
        'api_key'         => 'ys_ec_jkopay_api_key',    // example: wp_options key mapping, not a credential value
        'secret_key'      => 'ys_ec_jkopay_secret_key', // example: wp_options key mapping, not a credential value
        'qr_valid_time'   => 'ys_ec_jkopay_qr_valid_time',
        'payment_type'    => 'ys_ec_jkopay_payment_type',
    ];

    /**
     * 註冊 hook（給 YSEcommerce::init_admin / Whitelabel registry 呼叫）
     *
     * 為避免變動 init_admin（屬於 BATCH B 的範圍），此處只註冊 admin_post handler，
     * 不主動 add_menu_page。後台設定頁路由由 BATCH B 的 settings admin 統一提供。
     *
     * v2.38.1 (BATCH G8)：額外註冊 admin_enqueue_scripts，把 password 顯示/隱藏 toggle
     * 從原先 inline onclick 搬到外部 ys-ec-password-toggle.js。
     */
    public static function register(): void {
        add_action( 'admin_post_ys_ec_jkopay_save_settings', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    /**
     * 註冊街口設定頁專用 admin assets
     *
     * 只在 page=ys-ecommerce-jkopay 載入 ys-ec-password-toggle.js（vanilla JS、無 jQuery 依賴）。
     * v2.38.0 settings template 原先用 inline onclick 處理 password 顯示/隱藏；
     * v2.38.1 (BATCH G8) 改用 .ys-ec-password-toggle class + data-target 屬性 + 外部 JS。
     *
     * @param string $hook 目前 admin page hook（add_menu_page / add_submenu_page 回傳值）
     */
    public static function enqueue_assets( $hook ): void {
        $hook = (string) $hook;

        // 雙重 guard：hook suffix 通常是 toplevel_page_ys-ecommerce-jkopay 或
        //            ys-cart_page_ys-ecommerce-jkopay，視註冊位置而定；
        //            另以 $_GET['page'] 補強，因為 BATCH G4 的 admin menu 可能尚未 wire 進來。
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : '';
        $is_jkopay_page = false !== strpos( $hook, 'ys-ecommerce-jkopay' )
            || false !== strpos( $hook, 'ys-provider-jkopay' )
            || in_array( $page, [ 'ys-ecommerce-jkopay', 'ys-provider-jkopay' ], true );

        if ( ! $is_jkopay_page ) {
            return;
        }

        wp_enqueue_script(
            'ys-ec-password-toggle',
            YS_ECOMMERCE_URL . 'assets/js/admin/ys-ec-password-toggle.js',
            [],
            YS_ECOMMERCE_VERSION,
            true
        );

        // v2.39.5：測試連線按鈕的 vanilla JS handler（無 jQuery 依賴）
        wp_enqueue_script(
            'ys-ec-jkopay-test-connection',
            YS_CART_JKOPAY_URL . 'assets/js/admin/jkopay-test-connection.js',
            [],
            YS_CART_JKOPAY_VERSION,
            true
        );

        // 注入端點 + REST nonce 給前端 fetch() 使用
        // （即使站點安裝在子目錄 /wp/，rest_url 回傳的也是完整路徑）
        wp_localize_script(
            'ys-ec-jkopay-test-connection',
            'ysJkopayTestConnection',
            [
                'endpoint' => esc_url_raw( rest_url( 'ys-ecommerce-headless/v1/admin/jkopay/test-connection' ) ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
            ]
        );
    }

    /**
     * 取得目前所有設定值（內部用；含加密後的 secret blob，**勿直接給 template**）
     *
     * @return array
     */
    public static function get_all(): array {
        $ec  = YSEcommerce::get_instance();
        $out = [];
        foreach ( self::SETTING_KEYS as $alias => $option_key ) {
            $out[ $alias ] = $ec->get_setting( $option_key, self::default_for( $alias ) );
        }
        return $out;
    }

    /**
     * 取得設定值（給 template 顯示用）— 安全版本
     *
     * v2.41.0 P1-A：原本 render_page() 會把 api_key/secret_key 解密後直接帶進
     * template 的 input value 屬性，等於把金鑰直接寫進 HTML DOM，會被瀏覽器外掛、
     * password manager、screenshot 工具、或任何能讀 DOM 的腳本擷取。
     *
     * 改為：
     *   - api_key / secret_key 強制清空，**永不**輸出到 template
     *   - 額外提供 api_key_is_set / secret_key_is_set 布林旗標
     *   - Template 用旗標決定是否顯示「已設定」提示，但不顯示金鑰值
     *
     * 對應 PayUni 等其他金流的 masked-secret 模式。
     *
     * @return array {
     *     enabled, test_mode, store_id, qr_valid_time, payment_type: string,
     *     api_key, secret_key: '' (一律空字串，禁止洩漏到 DOM),
     *     api_key_is_set, secret_key_is_set: bool (給 template 判斷顯示「已設定」用)
     * }
     */
    public static function get_settings_for_render(): array {
        $raw = self::get_all();

        // 判斷有沒有設過金鑰（DB 內非空字串即視為已設）
        $api_key_is_set    = '' !== (string) ( $raw['api_key']    ?? '' );
        $secret_key_is_set = '' !== (string) ( $raw['secret_key'] ?? '' );

        $out = $raw;
        $out['enabled'] = self::is_provider_enabled() ? '1' : '0';
        // **永不**把（即便已解密的）金鑰塞進 template 變數
        $out['api_key']           = '';
        $out['secret_key']        = '';
        $out['api_key_is_set']    = $api_key_is_set;
        $out['secret_key_is_set'] = $secret_key_is_set;

        return $out;
    }

    /**
     * 取得單項設定預設值
     */
    public static function default_for( string $alias ): string {
        switch ( $alias ) {
            case 'enabled':       return '0';
            case 'test_mode':     return '1';
            case 'qr_valid_time': return '600';
            // v2.38.1 G1：街口真實 spec 是 onetime / regular（替代 v2.38.0 錯誤的 onepage / app）
            case 'payment_type':  return 'onetime';
            default:              return '';
        }
    }

    /**
     * 處理後台 POST 儲存
     *
     * 流程：
     *   - cap check (manage_options)
     *   - nonce 驗證
     *   - 逐項 sanitize 後寫入 ys_ec_settings
     *   - 重新導向回設定頁
     */
    public static function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '權限不足。', 'ys-cart' ), 403 );
        }

        check_admin_referer( self::NONCE_ACTION );

        $ec = YSEcommerce::get_instance();

        // enabled / test_mode（checkbox：勾起來才送）
        $ec->update_setting(
            self::SETTING_KEYS['enabled'],
            isset( $_POST['ys_ec_jkopay_enabled'] ) ? '1' : '0'
        );
        self::sync_provider_lifecycle( isset( $_POST['ys_ec_jkopay_enabled'] ) );
        $ec->update_setting(
            self::SETTING_KEYS['test_mode'],
            isset( $_POST['ys_ec_jkopay_test_mode'] ) ? '1' : '0'
        );

        // store_id（v2.38.1 G1：街口 spec 是 36-char UUID，**不可截斷**）
        $ec->update_setting(
            self::SETTING_KEYS['store_id'],
            mb_substr( sanitize_text_field( wp_unslash( (string) ( $_POST['ys_ec_jkopay_store_id'] ?? '' ) ) ), 0, 36 )
        );

        // api_key / secret_key — sanitize 後以 YSCrypto::encrypt_for_storage 加密入庫
        //
        // v2.38.0 hotfix：Client 端讀取已用 YSCrypto::decrypt_from_storage，這裡補齊加密側，
        // 避免設定 DB（option）洩漏即等於洩漏金鑰；與 PayUni / Shopline 設定一致。
        //
        // v2.41.0 P1-A：改為「留空即保留現有值」(skip-on-empty)。
        //   - 新的 template 不會把已存的金鑰回顯到 input value（DOM 不再洩漏）
        //   - 因此使用者進到設定頁時 input 會是空的，若沒輸入新值就送出，
        //     原本的「空字串覆蓋」會誤把金鑰清掉
        //   - 對應的「清除金鑰」應由獨立按鈕或 WP-CLI / DB 操作；
        //     UI 的 blank 永遠視為「不變」，避免誤觸清空
        $api_key_raw = sanitize_text_field( wp_unslash( (string) ( $_POST['ys_ec_jkopay_api_key'] ?? '' ) ) );
        if ( '' !== $api_key_raw ) {
            $ec->update_setting(
                self::SETTING_KEYS['api_key'],
                YSCrypto::encrypt_for_storage( $api_key_raw )
            );
        }
        $secret_key_raw = sanitize_text_field( wp_unslash( (string) ( $_POST['ys_ec_jkopay_secret_key'] ?? '' ) ) );
        if ( '' !== $secret_key_raw ) {
            $ec->update_setting(
                self::SETTING_KEYS['secret_key'],
                YSCrypto::encrypt_for_storage( $secret_key_raw )
            );
        }

        // qr_valid_time（60 ~ 1800 秒，避免無意義值；超出範圍歸 600）
        $valid_time = absint( wp_unslash( (string) ( $_POST['ys_ec_jkopay_qr_valid_time'] ?? '600' ) ) );
        if ( $valid_time < 60 || $valid_time > 1800 ) {
            $valid_time = 600;
        }
        $ec->update_setting( self::SETTING_KEYS['qr_valid_time'], (string) $valid_time );

        // payment_type（v2.38.1 G1：街口真實 spec 是 onetime / regular）
        // YSJkopayGateway::normalize_payment_type() 會自動把 v2.38.0 的 onepage/app 轉換，
        // 但這邊（save 端）直接寫入新值，避免舊值繼續流傳到 DB。
        $payment_type_raw = sanitize_text_field( wp_unslash( (string) ( $_POST['ys_ec_jkopay_payment_type'] ?? 'onetime' ) ) );
        $payment_type     = YSJkopayGateway::normalize_payment_type( $payment_type_raw );
        $ec->update_setting( self::SETTING_KEYS['payment_type'], $payment_type );

        // 完成後 redirect 回設定頁
        $redirect = wp_get_referer() ?: admin_url( 'admin.php?page=ys-provider-jkopay' );
        wp_safe_redirect( add_query_arg( 'ys_ec_jkopay_saved', '1', $redirect ) );
        exit;
    }

    /**
     * 顯示後台設定頁（template 由外部 admin shell 呼叫）
     *
     * 用於 BATCH B 後台尚未整合時的 fallback 顯示。
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '權限不足。', 'ys-cart' ), 403 );
        }

        // v2.41.0 P1-A：改用 get_settings_for_render()，**永不**把解密後的 secret
        // 帶進 template。template 內 input value 會強制空字串，僅以 api_key_is_set /
        // secret_key_is_set 旗標顯示「已設定」標籤；要更新請輸入新值，留空保留。
        $settings = self::get_settings_for_render();

        // 為了讓 template 取用方便，把旗標獨立解出（與既有變數命名風格一致）
        $api_key_is_set    = (bool) ( $settings['api_key_is_set']    ?? false );
        $secret_key_is_set = (bool) ( $settings['secret_key_is_set'] ?? false );

        $callback_url = YSJkopayGateway::get_callback_url();
        $nonce_action = self::NONCE_ACTION;

        YSAdminApp::open( '街口支付（OnlinePay）', '金物流 / 街口支付' );

        // 共用變數注入 template。
        $template = dirname( __DIR__, 3 ) . '/templates/admin/gateways/jkopay-settings.php';
        if ( file_exists( $template ) ) {
            include $template;
        } else {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( '街口支付設定 template 不存在。', 'ys-cart' )
                . '</p></div>';
        }

        YSAdminApp::close();
    }

    /**
     * 取得 nonce action（給 template 用）
     */
    public static function get_nonce_action(): string {
        return self::NONCE_ACTION;
    }

    private static function is_provider_enabled(): bool {
        if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
            return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_provider_enabled( 'ys_jkopay', Plugin::manifest() );
        }

        return '1' === (string) YSEcommerce::get_instance()->get_setting( self::SETTING_KEYS['enabled'], '0' );
    }

    private static function sync_provider_lifecycle( bool $enabled ): void {
        if ( ! class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
            return;
        }

        \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::set_provider_enabled( 'ys_jkopay', $enabled, Plugin::manifest() );
    }
}
