<?php

namespace YangSheep\YSCartJkopay;

use YangSheep\Ecommerce\Gateways\YSGatewayRegistry;
use YangSheep\YSCartJkopay\Api\Admin\YSJkopayTestConnectionController;
use YangSheep\YSCartJkopay\Api\YSJkopayCallbackController;
use YangSheep\YSCartJkopay\Gateway\Jkopay\YSJkopayGateway;
use YangSheep\YSCartJkopay\Gateway\Jkopay\YSJkopaySettings;
use YangSheep\Ecommerce\YSEcommerce;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init(): void {
		YSJkopaySettings::register();

		add_filter( 'ys_ec_provider_manifests', [ $this, 'register_manifest' ], 10, 1 );
		add_action( 'ys_ec_register_gateways', [ $this, 'register_gateway' ] );
		add_action( 'ys_ec_register_admin_rest_routes', [ $this, 'register_admin_routes' ] );
		add_action( 'ys_ec_register_storefront_routes', [ $this, 'register_storefront_routes' ] );
	}

	/**
	 * @param array<int,array<string,mixed>> $manifests
	 * @return array<int,array<string,mixed>>
	 */
	public function register_manifest( array $manifests ): array {
		$manifests[] = self::manifest();

		return $manifests;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function manifest(): array {
		static $manifest = null;

		if ( null === $manifest ) {
			$manifest = require YS_CART_JKOPAY_DIR . 'manifest.php';
		}

		return $manifest;
	}

	public function register_gateway(): void {
		if ( class_exists( YSGatewayRegistry::class ) && $this->is_payment_enabled() ) {
			YSGatewayRegistry::register( new YSJkopayGateway() );
		}
	}

	private function is_payment_enabled(): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_method_enabled( 'payment', YSJkopayGateway::GATEWAY_ID, self::manifest() );
		}

		if ( ! class_exists( YSEcommerce::class ) ) {
			return false;
		}

		return '1' === (string) YSEcommerce::get_instance()->get_setting( 'ys_ec_jkopay_enabled', '0' );
	}

	public function register_admin_routes( $registrar = null ): void {
		unset( $registrar );

		if ( ! $this->is_payment_enabled() ) {
			return;
		}

		YSJkopayTestConnectionController::register_routes();
	}

	public function register_storefront_routes( string $namespace = '' ): void {
		unset( $namespace );

		if ( $this->is_payment_enabled() ) {
			YSJkopayCallbackController::register_routes();
		}
	}
}
