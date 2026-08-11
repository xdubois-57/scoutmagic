<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * Extracted purely so Task\FullResetHandler (the highest-risk handler in
 * this codebase — it truncates every table) can be unit-tested against a
 * fake that never touches the database dump library or shells out to
 * `mysql`, exercising its real truncate/wipe/preserve-safety-backup logic
 * without needing a live MySQL server. BackupService is still constructed
 * directly (no interface) by every other caller — this is not a general DI
 * abstraction.
 */
interface BackupServiceInterface
{
    public function createDatabaseDump(): string;

    public function createConfigOnlyDump(): string;

    public function createFileBackup(bool $includeGallery = false): string;

    /**
     * @return array{zipPath: string, dbDumpPath: string}
     */
    public function createFullBackup(string $scope, string $password): array;

    public function supportsZipEncryption(): bool;

    public function restoreDatabase(string $dumpPath): void;

    public function restoreFiles(string $archivePath, ?string $password = null): void;
}
