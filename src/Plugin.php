<?php

namespace YangSheep\YSCartJkopay;

use YangSheep\Ecommerce\Gateways\YSGatewayRegistry;
use YangSheep\YSCartJkopay\Api\Admin\YSJkopayTestConnectionController;
use YangSheep\YSCartJkopay\Api\YSJkopayCallbackController;
use YangSheep\YSCartJkopay\Gateway\Jkopay\YSJkopayGateway;
use YangSheep\YSCartJkopay\Gateway\Jkopay\YSJkopaySettings;

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

		add_action( 'ys_ec_register_gateways', [ $this, 'register_gateway' ] );
		add_filter( 'ys_ec_providers', [ $this, 'register_provider' ] );
		add_action( 'ys_ec_admin_payment_menus', [ $this, 'register_admin_menu' ], 10, 2 );
		add_action( 'ys_ec_register_admin_rest_routes', [ $this, 'register_admin_routes' ] );
		add_action( 'ys_ec_register_storefront_routes', [ $this, 'register_storefront_routes' ] );
		add_filter( 'ys_ec_external_admin_pages', [ $this, 'register_external_admin_page' ] );
	}

	public function register_gateway(): void {
		if ( class_exists( YSGatewayRegistry::class ) ) {
			YSGatewayRegistry::register( new YSJkopayGateway() );
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $providers
	 * @return array<string,array<string,mixed>>
	 */
	public function register_provider( array $providers ): array {
		$providers['jkopay'] = [
			'name'        => '街口支付',
			'icon'        => 'dashicons-money-alt',
			'description' => '街口支付直連 OnlinePay 收款。',
			'payment'     => [ '街口支付' ],
			'shipping'    => [],
			'setting_key' => 'ys_ec_jkopay_enabled',
			'admin_url'   => admin_url( 'admin.php?page=ys-ecommerce-jkopay' ),
		];

		return $providers;
	}

	public function register_admin_menu( string $parent_slug, string $capability ): void {
		add_submenu_page(
			$parent_slug,
			'街口支付設定',
			'街口支付',
			$capability,
			'ys-ecommerce-jkopay',
			[ YSJkopaySettings::class, 'render_page' ]
		);
	}

	public function register_admin_routes( $registrar = null ): void {
		unset( $registrar );

		YSJkopayTestConnectionController::register_routes();
	}

	public function register_storefront_routes( string $namespace = '' ): void {
		unset( $namespace );

		YSJkopayCallbackController::register_routes();
	}

	/**
	 * @param array<int,string> $pages
	 * @return array<int,string>
	 */
	public function register_external_admin_page( array $pages ): array {
		$pages[] = 'ys-ecommerce-jkopay';

		return array_values( array_unique( $pages ) );
	}
}
