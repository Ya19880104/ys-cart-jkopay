<?php
/**
 * JKoPay inbound callback route must use route-level YS CART inbound guards.
 */

declare(strict_types=1);

$root     = dirname(__DIR__, 2);
$main     = (string) file_get_contents($root . '/ys-cart-jkopay.php');
$callback = (string) file_get_contents($root . '/src/Api/YSJkopayCallbackController.php');
$manifest = (string) file_get_contents($root . '/manifest.php');

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $ok) {
        $fail++;
    }
};

preg_match('/Version:\s*([0-9.]+)/', $main, $version_match);
preg_match("/YS_CART_JKOPAY_VERSION['\"]\s*,\s*['\"]([0-9.]+)['\"]/", $main, $constant_match);

$check('plugin version bumped to 1.1.7 and header/constant match', '1.1.7' === ($version_match[1] ?? '') && '1.1.7' === ($constant_match[1] ?? ''));
$check('callback imports YSInboundPermission', str_contains($callback, 'use YangSheep\\Ecommerce\\Security\\YSInboundPermission;'));
$check('callback exposes callback_permission', str_contains($callback, 'callback_permission'));
$check('runtime callback no longer uses __return_true', ! str_contains($callback, "'permission_callback' => '__return_true'"));
$check('manifest declares callback permission callback', str_contains($manifest, "[ \\YangSheep\\YSCartJkopay\\Api\\YSJkopayCallbackController::class, 'callback_permission' ]"));

echo "v118_inbound_permission_contract FAIL={$fail}" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
