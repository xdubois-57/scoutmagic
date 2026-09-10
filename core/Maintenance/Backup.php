<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

class Backup
{
    /** @var string[] */
    public const TYPES = ['database', 'full_config', 'full_no_gallery', 'full_with_gallery', 'auto_update',
        'auto_reset', 'auto_backup'];

    /** @var string[] */
    public const STATUSES = ['pending', 'in_progress', 'completed', 'failed'];

    /**
     * Types whose archive carries the photo gallery.
     *
     * The one thing a cap has to know about a type beyond its family: a
     * gallery archive can weigh more than every other backup on the disk
     * put together, so exactly one of them is kept, across all families
     * ({@see BackupRetention}).
     *
     * @var string[]
     */
    public const GALLERY_TYPES = ['full_with_gallery'];

    /**
     * @param int|null $sizeBytes what the backup occupies on disk, both of
     *        its files together. Filled only by the queries that join
     *        `files` — {@see BackupRepository::findForList()} — and null
     *        everywhere else, because a purge does not need it and a
     *        second join on every read would be paid by callers that never
     *        look at it.
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
        public readonly ?int $sizeBytes = null
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
            default => $type,
        };
    }
}
