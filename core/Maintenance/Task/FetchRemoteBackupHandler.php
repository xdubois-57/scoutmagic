<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Maintenance\BackupException;
use Core\Maintenance\Portable\PortableArchive;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Remote\RemotePassphrase;
use Core\Maintenance\Remote\RemoteRetention;
use Core\Maintenance\RequesterNotice;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\SecretManager;
use Core\Storage\Location\Backend\RangeReadableBackend;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\StorageLocationRepository;

/**
 * Brings an off-site copy back to this server, then hands it to the
 * portable restore (#847, part 4).
 *
 * **In slices, over several runs**, the way the send writes it: each run
 * appends what {@see RangeReadableBackend::getRange()} returns to a file
 * under `storage/temp` for at most {@see TIME_BUDGET_SECONDS}, then queues
 * the next run with the same payload. Never `get()`, which would hold the
 * whole archive in memory.
 *
 * **The phrase is checked here, before the site is touched.** Once the
 * copy is whole and its size is the one listed, it is opened with the
 * phrase given — the one in force when none was typed. Right, the restore
 * is queued as for an uploaded file, which takes the safety copy and
 * keeps today's state (part 3). Wrong, nothing is modified and the copy
 * is **kept** ({@see heldRecordPath()}): the card offers to type the phrase
 * again without bringing it back again. It goes as soon as a restore
 * succeeds (the restore deletes its upload), as soon as another copy is
 * brought back, or 24 hours later at the latest (a run queued for then).
 */
class FetchRemoteBackupHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'fetch_remote_backup';
    public const REFERENCE = 'remote_copy';

    /** The same budget and slice as the send, for the same hosting. */
    public const TIME_BUDGET_SECONDS = SendRemoteBackupHandler::TIME_BUDGET_SECONDS;
    public const CHUNK_BYTES = SendRemoteBackupHandler::CHUNK_BYTES;

    /** How long a copy whose phrase was wrong is kept for another try. */
    public const HOLD_SECONDS = 86400;

    private const HELD_RECORD = 'remote_copy_held.json';

    public function __construct(
        private readonly ?RangeReadableBackend $backend = null,
        private readonly ?\Closure $now = null,
        private readonly int $chunkBytes = self::CHUNK_BYTES
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $requestedBy = isset($payload['requested_by_user_account_id'])
            ? (int) $payload['requested_by_user_account_id']
            : null;

        if (isset($payload['discard_path'])) {
            self::discardHeld($context->storagePath, (string) $payload['discard_path']);

            return;
        }

        $name = (string) ($payload['name'] ?? '');
        $size = (int) ($payload['size'] ?? 0);
        $path = (string) ($payload['path'] ?? '');
        if (!RemoteRetention::isArchive($name) || $size <= 0 || !self::isOurTempFile($context->storagePath, $path)) {
            $this->fail($context, $requestedBy, 'La copie demandée n\'est pas reconnue.', $path);

            return;
        }

        if (($payload['fetched'] ?? false) !== true) {
            try {
                $complete = $this->fetchSlices($payload, $context, $name, $size, $path);
            } catch (\Throwable $e) {
                $this->fail(
                    $context,
                    $requestedBy,
                    'La copie n\'a pas pu être rapatriée depuis la destination.',
                    $path
                );
                $context->journal->log(
                    'core',
                    'remote_copy_fetch_failed',
                    'warning',
                    'Rapatriement d\'une copie hors site interrompu',
                    ['error' => $e::class],
                    $requestedBy
                );

                return;
            }
            if (!$complete) {
                $this->scheduler($context)->scheduleAfter(
                    'core',
                    self::TASK_KEY,
                    0,
                    self::carried($payload),
                    self::REFERENCE,
                    $requestedBy
                );

                return;
            }
        }

        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) !== $size) {
            $this->fail($context, $requestedBy, 'La copie rapatriée n\'a pas la taille attendue.', $path);

            return;
        }

        $this->verifyAndRestore($payload, $context, $requestedBy, $name, $path);
    }

    /**
     * The record of a copy kept after a wrong phrase, or null — read by the
     * card, which offers to type the phrase again.
     *
     * @return array{path: string, name: string, at: int}|null
     */
    public static function held(string $storagePath): ?array
    {
        $raw = @file_get_contents(self::heldRecordPath($storagePath));
        $record = $raw === false ? null : json_decode($raw, true);
        if (!is_array($record) || !is_string($record['path'] ?? null) || !is_string($record['name'] ?? null)) {
            return null;
        }
        if (!is_file($record['path']) || (int) ($record['at'] ?? 0) < time() - self::HOLD_SECONDS) {
            return null;
        }

        return ['path' => $record['path'], 'name' => $record['name'], 'at' => (int) $record['at']];
    }

    /**
     * Whether a kept copy is still on the disk past its hold — what
     * {@see \Core\Alert\Check\PortableBackupLingerCheck} reports.
     */
    public static function heldOverdue(string $storagePath, int $now): bool
    {
        $raw = @file_get_contents(self::heldRecordPath($storagePath));
        $record = $raw === false ? null : json_decode($raw, true);

        return is_array($record) && is_string($record['path'] ?? null) && is_file($record['path'])
            && (int) ($record['at'] ?? 0) < $now - self::HOLD_SECONDS;
    }

    /** Deletes a kept copy and its record — another fetch starts, or the hold ran out. */
    public static function discardHeld(string $storagePath, ?string $onlyPath = null): void
    {
        $raw = @file_get_contents(self::heldRecordPath($storagePath));
        $record = $raw === false ? null : json_decode($raw, true);
        $path = is_array($record) && is_string($record['path'] ?? null) ? $record['path'] : null;
        if ($onlyPath !== null && $path !== null && $path !== $onlyPath) {
            // A newer copy is held: the run queued for the old one has
            // nothing left to do but remove that one's file, if any.
            if (self::isOurTempFile($storagePath, $onlyPath)) {
                @unlink($onlyPath);
            }

            return;
        }
        if ($path !== null && self::isOurTempFile($storagePath, $path)) {
            @unlink($path);
        }
        if ($onlyPath !== null && self::isOurTempFile($storagePath, $onlyPath)) {
            @unlink($onlyPath);
        }
        @unlink(self::heldRecordPath($storagePath));
    }

    /** A fresh file for a copy to be brought back into, under `storage/temp`. */
    public static function newTempPath(string $storagePath): string
    {
        return rtrim($storagePath, '/') . '/temp/remote_copy_' . bin2hex(random_bytes(8)) . '.zip';
    }

    /** Whether a path is one {@see newTempPath()} hands out — never anything else is read or deleted. */
    public static function isOurTempFile(string $storagePath, string $path): bool
    {
        $directory = rtrim($storagePath, '/') . '/temp/';

        return str_starts_with($path, $directory)
            && preg_match('/^remote_copy_[0-9a-f]{16}\.zip$/', substr($path, strlen($directory))) === 1;
    }

    /**
     * Appends slices until the copy is whole or the budget is spent.
     *
     * @param array<string, mixed> $payload
     * @return bool whether the copy is whole
     */
    private function fetchSlices(array $payload, TaskContext $context, string $name, int $size, string $path): bool
    {
        $backend = $this->backend ?? $this->backendFor($context, (int) ($payload['location_id'] ?? 0));
        if ($backend === null) {
            throw new \RuntimeException('No range-readable backend for this destination.');
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The temporary directory cannot be created.');
        }
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException('The temporary file cannot be opened.');
        }
        try {
            @chmod($path, 0600);
            clearstatcache(true, $path);
            $offset = (int) filesize($path);
            $started = $this->now();
            while ($offset < $size && $this->now() - $started < self::TIME_BUDGET_SECONDS) {
                $slice = $backend->getRange($name, $offset, min($this->chunkBytes, $size - $offset));
                if ($slice === '') {
                    throw new \RuntimeException('The destination returned nothing before the end of the copy.');
                }
                if (fwrite($handle, $slice) !== strlen($slice)) {
                    throw new \RuntimeException('The temporary file cannot be written.');
                }
                $offset += strlen($slice);
            }
        } finally {
            fclose($handle);
        }

        return $offset >= $size;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function verifyAndRestore(
        array $payload,
        TaskContext $context,
        ?int $requestedBy,
        string $name,
        string $path
    ): void {
        try {
            $phrase = $this->phraseOf($payload, $context);
            PortableArchive::open($path, $phrase)->close();
        } catch (BackupException | \RuntimeException $e) {
            // Nothing was modified. The copy stays for another try.
            @file_put_contents(
                self::heldRecordPath($context->storagePath),
                (string) json_encode(['path' => $path, 'name' => $name, 'at' => time()])
            );
            @chmod(self::heldRecordPath($context->storagePath), 0600);
            $this->scheduler($context)->scheduleAfter(
                'core',
                self::TASK_KEY,
                self::HOLD_SECONDS,
                ['discard_path' => $path],
                null,
                $requestedBy
            );
            $context->journal->log(
                'core',
                'remote_copy_wrong_passphrase',
                'warning',
                'Copie hors site rapatriée, mais la phrase de passe ne l\'ouvre pas : rien n\'a été modifié',
                [],
                $requestedBy
            );
            RequesterNotice::sendAboutRestore(
                $context,
                $requestedBy,
                'core.restore_failed',
                'Restauration non lancée',
                'La phrase de passe ne correspond pas à cette copie hors site. Rien n\'a été modifié : '
                . 'ressaisissez-la dans « Copies hors site », sans rapatrier la copie à nouveau.'
            );

            return;
        }

        @unlink(self::heldRecordPath($context->storagePath));
        $this->scheduler($context)->scheduleAfter(
            'core',
            'restore_backup',
            0,
            [
                'source' => 'upload',
                'uploaded_temp_path' => $path,
                'encrypted_password' => base64_encode($context->encryption->encrypt($phrase, 'backup_password')),
                'remote_copy' => $name,
            ],
            null,
            $requestedBy
        );
        $context->journal->log(
            'core',
            'remote_copy_fetched',
            'info',
            'Copie hors site rapatriée : la restauration commence',
            [],
            $requestedBy
        );
    }

    /**
     * The phrase typed for this copy, or the one in force.
     *
     * @param array<string, mixed> $payload
     */
    private function phraseOf(array $payload, TaskContext $context): string
    {
        $encrypted = (string) ($payload['encrypted_password'] ?? '');
        if ($encrypted !== '') {
            $raw = base64_decode($encrypted, true);
            if ($raw === false) {
                throw new BackupException('La phrase de passe transmise est illisible.');
            }

            return $context->encryption->decrypt($raw, 'backup_password');
        }

        $phrase = (new RemotePassphrase(
            $context->settings,
            new SecretManager($context->storagePath . '/keys/master.key', $context->storagePath . '/config/secrets.enc')
        ))->stored();
        if ($phrase === '') {
            throw new BackupException('Aucune phrase de passe n\'est enregistrée sur ce site.');
        }

        return $phrase;
    }

    private function fail(TaskContext $context, ?int $requestedBy, string $message, string $path): void
    {
        if (self::isOurTempFile($context->storagePath, $path)) {
            @unlink($path);
        }
        RequesterNotice::sendAboutRestore(
            $context,
            $requestedBy,
            'core.restore_failed',
            'Restauration non lancée',
            $message . ' Rien n\'a été modifié.'
        );
    }

    /**
     * What the next run carries: the same request, nothing else.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function carried(array $payload): array
    {
        return array_intersect_key(
            $payload,
            array_flip(['name', 'size', 'path', 'location_id', 'encrypted_password'])
        );
    }

    private function backendFor(TaskContext $context, int $locationId): ?RangeReadableBackend
    {
        $repository = new StorageLocationRepository($context->connection->getPdo(), $context->encryption);
        $location = $locationId > 0 ? $repository->findById($locationId) : null;
        if ($location === null) {
            return null;
        }
        $backend = (new StorageBackendFactory($repository, $context->storagePath))->create($location);

        return $backend instanceof RangeReadableBackend ? $backend : null;
    }

    private function scheduler(TaskContext $context): SchedulerService
    {
        return new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
    }

    private function now(): float
    {
        return $this->now !== null ? (float) ($this->now)() : microtime(true);
    }

    private static function heldRecordPath(string $storagePath): string
    {
        return rtrim($storagePath, '/') . '/temp/' . self::HELD_RECORD;
    }
}
