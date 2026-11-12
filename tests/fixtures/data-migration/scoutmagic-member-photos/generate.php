<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Writes the example archives of the `scoutmagic-member-photos` format
 * (docs/formats/data-migration/member-photos.md) next to this script:
 *
 * - `exemple.zip` — the valid example. Four lines for the two members
 *   every end-to-end instance has (`E2E-ADMIN` and `E2E-MEMBER`, seeded by
 *   scripts/e2e-support.php), on two past scout years: three photos are
 *   imported, the fourth is a second photo for a member and year already
 *   given one by an earlier line, and is ignored.
 * - `elements-invalides.zip` — a structurally valid archive whose lines
 *   each fall into one reason of the report, and one valid line to show
 *   the others do not stop it.
 * - `invalide-empreinte.zip`, `invalide-version.zip`,
 *   `invalide-extension.zip` — archives refused as a whole before
 *   anything is written.
 *
 * The images are drawn here: flat shapes on a coloured background, no
 * photograph, nobody's face. The desk ids are those of the end-to-end
 * fixtures and of nobody real (D1).
 *
 *     php tests/fixtures/data-migration/scoutmagic-member-photos/generate.php
 *
 * The archives are committed: the tests and the end-to-end scenario read
 * them as an exporter would hand them over. GD's encoders are not
 * byte-stable across versions, so nothing compares a regenerated archive
 * with the committed one; `Tests\Modules\DataMigration\Importer\
 * MemberPhotosImportOnMysqlTest` checks what each one MEANS instead.
 */

const FIXED_MTIME = 1762603800; // 2025-11-08T12:10:00Z, so a rebuild does not churn the zip headers.

/**
 * @return string the encoded image
 */
function drawPortrait(int $width, int $height, array $background, array $shape, string $type): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$background));
    $ink = imagecolorallocate($image, ...$shape);
    // A head and shoulders in silhouette: two shapes, no features.
    imagefilledellipse($image, intdiv($width, 2), intdiv($height * 2, 5), intdiv($width, 2), intdiv($width, 2), $ink);
    imagefilledellipse($image, intdiv($width, 2), $height, $width - 20, intdiv($height, 2), $ink);

    ob_start();
    match ($type) {
        'jpg' => imagejpeg($image, null, 85),
        'png' => imagepng($image, null, 9),
        'webp' => imagewebp($image, null, 80),
    };
    imagedestroy($image);

    return (string) ob_get_clean();
}

/**
 * @param array<string, string> $files path => bytes, in archive order
 * @param array<string, mixed> $manifest fields replacing the defaults
 * @param array<string, string> $wrongFingerprints path => fingerprint the manifest announces instead
 */
