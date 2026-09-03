<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\File\StoredFileReader;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;

/**
 * Everything this receiver knows about one installation, in one zip.
 *
 * **Why a new archive rather than a fuller one.** The « archive de
 * diagnostic » on a ticket is the file the INSTANCE uploaded: built and
 * encrypted on the other side of the wire, before any of this happened.
 * Adding to it would mean rewriting somebody else's evidence, and an
 * archive whose contents were partly written by the receiver is no longer
 * the thing whose integrity anybody was relying on. So the uploaded file
 * goes in whole, under its own name, and everything the receiver knows
 * goes in beside it.
 *
 * **What was missing, and where it was looked for.** The e-mail probes
 * were a second download; the two readings of the statistics — the one
 * frozen with the ticket and the one from the last report — were on the
 * screen and nowhere else. A maintainer who downloads a ticket's archive
 * to look at it offline, or to attach it to a mail, had a third of the
 * file. The rest existed, in three places, none of them the one place
 * they went.
 *
 * **No new exposure.** Every part of this is already readable, on that
 * same page, by the same superadmin: the archive by its download, the
 * probes by theirs, the statistics on the page itself. Composing them
 * changes what a maintainer has to click, not what they may see.
 *
 * **A dossier is produced even with no archive at all.** Retention
 * deletes an uploaded archive long before the ticket (90 days after
 * closure, a year at the outside), and a ticket read after that used to
 * offer nothing. The probes and the statistics outlive it and are the
 * half that answers « quelle version avaient-ils quand ça a cassé ».
 */
final class TicketDossierBuilder
{
    /** The uploaded file keeps its own name inside, and says whose it is. */
    public const ARCHIVE_ENTRY = 'archive-transmise-par-l-installation.zip';

    public const README_ENTRY = 'LISEZ-MOI.txt';
    public const TICKET_ENTRY = 'ticket.txt';
    public const INSTALLATION_ENTRY = 'installation.txt';
    public const SNAPSHOT_ENTRY = 'statistiques-au-moment-du-ticket.json';
    public const LATEST_ENTRY = 'statistiques-dernieres.json';
    public const COMPARISON_ENTRY = 'statistiques-comparaison.txt';
    public const PROBES_ENTRY = 'sondes-email.txt';

    public function __construct(
        private SupportInstallationRepository $installations,
        /**
         * Null simply leaves the uploaded archive out — a caller that
         * built no reader gets the receiver's own knowledge and a line
         * saying the file could not be read, which is the honest shape of
         * a degradation (§7.5) rather than a failed download.
         */
        private ?StoredFileReader $files = null
    ) {
    }

    /**
     * @param array<string, mixed> $ticket a row from `SupportTicketService::detail()`
     * @return string the zip's bytes
     * @throws \RuntimeException when a zip cannot be produced at all
     */
    public function build(array $ticket, \DateTimeImmutable $now): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sm-dossier-');
        if ($path === false) {
            throw new \RuntimeException('Impossible de créer un fichier temporaire pour le dossier.');
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Impossible de créer le dossier de support.');
            }

            $missing = [];
            $zip->addFromString(self::TICKET_ENTRY, $this->ticketText($ticket, $now));
            $zip->addFromString(self::INSTALLATION_ENTRY, $this->installationText($ticket));
            $zip->addFromString(self::COMPARISON_ENTRY, self::comparisonText($ticket));
            $zip->addFromString(
                self::PROBES_ENTRY,
                MailProbeReport::build(
                    (string) ($ticket['reference'] ?? ''),
                    is_array($ticket['probes'] ?? null) ? $ticket['probes'] : []
                )
            );

            $snapshot = is_array($ticket['statistics_snapshot'] ?? null) ? $ticket['statistics_snapshot'] : [];
            if ($snapshot === []) {
                $missing[] = 'Le rapport d\'utilisation figé au moment du ticket : ce ticket est '
                    . 'antérieur à cette conservation, ou l\'installation n\'en avait envoyé aucun.';
            } else {
                $zip->addFromString(self::SNAPSHOT_ENTRY, self::json($snapshot));
            }

