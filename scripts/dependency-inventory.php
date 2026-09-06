<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Print every dependency this project ships or relies on, with its version
 * and its licence, as French Markdown.
 *
 *   php scripts/dependency-inventory.php [<repository root>]
 *
 * Written for scripts/release.sh, which appends the output to the release
 * notes. The point is that somebody reading a Release can see exactly what
 * shipped — and under which terms — without cloning the tag and running two
 * package managers.
 *
 * Four surfaces, and all four matter for different reasons:
 *
 *  - PHP, production. These are IN the deployable zip (vendor/). Their
 *    versions are what runs on the host.
 *  - PHP, development. Not deployed, but they are the tools whose verdict
 *    the release rests on. A release that says "PHPUnit passed" is worth
 *    knowing the PHPUnit version for.
 *  - JavaScript, development. Same reasoning: Vitest, Playwright and tsc
 *    are what judged the browser code. Nothing here reaches a browser —
 *    production JavaScript is plain, unbundled and Node-free (AGENTS.md
 *    § CSS / frontend).
 *  - The vendored front-end libraries under public/assets/vendor/. The ones
 *    no package manager installs and everybody forgets: a minified file
 *    committed by hand, with its version in a leading banner. They reach the
 *    browser of every visitor, so leaving them out would leave out the only
 *    third-party code a visitor actually executes.
 *
 * Versions are read from the LOCK files and the banners, never from
 * composer.json or package.json — a constraint like `^3.1` is not a version,
 * and the whole value of this list is that it says what shipped rather than
 * what was allowed.
 *
 * The licence column is not decoration. This project is AGPL-3.0-or-later,
 * the strongest copyleft in common use, and whether a dependency may be
 * combined with it at all depends entirely on that column. The last section
 * says, licence by licence, why each one present may be — and a licence this
 * file has never seen is printed as "à examiner" rather than quietly listed,
 * so adding a dependency under an unfamiliar licence is a decision somebody
 * has to write down here (AGENTS.md § CSS / frontend for the vendored ones).
 *
 * Tested by tests/Core/System/DependencyInventoryTest.php, which is why the
 * work is in functions and the side effects in inventory_main(): the test
 * defines DEPENDENCY_INVENTORY_TEST and includes this file for its functions.
 */

/**
 * The vendored front-end libraries, and how to read each one's version.
 *
 * Hand-maintained, unavoidably: nothing downloads these, so there is no
 * lock file to read them out of. Two things keep the map honest. The
 * regexes are the same ones scripts/release.sh's dependency freshness gate
 * uses, so a banner that changes shape breaks both at once rather than one
 * silently; and inventory_vendored_libraries() lists every directory under
 * public/assets/vendor/ and reports one missing from this map as "à
 * déclarer" instead of omitting it.
 *
 * @var array<string, array{name: string, file: string, pattern: string, licence: string, upstream: string}>
 */
const INVENTORY_VENDORED_LIBRARIES = [
    'bootstrap' => [
        'name' => 'Bootstrap',
        'file' => 'bootstrap/js/bootstrap.bundle.min.js',
        'pattern' => '/Bootstrap v([0-9]+\.[0-9]+\.[0-9]+)/',
        'licence' => 'MIT',
        'upstream' => 'twbs/bootstrap',
    ],
    'bootstrap-icons' => [
        'name' => 'Bootstrap Icons',
        'file' => 'bootstrap-icons/bootstrap-icons.min.css',
        'pattern' => '/Bootstrap Icons v([0-9]+\.[0-9]+\.[0-9]+)/',
        'licence' => 'MIT',
        'upstream' => 'twbs/icons',
    ],
    'chartjs' => [
        'name' => 'Chart.js',
        'file' => 'chartjs/chart.umd.min.js',
        'pattern' => '/Chart\.js v([0-9]+\.[0-9]+\.[0-9]+)/',
        'licence' => 'MIT',
        'upstream' => 'chartjs/Chart.js',
    ],
    'leaflet' => [
        'name' => 'Leaflet',
        'file' => 'leaflet/leaflet.js',
        'pattern' => '/Leaflet ([0-9]+\.[0-9]+\.[0-9]+)/',
        'licence' => 'BSD-2-Clause',
        'upstream' => 'Leaflet/Leaflet',
    ],
    'html5-qrcode' => [
        'name' => 'html5-qrcode',
        'file' => 'html5-qrcode/html5-qrcode.min.js',
        'pattern' => '/html5-qrcode v([0-9]+\.[0-9]+\.[0-9]+)/',
        'licence' => 'Apache-2.0',
        'upstream' => 'mebjas/html5-qrcode',
    ],
];

