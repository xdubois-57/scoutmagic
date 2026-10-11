<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Restore;

use Core\Security\EncryptionService;
use Core\Storage\Location\Restore\StorageBindingReconciler;
use Core\Storage\Location\Restore\StorageBindingRepository;
use Core\Storage\Location\Restore\StorageBindingSnapshot;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Today's storage bindings, put back over a restored backup's (#832).
 *
 * Each test plays the restore by hand: a snapshot of « before », then the
 * table rewritten to what « the backup » holds, then the reconciliation.
 * The restore itself — dump, migration, the scheduler pass — is
 * RestoreBackupRoundTripTest's, against MariaDB.
 *
 * @group database
 */
#[Group('database')]
final class StorageBindingReconcilerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $keys;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->keys = new EncryptionService(str_repeat('k', 32), str_repeat('b', 32));
    }

    /**
     * The case of the ticket: the Drive folder changed after the backup,
     * the files are in the new one, and the backup brings back the old
     * id. Today's binding — folder, grant, label — wins.
     */
    public function testALocationChangedAfterTheBackupKeepsTodaysBinding(): void
    {
        $this->insert(2, 'google_drive', 'Drive', $this->drive('dossier-actuel'), 'grant-actuel', default: true);
        $before = $this->snapshot();

        $this->replaceWithBackup([[2, 'google_drive', 'Drive (ancien)', $this->drive('dossier-ancien'), 'grant-ancien', true]]);
        $this->pdo->exec("UPDATE storage_locations SET last_check_ok = 0, last_check_error = 'Dossier introuvable'");

        $report = $this->reconciler()->reconcile($before, true);

        $row = $this->row(2);
        $this->assertSame('Drive', $row['label']);
        $this->assertStringContainsString('dossier-actuel', (string) $row['config']);
        $this->assertSame('grant-actuel', $this->keys->decrypt((string) $row['secret_encrypted'], 'storage_locations.secret'));
        $this->assertNull($row['reconciliation_note']);
        $this->assertNull($row['last_check_ok'], 'the health measured against the old binding must not be kept');
        $this->assertSame(1, (int) $row['is_default'], 'which location is the default is the backup\'s decision');
        $this->assertCount(1, $report);
        $this->assertStringContainsString('« Drive »', $report[0]);
    }

    /** Nothing changed: nothing is touched, and nothing is said. */
    public function testALocationThatDidNotChangeIsLeftAlone(): void
    {
        $this->insert(1, 'local', 'Disque du serveur', '{"path":"gallery"}', null, default: true);
        $before = $this->snapshot();
        $this->pdo->exec("UPDATE storage_locations SET last_check_ok = 1");

        $report = $this->reconciler()->reconcile($before, true);

        $this->assertSame([], $report);
        $this->assertSame(1, (int) $this->row(1)['last_check_ok']);
    }

    /**
     * Created after the backup was taken: data written there since must
     * stay reachable. Put back under its id, never as the default.
     */
    public function testALocationCreatedAfterTheBackupIsPutBack(): void
    {
        $this->insert(1, 'local', 'Disque du serveur', '{"path":"gallery"}', null, default: true);
        $this->insert(5, 's3', 'Bucket', '{"bucket":"photos"}', 'cle-s3');
        $before = $this->snapshot();

        $this->replaceWithBackup([[1, 'local', 'Disque du serveur', '{"path":"gallery"}', null, true]]);

        $this->reconciler()->reconcile($before, true);

        $row = $this->row(5);
        $this->assertSame('Bucket', $row['label']);
        $this->assertSame(0, (int) $row['is_default']);
        $this->assertSame('cle-s3', $this->keys->decrypt((string) $row['secret_encrypted'], 'storage_locations.secret'));
        $this->assertSame(StorageBindingReconciler::NOTE_KEPT_BESIDE, $row['reconciliation_note']);
    }

    /**
     * Deleted since, as far as the site knew — but the backup still has
     * it, and something restored may stand on it. Kept as the backup has
     * it, and flagged: its target was not checked.
     */
    public function testALocationOnlyTheBackupKnowsIsKeptAndFlagged(): void
    {
        $this->insert(1, 'local', 'Disque du serveur', '{"path":"gallery"}', null, default: true);
        $before = $this->snapshot();

        $this->replaceWithBackup([
            [1, 'local', 'Disque du serveur', '{"path":"gallery"}', null, true],
            [3, 'webdav', 'Partage', '{"url":"https://dav.example.org/scout"}', 'mdp', false],
        ]);

        $report = $this->reconciler()->reconcile($before, true);

        $row = $this->row(3);
        $this->assertStringContainsString('dav.example.org', (string) $row['config'], 'nothing is rebound to a guess');
        $this->assertSame(StorageBindingReconciler::NOTE_FROM_BACKUP, $row['reconciliation_note']);
        $this->assertSame(['« Partage » : venu de la sauvegarde, cible non vérifiée.'], $report);
    }

    /**
     * A portable restore installs the archive's keys: today's credential,
     * encrypted with the old ones, no longer opens. Today's binding is
     * kept, with the backup's credential, and the location asks for it.
     */
    public function testACredentialTheRestoredKeysCannotReadIsAskedForAgain(): void
    {
        $oldKeys = new EncryptionService(str_repeat('o', 32), str_repeat('b', 32));
        $this->pdo->prepare(
            "INSERT INTO storage_locations (id, type, label, is_default, config, secret_encrypted)
             VALUES (2, 'google_drive', 'Drive', 1, ?, ?)"
        )->execute([$this->drive('dossier-actuel'), $oldKeys->encrypt('grant-actuel', 'storage_locations.secret')]);
        $before = $this->snapshot();

        $this->replaceWithBackup([[2, 'google_drive', 'Drive', $this->drive('dossier-ancien'), 'grant-ancien', true]]);

        $this->reconciler()->reconcile($before, true);

        $row = $this->row(2);
        $this->assertStringContainsString('dossier-actuel', (string) $row['config']);
        $this->assertSame('grant-ancien', $this->keys->decrypt((string) $row['secret_encrypted'], 'storage_locations.secret'));
        $this->assertSame(StorageBindingReconciler::NOTE_SECRET_UNREADABLE, $row['reconciliation_note']);
    }

    /**
     * A portable archive from ANOTHER installation: its location 2 is not
     * this site's location 2. Nothing is matched — today's are put back
     * beside the archive's, under new ids and names where they collide,
     * and the archive's are all flagged.
     */
    public function testAnotherInstallationsLocationsAreNeverTakenForThisOnes(): void
    {
        $this->insert(1, 'local', 'Disque du serveur', '{"path":"gallery"}', null, default: true);
        $this->insert(2, 'google_drive', 'Drive', $this->drive('dossier-ici'), 'grant-ici');
        $before = $this->snapshot();

        $this->replaceWithBackup([
            [1, 'local', 'Disque du serveur', '{"path":"gallery"}', null, true],
            [2, 'google_drive', 'Drive', $this->drive('dossier-la-bas'), 'grant-la-bas', false],
        ]);

        $this->reconciler()->reconcile($before, false);

        $this->assertStringContainsString('dossier-la-bas', (string) $this->row(2)['config']);
        $this->assertSame(StorageBindingReconciler::NOTE_FROM_BACKUP, $this->row(2)['reconciliation_note']);

        $kept = $this->pdo->query(
            "SELECT * FROM storage_locations WHERE config LIKE '%dossier-ici%'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($kept);
        $this->assertNotSame(2, (int) $kept['id']);
        $this->assertSame('Drive (avant restauration)', $kept['label']);
        $this->assertSame(0, (int) $kept['is_default']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM storage_locations WHERE is_default = 1')->fetchColumn());
    }

    /**
     * Today has more locations than the archive: the one put back under a
     * new id must not take the id another one is about to be put back
     * under — the next id of the table is exactly that one.
     */
    public function testANewIdNeverTakesTheIdAnotherLocationKeeps(): void
    {
        $this->insert(1, 'local', 'Disque du serveur', '{"path":"gallery"}', null, default: true);
        $this->insert(2, 'google_drive', 'Drive', $this->drive('dossier-ici'), 'grant-ici');
        $before = $this->snapshot();

        $this->replaceWithBackup([[1, 'local', 'Disque de là-bas', '{"path":"ailleurs"}', null, true]]);

        $this->reconciler()->reconcile($before, false);

        $this->assertStringContainsString('dossier-ici', (string) $this->row(2)['config']);
        $this->assertStringContainsString('ailleurs', (string) $this->row(1)['config']);
        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM storage_locations WHERE config LIKE '%gallery%'"
        )->fetchColumn());
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM storage_locations')->fetchColumn());
    }

    /** The same id holding another kind of storage is another location. */
    public function testTheSameIdOfAnotherKindIsNotTheSameLocation(): void
    {
        $this->insert(2, 's3', 'Bucket', '{"bucket":"photos"}', 'cle');
        $before = $this->snapshot();

        $this->replaceWithBackup([[2, 'local', 'Ancien disque', '{"path":"vieux"}', null, true]]);

        $this->reconciler()->reconcile($before, true);

        $this->assertSame('local', $this->row(2)['type']);
        $this->assertSame(StorageBindingReconciler::NOTE_FROM_BACKUP, $this->row(2)['reconciliation_note']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM storage_locations WHERE type = 's3'")->fetchColumn());
    }

    /**
     * The snapshot crosses processes on disk: what goes in comes out, the
     * ciphertext's bytes included, and the file is the owner's alone.
     */
    public function testTheSnapshotHeldAsideReadsBackAsItWasWritten(): void
    {
        $this->insert(2, 'google_drive', 'Drive', $this->drive('dossier'), 'grant');
        $snapshot = $this->snapshot();
        $directory = sys_get_temp_dir() . '/scoutmagic_bindings_' . bin2hex(random_bytes(4));

        $path = $snapshot->holdAside($directory);
        $read = StorageBindingSnapshot::readHeldAside($path);

        $this->assertNotNull($read);
        $this->assertSame($snapshot->rows, $read->rows);
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        }
        $this->assertStringNotContainsString('grant', (string) file_get_contents($path), 'nothing readable on the disk');

        @unlink($path);
        @rmdir($directory . '/temp');
        @rmdir($directory);
    }

    public function testAMissingOrForeignFileIsNoSnapshot(): void
    {
        $this->assertNull(StorageBindingSnapshot::readHeldAside('/nulle/part.json'));
    }

    private function reconciler(): StorageBindingReconciler
    {
        return new StorageBindingReconciler(new StorageBindingRepository($this->pdo), $this->keys);
    }

    private function snapshot(): StorageBindingSnapshot
    {
        return StorageBindingSnapshot::capture(new StorageBindingRepository($this->pdo));
    }

    private function drive(string $folder): string
    {
        return (string) json_encode(['client_id' => 'c', 'folder_id' => $folder, 'connected_at' => '2026-09-01']);
    }

    private function insert(int $id, string $type, string $label, string $config, ?string $secret, bool $default = false): void
    {
        $this->pdo->prepare(
            'INSERT INTO storage_locations (id, type, label, is_default, config, secret_encrypted) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $type,
            $label,
            $default ? 1 : 0,
            $config,
            $secret === null ? null : $this->keys->encrypt($secret, 'storage_locations.secret'),
        ]);
    }

    /**
     * What the restore leaves before the reconciliation: the backup's
     * rows, and nothing else.
     *
     * @param list<array{0: int, 1: string, 2: string, 3: string, 4: ?string, 5: bool}> $rows
     */
    private function replaceWithBackup(array $rows): void
    {
        $this->pdo->exec('DELETE FROM storage_locations');
        // A restore recreates the table, so its next id starts again from
        // what the backup holds — not from what this site had counted to.
        $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'storage_locations'");
        foreach ($rows as [$id, $type, $label, $config, $secret, $default]) {
            $this->insert($id, $type, $label, $config, $secret, $default);
        }
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM storage_locations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "no location {$id}");

        return $row;
    }
}