            $latest = $this->latestPayload($ticket);
            if ($latest === null) {
                $missing[] = 'Le dernier rapport d\'utilisation : l\'enregistrement de cette '
                    . 'installation n\'existe plus, ou elle n\'a jamais rien envoyé.';
            } else {
                $zip->addFromString(self::LATEST_ENTRY, $latest);
            }

            $archive = $this->archiveBytes($ticket);
            if ($archive === null) {
                $missing[] = 'L\'archive de diagnostic transmise par l\'installation : elle n\'a pas '
                    . 'été transmise, ou sa rétention l\'a déjà supprimée.';
            } else {
                $zip->addFromString(self::ARCHIVE_ENTRY, $archive);
            }

            // Written LAST, because it is the only entry that can say what
            // the others turned out to be.
            $zip->addFromString(self::README_ENTRY, self::readme($ticket, $now, $missing));
            $zip->close();

            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException('Le dossier de support n\'a pas pu être relu.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    /**
     * The name the browser saves it under — the reference, so two tickets
     * never land on each other in a downloads folder.
     */
    /** @param array<string, mixed> $ticket */
    public static function filename(array $ticket): string
    {
        $reference = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($ticket['reference'] ?? '')) ?? '';

        return 'dossier-support-' . ($reference !== '' ? $reference : 'ticket') . '.zip';
    }

