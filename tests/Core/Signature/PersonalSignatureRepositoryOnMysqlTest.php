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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * An account's signature (issue #926) saved by the statement the production
 * engine runs.
 *
 * `PersonalSignatureRepository::save()` writes one upsert per dialect;
 * SQLite, the ordinary test engine, only ever runs its `ON CONFLICT`
 * spelling. This class runs the `ON DUPLICATE KEY UPDATE` one against the
 * production schema — its MEDIUMBLOB, its foreign key to `user_accounts` and
 * the cascade the RGPD page promises (« elle disparaît avec votre compte »).
 */
#[Group('database')]
final class PersonalSignatureRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private PersonalSignatureRepository $repository;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repository = new PersonalSignatureRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['chiffré', str_repeat('c', 64)]);
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    private static function png(string $marker): SignaturePng
    {
        return new SignaturePng("\x89PNG\r\n\x1a\n" . $marker);
    }

    public function testAFirstSignatureIsCreatedAndASecondReplacesItWhole(): void
    {
        $this->assertNull($this->repository->savedAt($this->accountId));

        $this->repository->save($this->accountId, self::png('first'), new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->repository->save($this->accountId, self::png('second'), new \DateTimeImmutable('2026-10-21 09:30:00'));

        $this->assertSame(self::png('second')->bytes(), $this->repository->find($this->accountId)?->bytes());
        $this->assertSame('2026-10-21 09:30:00', $this->repository->savedAt($this->accountId)?->format('Y-m-d H:i:s'));
        $this->assertTrue($this->repository->delete($this->accountId));
        $this->assertFalse($this->repository->delete($this->accountId));
        $this->assertNull($this->repository->find($this->accountId));
    }

    /**
     * A drawing near the ceiling fits the column: a 1200 × 400 PNG of noise
     * is far over the 64 KB a plain BLOB would hold, and its ciphertext has
     * to come back whole.
     */
    public function testALargeSignatureFitsTheColumn(): void
    {
        $image = imagecreatetruecolor(1200, 400);
        for ($i = 0; $i < 20000; $i++) {
            imagesetpixel($image, random_int(0, 1199), random_int(0, 399), random_int(0, 0xFFFFFF));
        }
        ob_start();
        imagepng($image, null, 0);
        $png = new SignaturePng((string) ob_get_clean());
        $this->assertGreaterThan(65535, $png->byteLength(), 'premise: the image is larger than a BLOB holds');

        $this->repository->save($this->accountId, $png, new \DateTimeImmutable());

        $this->assertSame($png->bytes(), $this->repository->find($this->accountId)?->bytes());
    }

    /**
     * The foreign key really cascades on this engine: an account erased by
     * hand takes its signature with it. The premise is asserted first — the
     * row exists before the account goes.
     */
    public function testErasingTheAccountErasesItsSignature(): void
    {
        $this->repository->save($this->accountId, self::png('cascade'), new \DateTimeImmutable());
        $this->assertNotNull($this->repository->find($this->accountId), 'premise: the signature was stored');

        $this->pdo->prepare('DELETE FROM user_accounts WHERE id = ?')->execute([$this->accountId]);

        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM user_account_signatures')->fetchColumn()
        );
    }

    /** A signature for an account that does not exist is refused by the foreign key. */
    public function testASignatureForNoAccountIsRefused(): void
    {
        $this->expectException(\PDOException::class);

        $this->repository->save($this->accountId + 1000, self::png('orphan'), new \DateTimeImmutable());
    }
}
