<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Modules\SupportDashboard\TicketCategory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

/**
 * Turns the diagnostic archive an installation transmitted into the
 * reduced, anonymised copy the automated GitHub triage reads
 * (ARCHITECTURE.md §8.49sexies).
 *
 * **An allowlist, never a denylist.** Every entry of the archive is
 * either named here — copied as text after scrubbing, or converted from
 * a spreadsheet — or it is left out and the README says so. A collector
 * added to the archive tomorrow therefore reaches no extract until
 * somebody reads what it writes and adds it here; the failure mode of
 * the other design is a new file leaking on the day it appears.
 *
 * **Left out on purpose**: `phpinfo.html`, which describes the hosting
 * in a detail a triage never needs and a reader could quote; and
 * `configuration-parameters.xlsx`, whose rows include the unit's name
 * and addresses. The ticket's contact address, the installation id and
 * the instance URL are not in the archive and are not added here.
 *
 * **Bounded, because the input is another installation's upload.** The
 * intake caps the archive at 60 MB and the generator's own limits put a
 * real one far below that, but a zip's declared sizes are what its
 * author wrote, so every entry is read through a stream with a hard
 * byte ceiling and the whole extract has one too. An entry over the
 * ceiling is omitted and named, never truncated in silence.
 *
 * The extract is built in memory from two temporary files that live for
 * the length of the call and are unlinked in a `finally` — the same
 * shape `SupportPackageService::generate()` uses, and the reason it can
 * be served without a plaintext copy ever resting on the receiver's disk.
 */
final class TriageExtractBuilder
{
    public const README_ENTRY = 'LISEZ-MOI.txt';
    public const TICKET_ENTRY = 'ticket.txt';

    /** Same clamp the cross-ticket analysis applies to a description. */
    public const DESCRIPTION_MAX_CHARS = 1500;

    /** The generator caps a single log at 2 MB; four times that is generous. */
    public const MAX_ENTRY_BYTES = 8 * 1024 * 1024;

    /** Well above a real archive's text, well below the 60 MB intake cap. */
    public const MAX_TOTAL_BYTES = 40 * 1024 * 1024;

    /**
     * Entries copied as text, once scrubbed. Exact names, as the
     * collectors under `core/Support/Collector/` write them.
     */
    private const TEXT_ENTRIES = [
        'statistics.json',
        'database-structure.sql',
        'event-journal-resume.txt',
        'request-timelines.csv',
        'request-timelines-resume.txt',
        'scheduler-settings.txt',
        'extensions.txt',
        'opcache.json',
        'filesystem.txt',
        'commands.txt',
        'background-execution.txt',
        'cron-cadence.txt',
        'collection-status.json',
        'README.txt',
    ];

    /** Directories whose every entry is copied as text, once scrubbed. */
    private const TEXT_PREFIXES = ['logs/', 'webserver/'];

    /**
     * Spreadsheets converted to CSV. `tokenise` names the columns whose
     * every value becomes a token of that kind, whatever it looks like:
     * the event journal's user-account and IP columns are personal by
     * position, not by shape.
     *
     * @var array<string, array{csv: string, tokenise: array<int, string>}>
     */
    private const SPREADSHEET_ENTRIES = [
        'event-journal.xlsx' => ['csv' => 'event-journal.csv', 'tokenise' => [1 => 'user', 2 => 'ip']],
        'scheduled-tasks.xlsx' => ['csv' => 'scheduled-tasks.csv', 'tokenise' => []],
        'update-history.xlsx' => ['csv' => 'update-history.csv', 'tokenise' => []],
    ];

    /**
     * Entries left out by decision, with the sentence the README gives.
     *
     * @var array<string, string>
     */
    private const EXCLUDED_ENTRIES = [
        'phpinfo.html' => "la configuration détaillée de PHP sur l'hébergement",
        'configuration-parameters.xlsx' => 'les paramètres de configuration du site, qui nomment l\'unité',
    ];

