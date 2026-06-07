<?php
/**
 * v1.1.4 regression: disabled JKoPay must not register as a checkout gateway.
 */

declare( strict_types = 1 );

if ( PHP_SAPI !== 'cli' && ! defined( 'ABSPATH' ) ) {
	exit;
}

$root   = dirname( __DIR__, 2 );
$main   = file_get_contents( $root . '/ys-cart-jkopay.php' ) ?: '';
$plugin = file_get_contents( $root . '/src/Plugin.php' ) ?: '';
$settings = file_get_contents( $root . '/src/Gateway/Jkopay/YSJkopaySettings.php' ) ?: '';

$pass = 0;
$fail = 0;

function v113_check( string $label, bool $ok ): void {
	global $pass, $fail;

	if ( $ok ) {
		echo "[PASS] {$label}\n";
		$pass++;
		return;
	}

	echo "[FAIL] {$label}\n";
	$fail++;
}

preg_match( '/Version:\s*([0-9.]+)/', $main, $version_match );
preg_match( "/YS_CART_JKOPAY_VERSION['\"]\s*,\s*['\"]([0-9.]+)['\"]/", $main, $constant_match );

v113_check(
	'plugin version is v1.1.4 or newer',
	version_compare( (string) ( $version_match[1] ?? '' ), '1.1.4', '>=' )
		&& version_compare( (string) ( $constant_match[1] ?? '' ), '1.1.4', '>=' )
);

v113_check(
	'provider manifest is registered for admin setup access',
	str_contains( $plugin, "ys_ec_provider_manifests" )
		&& str_contains( $plugin, 'register_manifest' )
		&& is_readable( $root . '/manifest.php' )
		&& str_contains( (string) file_get_contents( $root . '/manifest.php' ), "'slug'                => 'ys-provider-jkopay'" )
);

v113_check(
	'checkout gateway registration is guarded by the enabled setting',
	(bool) preg_match( '/function\s+register_gateway\s*\([^)]*\)\s*:\s*void\s*\{[^}]*YSGatewayRegistry::class[^}]*\&\&\s*\$this->is_payment_enabled\(\)[^}]*YSGatewayRegistry::register/s', $plugin )
);

v113_check(
	'enabled guard uses provider lifecycle method state with legacy fallback',
	str_contains( $plugin, 'function is_payment_enabled()' )
		&& str_contains( $plugin, 'YSProviderLifecycleState' )
		&& str_contains( $plugin, "is_method_enabled( 'payment', YSJkopayGateway::GATEWAY_ID" )
		&& str_contains( $plugin, "get_setting( 'ys_ec_jkopay_enabled', '0' )" )
		&& str_contains( $plugin, "return '1' ===" )
);

v113_check(
	'legacy provider/menu hooks are not used',
	! str_contains( $plugin, "ys_ec_providers" )
		&& ! str_contains( $plugin, "ys_ec_admin_payment_menus" )
);

v113_check(
	'settings save mirrors the single JKoPay method into L3 lifecycle state',
	str_contains( $settings, 'YSProviderLifecycleState::get_methods_state( \'payment\' )' )
		&& str_contains( $settings, 'YSJkopayGateway::GATEWAY_ID' )
		&& str_contains( $settings, "\$state[ \$method_id ]['enabled']     = \$enabled;" )
		&& str_contains( $settings, "\$state[ \$method_id ]['provider_id'] = 'ys_jkopay';" )
		&& str_contains( $settings, 'YSProviderLifecycleState::update_methods_state( \'payment\', $state )' )
);

echo "\nREGRESSION v113_gateway_registration_guard PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
