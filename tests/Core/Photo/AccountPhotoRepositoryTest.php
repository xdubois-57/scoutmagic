<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\Photo\AccountPhotoRepository;
use Core\Photo\PersonPhotoOwnershipChecker;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #1108: an account's photo file is stamped with the account that
 * owns it, so its original is served only to that account and to chief+
 * (Core\Photo\PersonPhotoOwnershipChecker).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class AccountPhotoRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private AccountPhotoRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new AccountPhotoRepository($this->pdo);
    }

    public function testSettingAPhotoMakesTheAccountItsFilesOwner(): void
    {
        $account = $this->account('a@test.com');
        $first = $this->file('a.jpg');
        $second = $this->file('b.jpg');

        $this->repository->upsert($account, $first);
        $this->assertSame([PersonPhotoOwnershipChecker::OWNER_ACCOUNT, $account], $this->ownerOf($first));

        $this->repository->upsert($account, $second);
        $this->assertSame([PersonPhotoOwnershipChecker::OWNER_ACCOUNT, $account], $this->ownerOf($second));
    }

    public function testBackfillStampsEveryUnownedAccountPhotoAndIsAReplayableNoOp(): void
    {
        $account = $this->account('a@test.com');
        $photo = $this->file('a.jpg');
        $this->pdo->prepare('INSERT INTO user_account_photos (user_account_id, file_id) VALUES (?, ?)')
            ->execute([$account, $photo]);
        $unrelated = $this->file('logo.jpg');

        $this->assertSame(1, $this->repository->backfillFileOwnership());

        $this->assertSame([PersonPhotoOwnershipChecker::OWNER_ACCOUNT, $account], $this->ownerOf($photo));
        $this->assertSame([null, null], $this->ownerOf($unrelated));
        $this->assertSame(0, $this->repository->backfillFileOwnership());
    }

    private function account(string $email): int
    {
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['x', hash('sha256', $email)]);

        return (int) $this->pdo->lastInsertId();
    }

    private function file(string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES (?, ?, 'image/jpeg', 100)"
        )->execute(['core/account_photos/' . $name, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0: ?string, 1: ?int} */
    private function ownerOf(int $fileId): array
    {
        $stmt = $this->pdo->prepare('SELECT owner_type, owner_id FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [$row['owner_type'] ?? null, $row['owner_id'] === null ? null : (int) $row['owner_id']];
    }
}
