<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Signature;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Signature\PersonalSignatureRepository;
use Core\Signature\PersonalSignatureService;
use Core\Signature\SignatureImage;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The owner's side of a personal signature (issue #926): has, since when,
 * the image, save, delete, and the erasure at deactivation — each answering
 * about the account that asks and no other, each journaled without a pixel.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PersonalSignatureServiceTest extends TestCase
{
    private \PDO $pdo;
    private PersonalSignatureService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->service = new PersonalSignatureService(
            new PersonalSignatureRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private static function drawn(): \Core\Signature\SignaturePng
    {
        $image = imagecreatetruecolor(300, 100);
        ob_start();
        imagepng($image);

        return SignatureImage::fromBytes((string) ob_get_clean());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journal(): array
    {
        return $this->pdo->query('SELECT * FROM event_log ORDER BY id')->fetchAll();
    }

    public function testAnAccountStartsWithoutASignature(): void
    {
        $this->assertFalse($this->service->has(7));
        $this->assertNull($this->service->savedAt(7));
        $this->assertNull($this->service->ownImage(7));
    }

    public function testASavedSignatureIsTheOwnersAndOnlyTheirs(): void
    {
        $png = self::drawn();
        $at = new \DateTimeImmutable('2026-10-21 09:30:00');

        $this->service->save(7, $png, $at);

        $this->assertTrue($this->service->has(7));
        $this->assertSame('2026-10-21 09:30:00', $this->service->savedAt(7)?->format('Y-m-d H:i:s'));
        $this->assertSame($png->bytes(), $this->service->ownImage(7)?->bytes());
        $this->assertFalse($this->service->has(8));
        $this->assertNull($this->service->ownImage(8));
    }

    /** The journal records the act and the account, never the image nor its size. */
    public function testSavingIsJournaledWithoutTheImage(): void
    {
        $png = self::drawn();

        $this->service->save(7, $png, new \DateTimeImmutable());

        $entries = $this->journal();
        $this->assertCount(1, $entries);
        $this->assertSame('personal_signature_saved', $entries[0]['event_type']);
        $this->assertSame('security', $entries[0]['level']);
        $this->assertSame(7, (int) $entries[0]['user_account_id']);
        $this->assertSame(['user_account_id' => 7], json_decode((string) $entries[0]['context'], true));
        $this->assertStringNotContainsString(base64_encode($png->bytes()), json_encode($entries, JSON_THROW_ON_ERROR));
    }

    public function testDeletingRemovesTheSignatureAndJournalsItOnce(): void
    {
        $this->service->save(7, self::drawn(), new \DateTimeImmutable());

        $this->assertTrue($this->service->delete(7));
        $this->assertFalse($this->service->delete(7), 'deleting twice is not an error, and deletes nothing');

        $this->assertFalse($this->service->has(7));
        $types = array_column($this->journal(), 'event_type');
        $this->assertSame(['personal_signature_saved', 'personal_signature_deleted'], $types);
    }

    /** Another account deleting is a no-op on the owner's signature. */
    public function testAnotherAccountCannotDeleteIt(): void
    {
        $this->service->save(7, self::drawn(), new \DateTimeImmutable());

        $this->assertFalse($this->service->delete(8));

        $this->assertTrue($this->service->has(7));
    }

    public function testTheErasureAtDeactivationNamesTheActorAndTheAccount(): void
    {
        $this->service->save(7, self::drawn(), new \DateTimeImmutable());

        $this->assertTrue($this->service->eraseOnDeactivation(7, 3));
        $this->assertFalse($this->service->eraseOnDeactivation(7, 3));

        $this->assertFalse($this->service->has(7));
        $erasures = array_values(array_filter(
            $this->journal(),
            static fn (array $row): bool => $row['event_type'] === 'personal_signature_erased'
        ));
        $this->assertCount(1, $erasures);
        $this->assertSame(3, (int) $erasures[0]['user_account_id']);
        $this->assertSame(['user_account_id' => 7], json_decode((string) $erasures[0]['context'], true));
    }
}
