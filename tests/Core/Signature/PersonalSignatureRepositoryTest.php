<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Signature;

use Core\Security\EncryptionService;
use Core\Signature\PersonalSignatureRepository;
use Core\Signature\SignaturePng;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Each account's own signature (issue #926): replaced whole, never lost to a
 * replacement that fails, encrypted at rest, and never another account's.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PersonalSignatureRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PersonalSignatureRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new PersonalSignatureRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    private static function png(string $marker): SignaturePng
    {
        return new SignaturePng("\x89PNG\r\n\x1a\n" . $marker);
    }

    public function testAnAccountWithoutASignatureHasNone(): void
    {
        $this->assertNull($this->repository->find(7));
        $this->assertNull($this->repository->savedAt(7));
        $this->assertFalse($this->repository->delete(7));
    }

    public function testASignatureIsReplacedWholeAndDatedFromTheLastSave(): void
    {
        $this->repository->save(7, self::png('first'), new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->repository->save(7, self::png('second'), new \DateTimeImmutable('2026-10-21 09:30:00'));

        $this->assertSame(self::png('second')->bytes(), $this->repository->find(7)?->bytes());
        $this->assertSame('2026-10-21 09:30:00', $this->repository->savedAt(7)?->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM user_account_signatures')->fetchColumn());
    }

    /** Two accounts — a parent and a leader sharing a household's details — never read each other's. */
    public function testEachAccountReadsOnlyItsOwn(): void
    {
        $this->repository->save(7, self::png('seven'), new \DateTimeImmutable());
        $this->repository->save(8, self::png('eight'), new \DateTimeImmutable());

        $this->assertSame(self::png('seven')->bytes(), $this->repository->find(7)?->bytes());
        $this->assertSame(self::png('eight')->bytes(), $this->repository->find(8)?->bytes());

        $this->assertTrue($this->repository->delete(7));
        $this->assertNull($this->repository->find(7));
        $this->assertSame(self::png('eight')->bytes(), $this->repository->find(8)?->bytes());
    }

    /** What rests in the table is ciphertext: neither the bytes nor the PNG header are in clear. */
    public function testTheImageIsEncryptedAtRest(): void
    {
        $this->repository->save(7, self::png('VISIBLE-MARKER'), new \DateTimeImmutable());

        $stored = (string) $this->pdo->query('SELECT image_encrypted FROM user_account_signatures')->fetchColumn();

        $this->assertStringNotContainsString('VISIBLE-MARKER', $stored);
        $this->assertStringNotContainsString("\x89PNG", $stored);
    }

    /**
     * Encrypted under a context of its own: the same ciphertext read as
     * anything else decrypts to nothing.
     */
    public function testTheCiphertextIsBoundToItsContext(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository->save(7, self::png('bound'), new \DateTimeImmutable());
        $stored = (string) $this->pdo->query('SELECT image_encrypted FROM user_account_signatures')->fetchColumn();

        $this->expectException(\Core\Security\DecryptionException::class);
        $encryption->decrypt($stored, 'rental_manager_signature');
    }

    /**
     * A replacement that fails leaves the signature it was replacing — the
     * account is never left with none at all.
     */
    public function testAReplacementThatFailsKeepsTheSignatureItWasReplacing(): void
    {
        $this->repository->save(7, self::png('first'), new \DateTimeImmutable());
        $this->pdo->exec(
            'CREATE TRIGGER refuse_signature BEFORE INSERT ON user_account_signatures '
                . "BEGIN SELECT RAISE(ABORT, 'refused'); END"
        );

        try {
            $this->repository->save(7, self::png('second'), new \DateTimeImmutable());
            $this->fail('the insert was expected to fail');
        } catch (\PDOException) {
        }

        $this->assertSame(self::png('first')->bytes(), $this->repository->find(7)?->bytes());
    }

    /**
     * It opens no transaction of its own, so a caller that saves inside
     * one — signing a document and remembering the signature in the same
     * act — has its rollback undo the remembering.
     */
    public function testASaveInsideTheCallersTransactionIsUndoneByItsRollback(): void
    {
        $this->pdo->beginTransaction();
        $this->repository->save(7, self::png('rolled back'), new \DateTimeImmutable());
        $this->pdo->rollBack();

        $this->assertNull($this->repository->find(7));
    }
}