    /**
     * @param array<string, mixed> $ticket a hydrated ticket row
     * @param string $archiveBytes the archive exactly as the installation sent it
     * @param int $issueNumber the GitHub issue the extract is being built for
     * @return string the extract, a zip
     * @throws \RuntimeException when neither zip could be opened
     */
    public function build(array $ticket, string $archiveBytes, int $issueNumber, \DateTimeImmutable $now): string
    {
        $scrubber = new TriageExtractScrubber();

        $sourcePath = tempnam(sys_get_temp_dir(), 'sm-triage-in-');
        $targetPath = tempnam(sys_get_temp_dir(), 'sm-triage-out-');
        if ($sourcePath === false || $targetPath === false) {
            throw new \RuntimeException('Could not allocate a temporary file for the triage extract.');
        }

        try {
            if (file_put_contents($sourcePath, $archiveBytes) === false) {
                throw new \RuntimeException('Could not write the archive to a temporary file.');
            }

            $source = new \ZipArchive();
            if ($source->open($sourcePath, \ZipArchive::RDONLY) !== true) {
                throw new \RuntimeException('The transmitted archive could not be opened as a zip.');
            }

            $target = new \ZipArchive();
            if ($target->open($targetPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                $source->close();
                throw new \RuntimeException('Could not create the triage extract.');
            }

            $copied = [];
            $omitted = [];
            $totalBytes = 0;

            for ($i = 0; $i < $source->numFiles; $i++) {
                $stat = $source->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $name = (string) $stat['name'];
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                if (isset(self::EXCLUDED_ENTRIES[$name])) {
                    $omitted[$name] = self::EXCLUDED_ENTRIES[$name] . ' (retirée par décision)';
                    continue;
                }

                $spreadsheet = self::SPREADSHEET_ENTRIES[$name] ?? null;
                if ($spreadsheet === null && !self::isTextEntry($name)) {
                    $omitted[$name] = 'rubrique inconnue de cette version du site, non copiée par précaution';
                    continue;
                }

                $content = self::readBounded($source, $name);
                if ($content === null) {
                    $omitted[$name] = 'entrée trop volumineuse (plus de ' . self::MAX_ENTRY_BYTES . ' octets)';
                    continue;
                }

                if ($spreadsheet !== null) {
                    $outputName = $spreadsheet['csv'];
                    $output = self::spreadsheetToCsv($content, $spreadsheet['tokenise'], $scrubber);
                    if ($output === null) {
                        $omitted[$name] = 'feuille de calcul illisible, non copiée';
                        continue;
                    }
                } else {
                    $outputName = $name;
                    $output = $scrubber->scrub($content);
                }

                if ($totalBytes + strlen($output) > self::MAX_TOTAL_BYTES) {
                    $omitted[$name] = 'volume total de l\'extrait atteint, non copiée';
                    continue;
                }

                $target->addFromString($outputName, $output);
                $copied[] = $outputName;
                $totalBytes += strlen($output);
            }

            $source->close();

            $target->addFromString(self::TICKET_ENTRY, self::renderTicket($ticket, $scrubber));
            $target->addFromString(
                self::README_ENTRY,
                self::renderReadme($ticket, $issueNumber, $now, $copied, $omitted, $scrubber)
            );
            $target->close();

            $bytes = @file_get_contents($targetPath);
            if ($bytes === false) {
                throw new \RuntimeException('The triage extract could not be read back.');
            }

            return $bytes;
        } finally {
            @unlink($sourcePath);
            @unlink($targetPath);
        }
    }

    private static function isTextEntry(string $name): bool
    {
        if (in_array($name, self::TEXT_ENTRIES, true)) {
            return true;
        }

        foreach (self::TEXT_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entry's bytes, or null when it is larger than the ceiling —
     * measured by reading, not by trusting the size the zip declares.
     */
    private static function readBounded(\ZipArchive $zip, string $name): ?string
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return null;
        }

        try {
            $content = stream_get_contents($stream, self::MAX_ENTRY_BYTES + 1);
        } finally {
            fclose($stream);
        }

        if ($content === false || strlen($content) > self::MAX_ENTRY_BYTES) {
            return null;
        }

        return $content;
    }

    /**
     * First sheet to CSV, every cell scrubbed, the named columns
     * tokenised whatever they hold. Null when PhpSpreadsheet cannot read
     * the bytes at all.
     *
     * @param array<int, string> $tokenise column index => token kind
     */
    private static function spreadsheetToCsv(string $xlsx, array $tokenise, TriageExtractScrubber $scrubber): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'sm-triage-xlsx-');
        if ($path === false) {
            return null;
        }

        try {
            if (file_put_contents($path, $xlsx) === false) {
                return null;
            }

            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);

