<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Writes the sample archives of the format `scoutmagic.email-history`
 * (docs/formats/data-migration/email-history.md):
 *
 * - `exemple/` and `exemple.zip` — a valid archive, every optional part
 *   shown once;
 * - `cas-ignores/` and `cas-ignores.zip` — a valid archive whose emails are
 *   each ignored, or imported without one of their parts, for one reason;
 * - `version-majeure-2.zip` — refused whole: a major version this site does
 *   not read.
 *
 * Everything in them is invented (decision D1): the unit « 99e Exempleville »,
 * its addresses on `exemple.test` and its Desk ids do not exist.
 *
 *     php tests/fixtures/data-migration/email-history/generate.php
 *
 * The ZIPs are written with a fixed date on every member, so running it
 * again rewrites the same bytes.
 */

const FIXED_MTIME = 1730000000;

$here = __DIR__;

// A one-page PDF, written by hand: `%PDF-` is what the real type is read from.
$pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
    . "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
    . "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >> endobj\n"
    . "trailer << /Root 1 0 R >>\n%%EOF\n";
// A 1×1 transparent PNG.
$png = (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    true
);
// Text that claims to be a PDF: its real type is not.
$fakePdf = "Ceci n'est pas un PDF.\n";

/**
 * @param array<string, string> $files path => bytes, manifest excluded
 * @param array<string, mixed> $manifest fields replacing the defaults
 */
