<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Seeds config/app.php for a local development checkout: `composer
 * dev-config`.
 *
 * config/app.php.dist ships the production policy, `https_required =>
 * true` (#751): every cookie Secure, HSTS on every response, and a setup
 * wizard that refuses an http:// base URL. `composer serve` runs over
 * plain http://localhost:8000, so a development checkout needs the
 * explicit exception — written here, by the tool, rather than left as a
 * sentence in CONTRIBUTING.md that somebody forgets to follow.
 *
 * Never overwrites an existing config/app.php: that file is the
 * developer's own, and may hold values they set on purpose.
 *
 * Usage: php scripts/dev-config.php [install root, default: this checkout]
 */

$root = $argv[1] ?? dirname(__DIR__);
$distPath = $root . '/config/app.php.dist';
$configPath = $root . '/config/app.php';

if (is_file($configPath)) {
    fwrite(STDOUT, "config/app.php existe déjà : laissé tel quel.\n");
    exit(0);
}

$dist = file_get_contents($distPath);
if ($dist === false) {
    fwrite(STDERR, "config/app.php.dist introuvable sous {$root}.\n");
    exit(1);
}

$count = 0;
$config = preg_replace(
    "/'https_required'\\s*=>\\s*true\\s*,/",
    "'https_required' => false, // development checkout: served over http://localhost",
    $dist,
    1,
    $count
);
if ($config === null || $count !== 1) {
    fwrite(STDERR, "config/app.php.dist ne déclare plus 'https_required' => true : script à mettre à jour.\n");
    exit(1);
}

file_put_contents($configPath, $config);
fwrite(STDOUT, "config/app.php créé pour le développement local (HTTP toléré).\n");