    /**
     * @param array<string, mixed> $ticket
     * @param list<string> $missing
     */
    private static function readme(array $ticket, \DateTimeImmutable $now, array $missing): string
    {
        $lines = [
            'Dossier de support — ticket ' . (string) ($ticket['reference'] ?? ''),
            'Composé le ' . $now->format('Y-m-d H:i:s') . ' par le receveur, à la demande d\'un',
            'super-administrateur. Il rassemble tout ce que ce receveur sait de cette',
            'installation ; rien n\'y a été ajouté qui ne fût déjà lisible sur la page du ticket.',
            '',
            str_repeat('=', 72),
            '',
            'Contenu',
            '',
            '  ' . self::TICKET_ENTRY . '        le ticket lui-même, tel qu\'il a été reçu',
            '  ' . self::INSTALLATION_ENTRY . '  ce que le receveur sait de l\'installation',
            '  ' . self::SNAPSHOT_ENTRY,
            '        le rapport d\'utilisation FIGÉ au moment du ticket',
            '  ' . self::LATEST_ENTRY,
            '        le dernier rapport reçu depuis, brut',
            '  ' . self::COMPARISON_ENTRY,
            '        les deux côte à côte, et ce qui a bougé entre les deux',
            '  ' . self::PROBES_ENTRY . '    les sondes e-mail et leurs en-têtes',
            '  ' . self::ARCHIVE_ENTRY,
            '        l\'archive que l\'installation a transmise, telle quelle',
            '',
            'L\'archive transmise n\'est PAS modifiée ni recomposée : elle a été construite et',
            'chiffrée de l\'autre côté du fil, et y toucher reviendrait à réécrire la pièce',
            'de quelqu\'un d\'autre. Elle est simplement placée ici entière.',
        ];

        if ($missing !== []) {
            $lines[] = '';
            $lines[] = str_repeat('=', 72);
            $lines[] = '';
            // Said rather than left out: a dossier that silently lacks a
            // file is one a maintainer searches for.
            $lines[] = 'Ce qui manque, et pourquoi';
            $lines[] = '';
            foreach ($missing as $reason) {
                $lines[] = '  - ' . $reason;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function ticketText(array $ticket, \DateTimeImmutable $now): string
    {
        $category = $ticket['category'] ?? null;

        $lines = [
            'Ticket ' . (string) ($ticket['reference'] ?? ''),
            'Extrait le ' . $now->format('Y-m-d H:i:s'),
            '',
            'Catégorie                : ' . (is_object($category) ? $category->label() : 'non précisée'),
            'Statut                   : ' . ((string) ($ticket['status'] ?? '') === 'closed' ? 'clôturé' : 'ouvert'),
            'Reçu le                  : ' . self::orUnknown($ticket['created_at'] ?? null),
            'Clôturé le               : ' . self::orUnknown($ticket['closed_at'] ?? null),
            'Adresse de contact       : ' . self::orUnknown($ticket['contact_email'] ?? null),
            'Version du site déclarée : ' . self::orUnknown($ticket['site_version'] ?? null),
            'Version de PHP déclarée  : ' . self::orUnknown($ticket['php_version'] ?? null),
            'Archive reçue le         : ' . self::orUnknown($ticket['archive_received_at'] ?? null),
            '',
            str_repeat('=', 72),
            '',
            'Description',
            '',
            trim((string) ($ticket['description'] ?? '')),
        ];

        $note = trim((string) ($ticket['resolution_note'] ?? ''));
        if ($note !== '') {
            $lines[] = '';
            $lines[] = str_repeat('=', 72);
            $lines[] = '';
            $lines[] = 'Note de résolution';
            $lines[] = '';
            $lines[] = $note;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function installationText(array $ticket): string
    {
        $installation = is_array($ticket['installation'] ?? null) ? $ticket['installation'] : [];

        if (($installation['public_id'] ?? null) === null) {
            return "L'enregistrement de cette installation n'existe plus : sa rétention l'a supprimé,\n"
                . "ou elle ne s'est jamais annoncée à ce receveur.\n";
        }

        $lines = [
            'Installation, aux dernières nouvelles',
            '',
            'Identifiant : ' . self::orUnknown($installation['public_id']),
            'Adresse     : ' . self::orUnknown($installation['instance_url'] ?? null),
            '',
        ];

        foreach (ReportedFacts::LABELS as $key => $label) {
            $lines[] = str_pad($label, 26) . ': ' . self::orUnknown($installation[$key]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The two readings side by side — the same rows the page shows, from
     * the same reader, so the file and the screen cannot disagree.
     *
     * @param array<string, mixed> $ticket
     */
    private static function comparisonText(array $ticket): string
    {
        $lines = [
            'Statistiques : au moment du ticket, et depuis',
            '',
            str_pad('', 26) . str_pad('Au ticket', 28) . 'Dernier rapport',
            str_repeat('-', 72),
        ];

        foreach (SupportTicketService::statisticsComparison($ticket) as $row) {
            $lines[] = str_pad((string) $row['label'], 26)
                . str_pad(self::orUnknown($row['at_ticket']), 28)
                . self::orUnknown($row['latest'])
                . ($row['changed'] === true ? '   <-- a changé' : '');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The last usage report as it was received, not as this module reads
     * it: a maintainer chasing a field the dashboard does not show needs
     * the document, and the derived facts are already in
     * `installation.txt`.
     *
     * @param array<string, mixed> $ticket
     */
    private function latestPayload(array $ticket): ?string
    {
        $row = $this->installations->findById((int) ($ticket['installation_id'] ?? 0));
        $payload = is_array($row) ? (string) ($row['payload'] ?? '') : '';
        if (trim($payload) === '') {
            return null;
        }

        // Pretty-printed when it parses, verbatim when it does not: a
        // payload this receiver cannot decode is exactly the one worth
        // handing over untouched.
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? self::json($decoded) : $payload;
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function archiveBytes(array $ticket): ?string
    {
        $fileId = $ticket['archive_file_id'] ?? null;
        if (!is_int($fileId) || $this->files === null) {
            return null;
        }

        $bytes = $this->files->read($fileId);

        return $bytes === null || $bytes === '' ? null : $bytes;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function orUnknown(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'non renseigné';
        }

        return is_scalar($value) ? (string) $value : 'non renseigné';
    }
}