/**
 * Why each licence may be combined with this project's AGPL-3.0-or-later.
 *
 * Keyed by SPDX identifier exactly as composer.lock and package-lock.json
 * spell it. A licence absent from this map is not assumed compatible: it is
 * printed as "à examiner", which is the whole reason the map is here rather
 * than a single reassuring paragraph. The reasoning is deliberately short
 * and specific — a reader checking it wants the clause, not a lecture.
 *
 * @var array<string, string>
 */
const INVENTORY_LICENCE_COMPATIBILITY = [
    'MIT' => 'Licence permissive : compatible.',
    'ISC' => 'Licence permissive : compatible.',
    'BSD-2-Clause' => 'Licence permissive : compatible.',
    'BSD-3-Clause' => 'Licence permissive : compatible.',
    'Apache-2.0' => 'Compatible dans un seul sens : du code Apache-2.0 peut entrer dans une œuvre AGPL-3.0 (la FSF la déclare compatible avec la GPLv3, et l\'AGPLv3 §13 étend cette compatibilité), pas l\'inverse.',
    'LGPL-2.1' => 'LGPL v2.1 : la bibliothèque reste sous ses propres termes ; sa section 3 permet en outre de la placer sous la GPL (v2 ou ultérieure), d\'où l\'AGPLv3 via la GPLv3 §13.',
    'LGPL-2.1-only' => 'LGPL v2.1 : la bibliothèque reste sous ses propres termes ; sa section 3 permet en outre de la placer sous la GPL (v2 ou ultérieure), d\'où l\'AGPLv3 via la GPLv3 §13.',
    'LGPL-2.1-or-later' => 'LGPL v2.1 ou ultérieure : la bibliothèque reste sous ses propres termes ; la LGPLv3 est la GPLv3 assortie d\'exceptions, elle-même combinable avec l\'AGPLv3 (§13).',
    'LGPL-3.0' => 'LGPL v3 : la GPLv3 assortie d\'exceptions supplémentaires, combinable avec l\'AGPLv3 (GPLv3 §13).',
    'LGPL-3.0-only' => 'LGPL v3 : la GPLv3 assortie d\'exceptions supplémentaires, combinable avec l\'AGPLv3 (GPLv3 §13).',
    'LGPL-3.0-or-later' => 'LGPL v3 ou ultérieure : la GPLv3 assortie d\'exceptions supplémentaires, combinable avec l\'AGPLv3 (GPLv3 §13).',
    'GPL-3.0-or-later' => 'GPL v3 ou ultérieure : la GPLv3 §13 autorise explicitement la combinaison avec une œuvre AGPLv3 ; la partie GPL reste sous GPL, la partie AGPL sous AGPL.',
    'GPL-3.0-only' => 'GPL v3 : la GPLv3 §13 autorise explicitement la combinaison avec une œuvre AGPLv3 ; la partie GPL reste sous GPL, la partie AGPL sous AGPL.',
    'AGPL-3.0-or-later' => 'La licence du projet lui-même.',
    'AGPL-3.0-only' => 'La licence du projet lui-même.',
    'MIT-0' => 'Licence permissive : compatible.',
    'CC0-1.0' => 'Domaine public : compatible.',
    'BlueOak-1.0.0' => 'Licence permissive : compatible.',
    'Unlicense' => 'Domaine public : compatible.',
];

/** What the compatibility table says about a licence this file has never seen. */
const INVENTORY_LICENCE_UNKNOWN = '**À examiner** — licence absente de la table de compatibilité de `scripts/dependency-inventory.php`.';

// Guarded the same way scripts/authz-support.php is: the test suite defines
// DEPENDENCY_INVENTORY_TEST and includes this file for its functions, and
// the command must not run when it does.
if (!defined('DEPENDENCY_INVENTORY_TEST')) {
    inventory_main($argv);
}

/**
 * Every side effect this file has: reads argv, reads the repository, writes
 * to stdout, exits.
 *
 * @param string[] $argv
 */
