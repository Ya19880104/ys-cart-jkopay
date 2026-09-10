<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

// 🔴 驗「本版要出貨的那一包」，不是「排序後的第一個」。
//
// 原本是 glob 全部 artifacts 後 `rsort` 取第一個。rsort 是**字串**排序：
// '1.1.9' > '1.1.10'（比的是 '9' 和 '1'），所以版號一進兩位數，這個閘門就會
// 繼續驗那個舊 zip 然後放行——真正要出貨的那一包從來沒被驗到，而且是綠的。
$main = (string) file_get_contents($root . '/ys-cart-jkopay.php');
preg_match('/^[ \t]*\*[ \t]*Version:[ \t]*([^\r\n]+)/m', $main, $header);
$version = trim((string) ($header[1] ?? ''));
if ('' === $version) {
    fwrite(STDERR, "cannot read the plugin version from ys-cart-jkopay.php\n");
    exit(1);
}
$zipPath = $root . '/artifacts/ys-cart-jkopay-' . $version . '.zip';

if (!is_file($zipPath)) {
    echo "v117_release_package_contract skipped: no release zip built for {$version} yet\n";
    exit(0);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive extension is required to inspect {$zipPath}\n");
    exit(1);
}

$zip = new ZipArchive();
if (true !== $zip->open($zipPath)) {
    fwrite(STDERR, "Unable to open release zip: {$zipPath}\n");
    exit(1);
}

$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}
$zip->close();

$mustHave = [
    'ys-cart-jkopay/ys-cart-jkopay.php',
    'ys-cart-jkopay/manifest.php',
    'ys-cart-jkopay/vendor/autoload.php',
    'ys-cart-jkopay/vendor/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php',
    'ys-cart-jkopay/README.md',
    'ys-cart-jkopay/docs/headless.md',
    'ys-cart-jkopay/sdk/ys-cart-jkopay-headless.js',
    'ys-cart-jkopay/skills/ys-cart-jkopay-headless.md',
];

foreach ($mustHave as $entry) {
    if (!in_array($entry, $names, true)) {
        fwrite(STDERR, "Release zip missing required entry: {$entry}\n");
        exit(1);
    }
}

$forbiddenPatterns = [
    '#^ys-cart-jkopay/\\.git/#',
    '#^ys-cart-jkopay/\\.github/#',
    '#^ys-cart-jkopay/artifacts/#',
    '#^ys-cart-jkopay/bin/#',
    '#^ys-cart-jkopay/tests/#',
    '#^ys-cart-jkopay/tmp/#',
    '#^ys-cart-jkopay/node_modules/#',
    '#^ys-cart-jkopay/\\.env(\\..*)?$#',
    '#\\.log$#',
    '#\\.tmp$#',
    '#^ys-cart-jkopay/composer\\.(json|lock)$#',
];

foreach ($names as $entry) {
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $entry)) {
            fwrite(STDERR, "Release zip includes forbidden entry: {$entry}\n");
            exit(1);
        }
    }
}

echo "v117_release_package_contract passed\n";
