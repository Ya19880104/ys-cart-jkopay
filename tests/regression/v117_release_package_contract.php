<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$artifacts = glob($root . '/artifacts/ys-cart-jkopay-*.zip') ?: [];

if (!$artifacts) {
    echo "v117_release_package_contract skipped: no release zip built yet\n";
    exit(0);
}

rsort($artifacts);
$zipPath = $artifacts[0];

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
