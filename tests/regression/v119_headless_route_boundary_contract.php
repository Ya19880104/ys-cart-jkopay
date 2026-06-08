<?php
/**
 * JKoPay public docs and SDK must keep callback/admin routes out of storefront UI.
 */

declare(strict_types=1);

$root   = dirname(__DIR__, 2);
$sdk    = (string) file_get_contents($root . '/sdk/ys-cart-jkopay-headless.js');
$docs   = (string) file_get_contents($root . '/docs/headless.md');
$skill  = (string) file_get_contents($root . '/skills/ys-cart-jkopay-headless.md');
$readme = (string) file_get_contents($root . '/README.md');

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

$check(
    'SDK route constants document route ownership',
    str_contains($sdk, 'Do not call from customer storefront UI')
        && str_contains($sdk, 'Do not bundle into customer storefront UI')
);

$check(
    'Docs keep callback and admin test routes out of storefront UI',
    str_contains($docs, 'Do not call it from browser UI')
        && str_contains($docs, 'not be bundled into public customer UI')
);

$check(
    'Skill keeps callback/admin routes out of customer UI',
    str_contains($skill, 'Never call the provider callback route from browser UI')
        && str_contains($skill, 'Never call the admin test route from customer storefront UI')
);

$check(
    'README documents provider-scoped reconciler boundary',
    str_contains($readme, 'not a storefront route')
        && str_contains($readme, 'provider-scoped')
);

echo "v119_headless_route_boundary_contract FAIL={$fail}" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