function writeArchive(string $directory, string $zipPath, array $files, array $manifest = []): void
{
    ksort($files, SORT_STRING);
    $inventory = [];
    foreach ($files as $path => $bytes) {
        $inventory[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
    }
    $files = ['manifest.json' => json_encode(array_replace([
        'format' => 'scoutmagic.email-history',
        'format_version' => '1.0.0',
        'source' => ['installation_id' => 'site-exempleville', 'name' => 'Ancien site du 99e Exempleville'],
        'export_id' => 'export-2026-11-08-courriels',
        'exported_at' => '2026-11-08T14:30:00+01:00',
        'files' => $inventory,
    ], $manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"] + $files;

    if ($directory !== '') {
        removeTree($directory);
        foreach ($files as $path => $bytes) {
            $target = $directory . '/' . $path;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            file_put_contents($target, $bytes);
        }
    }

    @unlink($zipPath);
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $path => $bytes) {
        $zip->addFromString($path, $bytes);
        $zip->setMtimeName($path, FIXED_MTIME);
    }
    $zip->close();
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

/** @return array{0: string, 1: string} path, bytes */
function content(string $extension, string $bytes): array
{
    return ['content/' . hash('sha256', $bytes) . '.' . $extension, $bytes];
}

/** @return array{0: string, 1: string, 2: string} path, bytes, sha256 */
function attachment(string $bytes): array
{
    $sha = hash('sha256', $bytes);

    return ['attachments/' . $sha, $bytes, $sha];
}

/** @param array<string, mixed> $communication */
function communication(array $communication): string
{
    return json_encode($communication, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

// ─── exemple : valid, every optional part shown once ───────────────────────

$files = [];

[$html1, $html1Bytes] = content('html', "<h1>Camp d'été 2024</h1>\n<p>Chers parents,</p>\n"
    . "<p>Le camp des Éclaireurs aura lieu du <strong>15 au 29 juillet</strong>. Le programme est joint.</p>\n"
    . "<p><img src=\"cid:logo-unite\" alt=\"Logo\"></p>\n<script>alert('retiré à l\\'import')</script>\n");
[$text1, $text1Bytes] = content('txt', "Camp d'été 2024\n\nChers parents,\n\nLe camp des Éclaireurs aura lieu du 15 au 29 juillet.\n");
[$pdfPath, $pdfBytes, $pdfSha] = attachment($pdf);
[$pngPath, $pngBytes, $pngSha] = attachment($png);
$files += [$html1 => $html1Bytes, $text1 => $text1Bytes, $pdfPath => $pdfBytes, $pngPath => $pngBytes];
$files['communications/c-2024-0521-001.json'] = communication([
    'id' => 'c-2024-0521-001',
    'sent_at' => '2024-05-21T18:30:00+02:00',
    'subject' => "Infos camp d'été 2024",
    'sender' => ['address' => 'eclaireurs@exemple.test', 'name' => 'Éclaireurs 99e Exempleville'],
    'body' => ['html' => $html1, 'text' => $text1],
    'audience_name' => 'Parents des Éclaireurs',
    'mail_merge' => false,
    'recipients' => [
        ['address' => 'famille.alpha@exemple.test', 'status' => 'accepted', 'sent_at' => '2024-05-21T18:31:04+02:00', 'desk_ids' => ['900001']],
        ['address' => 'famille.beta@exemple.test', 'status' => 'unknown', 'desk_ids' => ['900002']],
        ['address' => 'ancien.chef@exemple.test', 'status' => 'failed'],
    ],
    'desk_ids' => ['900003'],
    'attachments' => [
        ['path' => $pdfPath, 'sha256' => $pdfSha, 'filename' => 'programme-camp.pdf', 'media_type' => 'application/pdf'],
        ['path' => $pngPath, 'sha256' => $pngSha, 'filename' => 'logo.png', 'media_type' => 'image/png', 'content_id' => 'logo-unite'],
    ],
    'legacy_campaign_id' => 4127,
]);

[$text2, $text2Bytes] = content('txt', "Bonjour,\n\nLa réunion de rentrée a lieu samedi à 14 h au local.\n\nLe Staff d'U\n");
$files[$text2] = $text2Bytes;
$files['communications/c-2023-0904-002.json'] = communication([
    'id' => 'c-2023-0904-002',
    'sent_at' => '2023-09-04T07:15:00Z',
    'subject' => 'Réunion de rentrée',
    'sender' => ['address' => 'staff@exemple.test'],
    'body' => ['text' => $text2],
    'audience_name' => 'Toute l\'unité',
]);

[$html3, $html3Bytes] = content('html', "<p>Bonjour {{Prenom}},</p>\n<p>Ta cotisation de {{Montant}} € est attendue pour le 30 septembre.</p>\n");
$files[$html3] = $html3Bytes;
$files['communications/c-2022-0912-003.json'] = communication([
    'id' => 'c-2022-0912-003',
    'sent_at' => '2022-09-12T20:00:00+02:00',
    'subject' => 'Cotisation {{Annee}}',
    'sender' => ['address' => 'tresorerie@exemple.test', 'name' => 'Trésorerie'],
    'body' => ['html' => $html3],
    'mail_merge' => true,
    'recipients' => [
        ['address' => 'famille.alpha@exemple.test', 'status' => 'accepted', 'desk_ids' => ['900001']],
    ],
]);

writeArchive($here . '/exemple', $here . '/exemple.zip', $files);

// ─── cas-ignores : one reason per email ────────────────────────────────────

$cases = [];
[$okHtml, $okHtmlBytes] = content('html', "<p>Un message ordinaire.</p>\n");
[$emptyHtml, $emptyHtmlBytes] = content('html', "<script>seulement un script</script>\n");
[$badSha, $badShaBytes, ] = attachment($pdf . "%modifié\n");
[$fakePath, $fakeBytes, $fakeSha] = attachment($fakePdf);
$cases += [$okHtml => $okHtmlBytes, $emptyHtml => $emptyHtmlBytes, $badSha => $badShaBytes, $fakePath => $fakeBytes];
$base = [
    'sent_at' => '2021-03-01T10:00:00+01:00',
    'subject' => 'Un sujet',
    'sender' => ['address' => 'baladins@exemple.test'],
    'body' => ['html' => $okHtml],
];
$variants = [
    'i-01-sans-sujet' => ['subject' => '   '],
    'i-02-sans-date' => ['sent_at' => null],
    'i-03-date-sans-fuseau' => ['sent_at' => '2021-03-01T10:00:00'],
    'i-04-sans-expediteur' => ['sender' => ['name' => 'Personne']],
    'i-05-expediteur-invalide' => ['sender' => ['address' => 'pas-une-adresse']],
    'i-06-sans-contenu' => ['body' => []],
    'i-07-contenu-vide-une-fois-nettoye' => ['body' => ['html' => $emptyHtml]],
    'i-08-contenu-introuvable' => ['body' => ['html' => 'content/' . str_repeat('0', 64) . '.html']],
    // Imported, but without their invalid parts.
    'p-09-piece-jointe-empreinte-fausse' => ['attachments' => [
        ['path' => $badSha, 'sha256' => str_repeat('a', 64), 'filename' => 'faux.pdf'],
    ]],
    'p-10-piece-jointe-type-refuse' => ['attachments' => [
        ['path' => $fakePath, 'sha256' => $fakeSha, 'filename' => 'pas-un.pdf'],
    ]],
    'p-11-destinataire-invalide-et-membre-inconnu' => ['recipients' => [
        ['address' => 'pas une adresse', 'status' => 'accepted'],
        ['address' => 'inconnu@exemple.test', 'status' => 'delivered', 'desk_ids' => ['999999', 12]],
    ]],
];
foreach ($variants as $id => $override) {
    $communication = array_replace(['id' => $id], $base, $override);
    $communication = array_filter($communication, static fn (mixed $value): bool => $value !== null);
    $cases['communications/' . $id . '.json'] = communication($communication);
}
// An identifier that is a JSON number: refused, its leading zeros already lost.
$cases['communications/i-12-identifiant-numerique.json'] = communication(['id' => 12] + $base);
$cases['communications/i-13-pas-du-json.json'] = "{ ceci n'est pas du JSON\n";

writeArchive($here . '/cas-ignores', $here . '/cas-ignores.zip', $cases);

// ─── version-majeure-2 : refused whole ─────────────────────────────────────

writeArchive('', $here . '/version-majeure-2.zip', [
    'communications/c-1.json' => communication(['id' => 'c-1'] + $base),
    $okHtml => $okHtmlBytes,
], ['format_version' => '2.0.0']);

echo "Archives written in {$here}\n";
