<?php
/**
 * JKoPay payment reconciliation contract.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function v116_read(string $relative): string {
	global $root;
	$path = $root . '/' . ltrim($relative, '/\\');
	if (!is_file($path)) {
		fwrite(STDERR, "Missing required file: {$relative}\n");
		exit(1);
	}
	return (string) file_get_contents($path);
}

function v116_check(string $label, bool $ok): void {
	global $pass, $fail;
	if ($ok) {
		echo "[PASS] {$label}\n";
		$pass++;
		return;
	}
	echo "[FAIL] {$label}\n";
	$fail++;
}

$main       = v116_read('ys-cart-jkopay.php');
$plugin     = v116_read('src/Plugin.php');
$client     = v116_read('src/Gateway/Jkopay/YSJkopayClient.php');
$gateway    = v116_read('src/Gateway/Jkopay/YSJkopayGateway.php');
$handler    = v116_read('src/Gateway/Jkopay/YSJkopayWebhookHandler.php');
$reconciler = v116_read('src/Gateway/Jkopay/YSJkopayPaymentReconciler.php');

echo "## JKoPay payment reconciliation contract\n";

v116_check(
	'Provider registers a payment reconciler only through the YS CART hook',
	str_contains($plugin, "add_action( 'ys_ec_register_payment_reconcilers'")
		&& str_contains($plugin, 'public function register_payment_reconciler')
		&& str_contains($plugin, '$registry->register( new YSJkopayPaymentReconciler( $client ) );')
);

v116_check(
	'Reconciler registration is gated by lifecycle method state and configured client',
	str_contains($plugin, 'is_payment_enabled()')
		&& str_contains($plugin, '$client->is_configured()')
		&& str_contains($plugin, "interface_exists( '\\YangSheep\\Ecommerce\\Services\\Payment\\YSPaymentReconcilerInterface' )")
);

v116_check(
	'Gateway stores platform order id and trade number for future reconciliation',
	str_contains($gateway, "YSJkopayWebhookHandler::META_TRADE_NO")
		&& str_contains($gateway, "ys_jkopay_platform_order_id")
		&& str_contains($gateway, "'payment_method'   => self::GATEWAY_ID")
);

v116_check(
	'Client exposes JKoPay platform inquiry API',
	str_contains($client, 'public function inquiry')
		&& str_contains($client, '/platform/inquiry')
		&& str_contains($client, "'platform_order_id' => \$platform_order_id")
);

v116_check(
	'Reconciler maps JKoPay inquiry states to normalized YS CART actions',
	str_contains($reconciler, 'implements YSPaymentReconcilerInterface')
		&& str_contains($handler, 'public const STATUS_MAP')
		&& str_contains($reconciler, 'YSPaymentReconcileResult::paid')
		&& str_contains($reconciler, 'YSPaymentReconcileResult::failed')
		&& str_contains($reconciler, 'YSPaymentReconcileResult::hold')
		&& str_contains($reconciler, 'YSPaymentReconcileResult::handled')
);

v116_check(
	'Reconciler recognizes only JKoPay-owned orders',
	str_contains($reconciler, 'YSJkopayGateway::GATEWAY_ID')
		&& str_contains($reconciler, 'ys_jkopay_platform_order_id')
		&& str_contains($reconciler, 'YSJkopayWebhookHandler::META_TRADE_NO')
);

v116_check(
	'Reconciler does not claim generic trade_no records from other payment providers',
	str_contains($reconciler, "\$detail[ YSJkopayWebhookHandler::META_TRADE_NO ] ?? ''")
		&& ! str_contains($reconciler, "\$detail['trade_no'] ?? ''")
);

preg_match('/Version:\s*([0-9.]+)/', $main, $version_match);
preg_match("/YS_CART_JKOPAY_VERSION', '([0-9.]+)'/", $main, $constant_match);
v116_check(
	'Plugin version is bumped for payment reconciliation',
	version_compare((string) ($version_match[1] ?? ''), '1.1.6', '>=')
		&& version_compare((string) ($constant_match[1] ?? ''), '1.1.6', '>=')
);

echo "\nREGRESSION v116_payment_reconciler_contract PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
