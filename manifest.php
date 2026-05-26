<?php
/**
 * JKoPay provider manifest for YS CART.
 *
 * @package YangSheep\YSCartJkopay
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

return [
	'id'                 => 'ys_jkopay',
	'name'               => '街口支付',
	'description'        => '街口支付直連 OnlinePay 收款。',
	'version'            => YS_CART_JKOPAY_VERSION,
	'contract_version'   => 1,
	'plugin_file'        => YS_CART_JKOPAY_BASENAME,
	'icon'               => 'dashicons-money-alt',
	'documentation_url'  => 'https://www.jkopay.com/',
	'legacy_setting_key' => 'ys_ec_jkopay_enabled',
	'domains'            => [ 'payment' ],
	'capabilities'       => [
		'payment' => [
			'methods'              => [
				[
					'id'          => 'ys_ec_jkopay',
					'label'       => '街口支付',
					'class'       => \YangSheep\YSCartJkopay\Gateway\Jkopay\YSJkopayGateway::class,
					'description' => '街口支付 APP 掃碼或網頁付款。',
				],
			],
			'supported_currencies' => [ 'TWD' ],
			'supported_countries'  => [ 'TW' ],
			'test_mode_available'  => true,
		],
	],
	'admin_page'         => [
		'slug'                => 'ys-provider-jkopay',
		'title'               => '街口支付設定',
		'render_callback'     => [ \YangSheep\YSCartJkopay\Gateway\Jkopay\YSJkopaySettings::class, 'render_page' ],
		'capability_required' => 'manage_options',
		'icon'                => 'dashicons-money-alt',
	],
	'callback_routes'    => [
		'payment_notify' => [ 'namespace' => 'ys-ecommerce-headless/v1', 'route' => '/payment/jkopay/callback', 'methods' => [ 'POST' ], 'signature_scheme' => 'jkopay_hmac' ],
	],
	'allowed_hosts'      => [
		'uat-onlinepay.jkopay.app',
		'onlinepay.jkopay.app',
	],
	'health_check'       => [
		'callback'  => null,
		'cache_ttl' => 3600,
	],
];
