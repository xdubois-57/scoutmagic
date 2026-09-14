<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

class Backup
{
    /**
     * The one type whose archive carries the site's own keys.
     *
     * Named rather than spelled, because three layers have to agree on it
     * — the controller that creates the row, the handler that dispatches
     * on it, and the family that gives it a quota of one — and a literal
     * `'portable'` in each is three places for a typo to become a backup
     * nothing purges.
     */
    public const PORTABLE_TYPE = 'portable';

    /**
     * Every value `backups.type` can hold — which is not the same as
     * every value this code still WRITES.
     *
     * **`full_with_gallery` is here and is no longer produced.** D10 took
     * every declared storage location out of every archive, so a scope
     * promising one was a scope that could not keep its promise; the
     * Maintenance page stopped offering it and `createFullBackup()`
     * stopped accepting it. The value stays readable because rows
     * written before that change still carry it, and because narrowing
     * the column is not available to us: this schema is applied by a
     * pure differ ({@see \Core\Database\MigrationRunner}) with no
     * data-migration step, and under `STRICT_TRANS_TABLES` an `ALTER`
     * dropping a value some row still holds is refused outright — the
     * migration would then retry, abandon, and report itself broken on
     * the Maintenance page of a site whose only fault was having taken a
     * backup. Re-labelling those rows would be worse than keeping them:
     * a `full_with_gallery` archive really does contain the photographs,
     * and calling it `full_no_gallery` would lie to whoever restores it.
     *
     * @var string[]
     */
    public const TYPES = ['database', 'full_config', 'full_no_gallery', 'full_with_gallery', 'auto_update',
        'auto_reset', 'auto_backup', self::PORTABLE_TYPE];

    /** @var string[] */
    public const STATUSES = ['pending', 'in_progress', 'completed', 'failed'];


    /**
     * @param int|null $sizeBytes what the backup occupies on disk, both of
     *        its files together. Filled only by the queries that join
     *        `files` — {@see BackupRepository::findForList()} — and null
     *        everywhere else, because a purge does not need it and a
     *        second join on every read would be paid by callers that never
     *        look at it.
     * @param string|null $archiveSha256 what each file hashed to when the
     * @param string|null $dbDumpSha256  backup completed, so that a pass
     *        can later ask whether it still does ({@see BackupIntegrity}).
     *        Null on a backup taken before that existed — which is not the
     *        same as a mismatch and is never reported as one.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly ?int $fileId,
        public readonly ?int $dbDumpFileId,
        public readonly string $status,
        public readonly ?int $requestedBy,
        public readonly ?string $errorMessage,
        public readonly string $createdAt,
        public readonly ?string $completedAt,
        public readonly ?int $sizeBytes = null,
        public readonly ?string $archiveSha256 = null,
        public readonly ?string $dbDumpSha256 = null,
        public readonly BackupIntegrityStatus $integrityStatus = BackupIntegrityStatus::Unknown,
        public readonly ?string $integrityCheckedAt = null
    ) {
    }

    /**
     * What a type is called on screen.
     *
     * In PHP rather than in the Twig macro it replaces, for the reason the
     * maintenance template already gives about the disk block: a choice
     * made in a template is a choice nobody can test. Two surfaces need
     * this same string — the row in « Sauvegardes récentes » and the
     * deletion confirmation that has to name what it is about to destroy —
     * and two spellings of « Complète (sans galerie) » is how a
     * confirmation stops matching the row somebody clicked.
     */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'database' => 'Base de données',
            'full_config' => 'Configuration seule',
            'full_no_gallery' => 'Complète (sans galerie)',
            'full_with_gallery' => 'Complète (avec galerie)',
            'auto_update' => 'Avant mise à jour',
            'auto_reset' => 'Avant réinitialisation',
            'auto_backup' => 'Planifiée',
            self::PORTABLE_TYPE => 'Portable',
            default => $type,
        };
    }
}
