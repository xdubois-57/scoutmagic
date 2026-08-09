<?php

declare(strict_types=1);

/**
 * Regenerates every shipped default under public/assets/img/pwa/ — the
 * fallback served whenever no superadmin has uploaded a custom unit logo
 * (Core\Photo\UnitLogoService::resolveIconContent()/
 * resolveFaviconIcoContent()). Not web-reachable, run manually:
 *
 *   php scripts/generate-pwa-defaults.php
 *
 * Source: the existing icon-512.png default, the highest-resolution asset
 * already in the tree — re-running it through Core\Photo\
 * UnitLogoProcessor (the exact same pipeline a superadmin's upload goes
 * through) keeps every default visually identical to what it replaces
 * (512 is already square, so its own crop/resize is a no-op) while fixing
 * icon-180's real pre-existing bug: the shipped default was itself an
 * alpha-preserving PNG, meaning even a unit that never uploaded a custom
 * logo got a solid black square for its apple-touch-icon on iOS — this
 * regenerates it opaque, flattened onto the background color below, same
 * as every future re-derivation.
 *
 * The background color used here is intentionally the setting's own
 * documented default (#ffffff, see public/index.php's pwa_background_color
 * registration) — this script produces build-time static assets with no
 * live SettingService to read from, not a runtime derivation; a unit that
 * changes the color later re-derives its own uploaded logo automatically
 * (Core\Http\Controller\SettingsController::update()) but never touches
 * these shipped defaults, which stay whatever they looked like at build
 * time — same as before this feature.
 */

require __DIR__ . '/../vendor/autoload.php';

use Core\Photo\UnitLogoProcessor;

const DEFAULT_BACKGROUND_COLOR = '#ffffff';

$defaultsDir = __DIR__ . '/../public/assets/img/pwa';
$sourcePath = $defaultsDir . '/icon-512.png';

if (!is_file($sourcePath)) {
    fwrite(STDERR, "Source not found: {$sourcePath}\n");
    exit(1);
}

$contents = file_get_contents($sourcePath);
if ($contents === false) {
    fwrite(STDERR, "Could not read: {$sourcePath}\n");
    exit(1);
}

$processor = new UnitLogoProcessor();
$icons = $processor->process($contents, 'image/png', DEFAULT_BACKGROUND_COLOR);

$filenames = [
    '16' => 'favicon-16.png',
    '32' => 'favicon-32.png',
    '48' => 'favicon-48.png',
    '64' => 'logo-64.png',
    '180' => 'icon-180.png',
    '192' => 'icon-192.png',
    '512' => 'icon-512.png',
    '512-maskable' => 'icon-512-maskable.png',
];

foreach ($filenames as $size => $filename) {
    file_put_contents($defaultsDir . '/' . $filename, $icons[$size]);
    echo "Wrote {$filename}\n";
}
file_put_contents($defaultsDir . '/favicon.ico', $icons['ico']);
echo "Wrote favicon.ico\n";