function writeArchive(string $target, array $files, array $manifest = [], array $wrongFingerprints = []): void
{
    $inventory = [];
    foreach ($files as $path => $bytes) {
        $inventory[] = [
            'path' => $path,
            'sha256' => $wrongFingerprints[$path] ?? hash('sha256', $bytes),
            'size' => strlen($bytes),
        ];
    }

    $manifestBytes = json_encode(array_replace([
        'format' => 'scoutmagic-member-photos',
        'format_version' => '1.0.0',
        'source' => ['installation_id' => 'exemple-ancien-site', 'name' => 'Ancien site d\'exemple'],
        'export_id' => 'exemple-photos-2026-11-08',
        'exported_at' => '2026-11-08T14:30:00+01:00',
        'files' => $inventory,
    ], $manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    @unlink($target);
    $zip = new ZipArchive();
    if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        fwrite(STDERR, "Cannot write {$target}\n");
        exit(1);
    }
    $zip->addFromString('manifest.json', $manifestBytes);
    $zip->setMtimeName('manifest.json', FIXED_MTIME);
    foreach ($files as $path => $bytes) {
        $zip->addFromString($path, $bytes);
        $zip->setMtimeName($path, FIXED_MTIME);
    }
    $zip->close();
}

/**
 * @param list<array<string, mixed>|string> $lines objects, or a raw line
 */
function jsonLines(array $lines): string
{
    return implode('', array_map(
        static fn (array|string $line): string => (is_string($line)
            ? $line
            : json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n",
        $lines
    ));
}

/** @return array{string, string} path, bytes */
function image(string $bytes, string $extension): array
{
    return ['images/' . hash('sha256', $bytes) . '.' . $extension, $bytes];
}

/**
 * @param array{string, string} $image
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function line(string $deskId, string $year, array $image, array $extra = []): array
{
    return ['desk_id' => $deskId, 'scout_year' => $year, 'path' => $image[0], 'sha256' => hash('sha256', $image[1])]
        + $extra;
}

$here = __DIR__;

$blue = image(drawPortrait(120, 160, [46, 94, 140], [235, 242, 250], 'jpg'), 'jpg');
$green = image(drawPortrait(120, 160, [61, 122, 74], [240, 247, 238], 'png'), 'png');
$orange = image(drawPortrait(120, 160, [196, 110, 40], [252, 243, 230], 'webp'), 'webp');
$purple = image(drawPortrait(120, 160, [104, 72, 150], [244, 238, 252], 'jpg'), 'jpg');

// The valid example.
writeArchive("{$here}/exemple.zip", [
    'photos.jsonl' => jsonLines([
        line('E2E-ADMIN', '2022-2023', $blue, ['original_name' => 'portrait-2022.jpg', 'taken_at' => '2022-10-01']),
        line('E2E-ADMIN', '2023-2024', $green),
        line('E2E-MEMBER', '2023-2024', $orange, ['original_name' => 'portrait-2023.webp']),
        line('E2E-MEMBER', '2023-2024', $purple),
    ]),
    $blue[0] => $blue[1],
    $green[0] => $green[1],
    $orange[0] => $orange[1],
    $purple[0] => $purple[1],
]);

// One reason per line.
$pngNamedJpg = ['images/' . hash('sha256', $green[1]) . '.jpg', $green[1]];
$broken = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 12) . 'not the rest of a JPEG';
$brokenImage = image($broken, 'jpg');
$absent = image('an image nobody put in the archive', 'jpg');
// Named after one image, holding another: the line and its path agree, the
// manifest (which fingerprints the bytes) does not.
$misnamed = ['images/' . hash('sha256', $purple[1]) . '.jpg', $blue[1]];
writeArchive("{$here}/elements-invalides.zip", [
    'photos.jsonl' => jsonLines([
        line('INCONNU-0001', '2022-2023', $blue),
        '{"desk_id": "E2E-ADMIN", "scout_year": ',
        ['desk_id' => 4518, 'scout_year' => '2022-2023', 'path' => $blue[0], 'sha256' => hash('sha256', $blue[1])],
        line('E2E-ADMIN', '2023', $blue),
        ['desk_id' => 'E2E-ADMIN', 'scout_year' => '2022-2023', 'path' => $blue[0], 'sha256' => 'ABC'],
        ['desk_id' => 'E2E-ADMIN', 'scout_year' => '2022-2023', 'path' => $blue[0], 'sha256' => hash('sha256', $green[1])],
        line('E2E-ADMIN', '2022-2023', $pngNamedJpg),
        line('E2E-ADMIN', '2022-2023', $brokenImage),
        line('E2E-ADMIN', '2022-2023', $absent),
        ['desk_id' => 'E2E-ADMIN', 'scout_year' => '2022-2023', 'path' => $misnamed[0], 'sha256' => hash('sha256', $purple[1])],
        line('E2E-MEMBER', '2022-2023', $blue),
    ]),
    $blue[0] => $blue[1],
    $pngNamedJpg[0] => $pngNamedJpg[1],
    $brokenImage[0] => $brokenImage[1],
    $misnamed[0] => $misnamed[1],
]);

$valid = [
    'photos.jsonl' => jsonLines([line('E2E-ADMIN', '2022-2023', $blue)]),
    $blue[0] => $blue[1],
];
writeArchive("{$here}/invalide-empreinte.zip", $valid, [], [$blue[0] => hash('sha256', 'something else')]);
writeArchive("{$here}/invalide-version.zip", $valid, ['format_version' => '2.0.0']);
$gif = drawPortrait(40, 40, [0, 0, 0], [255, 255, 255], 'png');
writeArchive("{$here}/invalide-extension.zip", $valid + ['images/portrait.gif' => $gif]);

echo "Archives written in {$here}\n";
