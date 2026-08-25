<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Service\DateInput;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use Core\Support\SupportSpreadsheet;

/**
 * `update-history.xlsx` — which version was running when, and what each
 * attempt to change that did.
 *
 * The question this answers is "was the site on the code I think it was?",
 * and until this collector existed the archive could not answer it. The
 * installed version was in `statistics.json` as a single value — what is
 * running NOW — while the interesting moment is always earlier: an error
 * logged at 19:57 belongs to whatever was installed at 19:57, which on an
 * installation that auto-updates may be four versions ago. That answer had
 * to be reconstructed by hand from `update_installed` rows scattered
 * through the event journal, on an archive where 46 of them were competing
 * with 884 other entries.
 *
 * A version timeline also makes a whole class of report readable at a
 * glance. "It broke this afternoon" next to an update installed that
 * afternoon is a different investigation from the same words with no
 * update for a week. And a `rolled_back` or `failed` row is worth seeing
 * even when the site is healthy now: an update that failed and restored
 * itself leaves no other trace a reader would notice.
 *
 * Bounded like every other collector here (MAX_ROWS): an installation on
 * the development channel installs on every push, so "all of them" is not
 * a size anyone can predict. Newest first, because that is where an
 * investigation starts, and the count of what was dropped is stated rather
 * than left to be inferred.
 */
class UpdateHistoryCollector implements SupportCollectorInterface
{
    /**
     * Enough to cover a week of the development channel's push-driven
     * installs, which is far more than a stable installation produces in a
     * year.
     */
    public const MAX_ROWS = 200;

    private const ERROR_MAX_LENGTH = 300;

    public function name(): string
    {
        return 'update_history';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $stmt = $context->pdo()->prepare(
            'SELECT id, version_from, version_to, status, dependencies_changed,
                    error_message, backup_id, started_at, completed_at
             FROM update_history
             ORDER BY started_at DESC, id DESC
             LIMIT ' . (self::MAX_ROWS + 1)
        );
        $stmt->execute();
        $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $truncated = count($entries) > self::MAX_ROWS;
        if ($truncated) {
            $entries = array_slice($entries, 0, self::MAX_ROWS);
            $context->addNote(
                'Historique tronqué aux ' . self::MAX_ROWS . ' installations les plus récentes'
            );
        }

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                self::localTimestamp(self::asString($entry['started_at'] ?? null)),
                self::localTimestamp(self::asString($entry['completed_at'] ?? null)),
                self::duration($entry['started_at'] ?? null, $entry['completed_at'] ?? null),
                self::asString($entry['version_from'] ?? null),
                self::asString($entry['version_to'] ?? null),
                self::asString($entry['status'] ?? null),
                ((int) ($entry['dependencies_changed'] ?? 0)) === 1 ? 'oui' : 'non',
                // A failure message quotes whatever threw, and a PDO error
                // routinely quotes the credentials it failed with — so it
                // goes through the same redaction as every other free text
                // in this archive.
                $context->redact(self::asString($entry['error_message'] ?? null), self::ERROR_MAX_LENGTH),
                self::asString($entry['backup_id'] ?? null),
            ];
        }

        $context->addNote(count($rows) . ' installation(s) enregistrée(s)');
        $context->addFileFromContent(
            'update-history.xlsx',
            SupportSpreadsheet::build(
                [
                    'Démarrée le (heure locale du serveur)',
                    'Terminée le',
                    'Durée',
                    'Version de départ',
                    'Version installée',
                    'Statut',
                    'Dépendances modifiées',
                    'Erreur',
                    'Sauvegarde de sécurité',
                ],
                $rows,
                'Mises à jour'
            )
        );
    }

    /**
     * How long an install took, which is the column that says whether one
     * is genuinely stuck: a row still `migrating` an hour after it started
     * is a different problem from one that finished in six seconds.
     */
    private static function duration(mixed $startedAt, mixed $completedAt): string
    {
        if (!is_string($startedAt) || !is_string($completedAt) || $startedAt === '' || $completedAt === '') {
            return '';
        }

        $finished = DateInput::fromStorage($completedAt);
        $began = DateInput::fromStorage($startedAt);
        if ($finished === null || $began === null) {
            return '';
        }

        $seconds = $finished->getTimestamp() - $began->getTimestamp();

        if ($seconds < 0) {
            return '';
        }

        return $seconds < 60
            ? $seconds . ' s'
            : intdiv($seconds, 60) . ' min ' . ($seconds % 60) . ' s';
    }

    /**
     * Same rule as the event journal's: a DB `DATETIME` carries no zone,
     * and lining these up against a server log is the entire point of the
     * sheet, so each cell says which clock it is on.
     */
    private static function localTimestamp(string $storedValue): string
    {
        if ($storedValue === '') {
            return '';
        }

        return DateInput::fromStorage($storedValue)?->format('Y-m-d H:i:sP') ?? $storedValue;
    }

    private static function asString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