function inventory_main(array $argv): void
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "dependency-inventory.php is a CLI script.\n");
        exit(1);
    }

    $root = $argv[1] ?? dirname(__DIR__);

    try {
        echo inventory_render($root);
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'dependency-inventory: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

/**
 * The whole document, for one repository root.
 *
 * @throws RuntimeException when a lock file is missing or unreadable — an
 *         inventory that silently skips a surface is worse than none.
 */
function inventory_render(string $root): string
{
    $composerLock = inventory_read_json($root . '/composer.lock');
    $packageJson = inventory_read_json($root . '/package.json');
    $packageLock = inventory_read_json($root . '/package-lock.json');

    $php = inventory_composer_packages($composerLock, 'packages');
    $phpDev = inventory_composer_packages($composerLock, 'packages-dev');
    $node = inventory_npm_packages($packageJson, $packageLock);
    $vendored = inventory_vendored_libraries($root . '/public/assets/vendor');

    $phpRequirement = (string) ($composerLock['platform']['php'] ?? 'voir composer.json');
    $nodeRequirement = (string) ($packageJson['engines']['node'] ?? 'voir package.json');

    $out = "## Dépendances livrées\n\n";
    $out .= "Versions lues dans les fichiers de verrouillage (`composer.lock`, `package-lock.json`) et dans les bannières des bibliothèques vendorisées — ce qui a été livré, pas ce qu'une contrainte autorisait. Généré par `scripts/dependency-inventory.php`.\n\n";
    $out .= '**PHP requis :** `' . $phpRequirement . '` — **Node requis (développement seulement) :** `' . $nodeRequirement . "`\n\n";

    $out .= '### PHP — production (' . count($php) . ")\n\n";
    $out .= "Présentes dans `vendor/` de l'archive installable : c'est ce qui tourne sur l'hébergement.\n\n";
    $out .= inventory_table($php, '_Aucune._');

    $out .= "\n### PHP — développement (" . count($phpDev) . ")\n\n";
    $out .= "Non livrées. Ce sont les outils dont le verdict fonde cette release.\n\n";
    $out .= inventory_table($phpDev, '_Aucune._');

    $out .= "\n### JavaScript — développement (" . count($node) . ")\n\n";
    $out .= "Outillage de test et d'analyse uniquement. Le JavaScript de production est du code navigateur simple, sans bundler ni Node : rien d'ici n'atteint un visiteur.\n\n";
    $out .= inventory_table($node, '_Aucune._');

    $out .= "\n### Bibliothèques front-end vendorisées (" . count($vendored) . ")\n\n";
    $out .= "Dans aucun fichier de verrouillage : des fichiers minifiés commités sous `public/assets/vendor/`, version lue dans leur bannière. Ce sont les seules dépendances tierces que le navigateur d'un visiteur exécute réellement.\n\n";
    $out .= inventory_table($vendored, '_Aucune trouvée — vérifier le balayage dans `scripts/dependency-inventory.php`._');

    $out .= "\n### Compatibilité avec la licence du projet\n\n";
    $out .= "ScoutMagic est publié sous **AGPL-3.0-or-later**. Pour chaque licence rencontrée ci-dessus, pourquoi elle peut être combinée avec celle-ci :\n\n";
    $out .= inventory_compatibility_table(array_merge($php, $phpDev, $node, $vendored));

    return $out;
}

/**
 * Read and decode a JSON file, or refuse loudly.
 *
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function inventory_read_json(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('missing ' . $path);
    }

    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException($path . ' is not valid JSON');
    }

    return $data;
}

/**
 * The packages of one composer.lock section, at their locked versions.
 *
 * @param array<string, mixed> $lock
 * @return array<string, array{version: string, licence: string}>
 */
function inventory_composer_packages(array $lock, string $section): array
{
    $rows = [];
    $packages = $lock[$section] ?? [];
    if (!is_array($packages)) {
        return $rows;
    }

    foreach ($packages as $package) {
        if (!is_array($package) || !isset($package['name'], $package['version'])) {
            continue;
        }
        $licence = $package['license'] ?? [];
        $rows[(string) $package['name']] = [
            'version' => (string) $package['version'],
            'licence' => $licence === [] ? '**non déclarée**' : implode(' / ', (array) $licence),
        ];
    }
    ksort($rows);

    return $rows;
}

/**
 * The JavaScript development dependencies, at their locked versions.
 *
 * package-lock.json v3 keys every installed tree under "packages", where the
 * direct dependencies appear as "node_modules/<name>". Only the ones
 * package.json actually asks for are listed: the full tree is several
 * hundred entries and nobody reads it. A declared package the lock does not
 * record is reported as such rather than dropped.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, mixed> $lock
 * @return array<string, array{version: string, licence: string}>
 */
function inventory_npm_packages(array $manifest, array $lock): array
{
    $dependencies = is_array($manifest['dependencies'] ?? null) ? $manifest['dependencies'] : [];
    $devDependencies = is_array($manifest['devDependencies'] ?? null) ? $manifest['devDependencies'] : [];
    $wanted = array_merge(array_keys($dependencies), array_keys($devDependencies));
    $packages = is_array($lock['packages'] ?? null) ? $lock['packages'] : [];

    $rows = [];
    foreach ($wanted as $name) {
        $entry = $packages['node_modules/' . $name] ?? null;
        $version = is_array($entry) && isset($entry['version']) ? (string) $entry['version'] : '**absente du lock**';
        $licence = is_array($entry) ? ($entry['license'] ?? null) : null;
        $rows[(string) $name] = [
            'version' => $version,
            'licence' => $licence === null ? '**non déclarée**' : implode(' / ', (array) $licence),
        ];
    }
    ksort($rows);

    return $rows;
}

/**
 * The vendored front-end libraries, version read from each file's banner.
 *
 * Driven by the DIRECTORIES actually present, not by the map alone: a
 * library vendored without an entry in INVENTORY_VENDORED_LIBRARIES is
 * listed with "à déclarer" in every column, which is what turns a
 * forgotten update to this file into something a reader of the release
 * notes sees. A mapped library whose file or banner is missing is reported
 * the same way rather than skipped.
 *
 * @return array<string, array{version: string, licence: string}>
 */
function inventory_vendored_libraries(string $vendorDir): array
{
    $rows = [];
    $directories = is_dir($vendorDir) ? array_filter(
        scandir($vendorDir) ?: [],
        static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($vendorDir . '/' . $entry)
    ) : [];

    foreach ($directories as $directory) {
        $library = INVENTORY_VENDORED_LIBRARIES[$directory] ?? null;
        if ($library === null) {
            $rows[$directory] = [
                'version' => '**à déclarer dans `scripts/dependency-inventory.php`**',
                'licence' => '**à déclarer**',
            ];
            continue;
        }

        $rows[$library['name']] = [
            'version' => inventory_banner_version($vendorDir . '/' . $library['file'], $library['pattern']),
            'licence' => $library['licence'],
        ];
    }

    // Libraries in the map whose directory is gone are not listed: nothing
    // shipped. The freshness gate in scripts/release.sh is what fails on a
    // missing file, and it does so before any release exists.
    ksort($rows, SORT_FLAG_CASE | SORT_STRING);

    return $rows;
}

/**
 * The version a vendored file's banner declares, or a message a reader
 * cannot mistake for one.
 */
function inventory_banner_version(string $file, string $pattern): string
{
    if (!is_file($file)) {
        return '**fichier introuvable** (`' . basename($file) . '`)';
    }

    // The banner is the first few hundred bytes; reading a 200 KB minified
    // bundle whole to find it would be wasteful, and the freshness gate's
    // `grep | head -1` has the same reach.
    $head = (string) file_get_contents($file, false, null, 0, 4096);
    if (preg_match($pattern, $head, $match) !== 1) {
        return '**version illisible dans la bannière**';
    }

    return $match[1];
}

/**
 * One Markdown table of name/version/licence rows.
 *
 * @param array<string, array{version: string, licence: string}> $rows
 */
function inventory_table(array $rows, string $emptyNote): string
{
    if ($rows === []) {
        return $emptyNote . "\n";
    }

    $out = "| Paquet | Version | Licence |\n|---|---|---|\n";
    foreach ($rows as $name => $row) {
        $out .= '| `' . $name . '` | ' . $row['version'] . ' | ' . $row['licence'] . " |\n";
    }

    return $out;
}

/**
 * The licences actually present, each with the reason it may be combined
 * with AGPL-3.0-or-later — or a flag that nobody has written one yet.
 *
 * A package declaring several licences ("MIT / Apache-2.0") is a choice
 * offered to the user of that package, so each alternative is listed on its
 * own and counted once per package.
 *
 * @param array<string, array{version: string, licence: string}> $rows
 */
function inventory_compatibility_table(array $rows): string
{
    $counts = [];
    foreach ($rows as $row) {
        foreach (explode(' / ', $row['licence']) as $licence) {
            $counts[$licence] = ($counts[$licence] ?? 0) + 1;
        }
    }
    ksort($counts, SORT_FLAG_CASE | SORT_STRING);

    $out = "| Licence | Paquets | Compatibilité |\n|---|---|---|\n";
    foreach ($counts as $licence => $count) {
        $verdict = INVENTORY_LICENCE_COMPATIBILITY[$licence] ?? INVENTORY_LICENCE_UNKNOWN;
        if (str_starts_with($licence, '**')) {
            // "**non déclarée**" and the vendored "**à déclarer**" markers:
            // not a licence, and not something to look up.
            $verdict = '**À examiner** — aucune licence déclarée.';
        }
        $out .= '| ' . $licence . ' | ' . $count . ' | ' . $verdict . " |\n";
    }

    return $out;
}
