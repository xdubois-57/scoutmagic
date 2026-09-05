<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Class loading for this directory's two CLI entry points (`build.php`,
 * `generate.php`), which is not the same problem as class loading for the
 * PHPUnit suite.
 *
 * Composer's autoloader carries `Core\` and `Modules\` — the application —
 * and it is required here for exactly that. What it does NOT carry outside
 * a development checkout is `Tests\Fixtures\ReferenceDataset\`: that
 * mapping lives in composer.json's `autoload-dev`, and every real
 * installation runs a `vendor/` dumped by
 * `composer install --no-dev --optimize-autoloader`
 * (`scripts/build-artifact.sh`), where `autoload-dev` is stripped and the
 * optimised class map never listed these classes in the first place.
 *
 * That is not an exotic setup — it is the documented way to run the
 * builder (README.md §8: « sans `--root`, le builder cible l'installation
 * dans laquelle il se trouve »), and on a real installation it failed on
 * the first line naming one of these classes, with
 * « Class "Tests\Fixtures\ReferenceDataset\InstanceContext" not found ».
 * The directory travels as a unit — the artifact ships none of it, so
 * somebody copied it there — and it should carry its own class loading
 * with it rather than depend on how the target's `vendor/` was dumped.
 *
 * One namespace, one directory, and `require_once` rather than `require`
 * so this is harmless when Composer's own mapping got there first (a
 * development checkout, or the PHPUnit suite, which never loads this file
 * at all).
 */
require_once __DIR__ . '/../../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\Fixtures\\ReferenceDataset\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