            try {
                $spreadsheet = $reader->load($path);
                $rows = $spreadsheet->getActiveSheet()->toArray(null, false, false, false);
                $spreadsheet->disconnectWorksheets();
            } catch (\Throwable) {
                return null;
            }
        } finally {
            @unlink($path);
        }

        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return null;
        }

        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            foreach (array_values((array) $row) as $columnIndex => $value) {
                $text = $value === null ? '' : (string) $value;
                // Row 0 is the header; the tokenised columns are
                // replaced from the first data row on.
                if ($rowIndex > 0 && $text !== '' && isset($tokenise[$columnIndex])) {
                    $text = $scrubber->token($tokenise[$columnIndex], $text);
                }
                $cells[] = $scrubber->scrub($text);
            }
            fputcsv($out, $cells, ',', '"', '\\', "\n");
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv === false ? null : $csv;
    }

    /**
     * The ticket as the triage may see it: category, versions, dates and
     * the description — scrubbed and clamped. Never the contact address,
     * never the installation.
     *
     * @param array<string, mixed> $ticket
     */
    private static function renderTicket(array $ticket, TriageExtractScrubber $scrubber): string
    {
        $category = $ticket['category'] ?? null;
        $description = trim((string) ($ticket['description'] ?? ''));
        if (mb_strlen($description) > self::DESCRIPTION_MAX_CHARS) {
            $description = mb_substr($description, 0, self::DESCRIPTION_MAX_CHARS) . ' […]';
        }

        $lines = [
            '# Ticket de support ' . (string) ($ticket['reference'] ?? ''),
            'Catégorie              : ' . ($category instanceof TicketCategory ? $category->label() : 'Non précisée'),
            'Reçu le                : ' . (string) ($ticket['created_at'] ?? ''),
            'Statut                 : ' . ((($ticket['status'] ?? '') === 'closed') ? 'clôturé' : 'ouvert'),
            'Version du site        : ' . (string) ($ticket['site_version'] ?? 'non renseignée'),
            'Version de PHP         : ' . (string) ($ticket['php_version'] ?? 'non renseignée'),
            'Archive reçue le       : ' . (string) ($ticket['archive_received_at'] ?? ''),
            '',
            '## Description (anonymisée, tronquée à ' . self::DESCRIPTION_MAX_CHARS . ' caractères)',
            $scrubber->scrub($description),
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $ticket
     * @param list<string> $copied
     * @param array<string, string> $omitted entry => why
     */
    private static function renderReadme(
        array $ticket,
        int $issueNumber,
        \DateTimeImmutable $now,
        array $copied,
        array $omitted,
        TriageExtractScrubber $scrubber
    ): string {
        $lines = [
            '# Extrait de triage — ticket ' . (string) ($ticket['reference'] ?? ''),
            '',
            'Généré le ' . $now->format('Y-m-d H:i:s') . ' (UTC) par le site support de ScoutMagic pour le triage',
            'automatique de l\'issue GitHub n°' . $issueNumber . '.',
            '',
            'Ce fichier est une COPIE RÉDUITE ET ANONYMISÉE de l\'archive de diagnostic que',
            'l\'installation a transmise avec son ticket. Il est produit à la demande, jamais',
            'conservé, et destiné à un agent de triage qui lit du code — pas à une personne.',
            'Rien de ce qu\'il contient ne doit être cité mot pour mot dans un commentaire',
            'public : il décrit l\'hébergement de quelqu\'un.',
            '',
            '## Ce qui a été retiré',
            '',
        ];

        foreach ($omitted as $name => $why) {
            $lines[] = '- ' . $name . ' : ' . $why;
        }
        $lines[] = '- l\'adresse de contact, l\'identifiant d\'installation et l\'URL de l\'instance ne';
        $lines[] = '  figurent nulle part : ils ne sont pas dans l\'archive et ne sont pas ajoutés ici.';
        $lines[] = '';
        $lines[] = '## Ce qui a été transformé';
        $lines[] = '';
        $lines[] = '- Chaque adresse IP est remplacée par un jeton stable (ip-1, ip-2…) : deux lignes';
        $lines[] = '  portant le même jeton viennent du même client, et rien ne permet de retrouver';
        $lines[] = '  l\'adresse. La table de correspondance n\'existe que le temps de la génération.';
        $lines[] = '  Adresses distinctes remplacées : ' . $scrubber->count('ip') . '.';
        $lines[] = '- Chaque adresse e-mail devient email-N (' . $scrubber->count('email') . ' distincte(s)),';
        $lines[] = '  chaque compte utilisateur du journal user-N (' . $scrubber->count('user') . '),';
        $lines[] = '  chaque identifiant hexadécimal long — session, jeton, fichier — hex-N';
        $lines[] = '  (' . $scrubber->count('hex') . ').';
        $lines[] = '- La valeur des paramètres d\'URL sensibles (token, key, email…) est masquée.';
        $lines[] = '- Les feuilles de calcul sont converties en CSV ; les colonnes « Compte';
        $lines[] = '  utilisateur » et « Adresse IP » du journal des événements sont tokenisées';
        $lines[] = '  en entier.';
        $lines[] = '';
        $lines[] = '## Contenu';
        $lines[] = '';
        $lines[] = '- ' . self::TICKET_ENTRY . ' : le ticket — catégorie, versions, description anonymisée.';
        foreach ($copied as $name) {
            $lines[] = '- ' . $name;
        }
        $lines[] = '';
        $lines[] = 'Les horodatages gardent la zone de l\'horloge qui les a écrits : voir logs/summary.txt';
        $lines[] = 'et collection-status.json dans l\'archive d\'origine, copiés ici tels quels.';

        return implode("\n", $lines) . "\n";
    }
}
