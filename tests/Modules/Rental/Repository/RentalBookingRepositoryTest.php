<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalBookingRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Two answers of the booking repository that a stored row decides: a
 * tracking token that no longer decrypts, and an address the booking
 * already has.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalBookingRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RentalBookingRepository $repository;
    private int $bookingId;
    private string $token;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->repository = new RentalBookingRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        $this->pdo->exec("INSERT INTO rental_assets (asset_type, name, slug) VALUES ('Local', 'Local', 'local')");
        $created = $this->repository->create(
            (int) $this->pdo->lastInsertId(),
            'LOC-A2B3C4',
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => null,
                'organisation' => null,
                'purpose' => null,
                'comment' => null,
            ],
            null,
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );
        $this->bookingId = $created['id'];
        $this->token = $created['tracking_token'];
    }

    /**
     * A token that no longer decrypts — a key rotated without
     * re-encrypting, a corrupted row — opens nothing and hands nothing
     * out: the renter is given a fresh link, never an error page.
     */
    public function testATrackingTokenThatNoLongerDecryptsOpensNothing(): void
    {
        $this->assertTrue($this->repository->verifyTrackingToken($this->bookingId, $this->token), 'the premise');

        $this->pdo->prepare('UPDATE rental_bookings SET tracking_token_encrypted = ? WHERE id = ?')
            ->execute([base64_encode(random_bytes(48)), $this->bookingId]);

        $this->assertNull($this->repository->trackingTokenOf($this->bookingId));
        $this->assertFalse($this->repository->verifyTrackingToken($this->bookingId, $this->token));
    }

    /**
     * An address the booking already has is not added twice, however it
     * is written — and the first one keeps the message that taught it, so
     * « Détacher » that message still takes back the right address.
     */
    public function testAnAddressTheBookingAlreadyHasIsNotAddedTwice(): void
    {
        $this->assertTrue($this->repository->addRenterEmail($this->bookingId, 'tresorier@groupe.example', 9));

        $this->assertFalse($this->repository->addRenterEmail($this->bookingId, ' Tresorier@Groupe.example ', 12));

        $others = $this->repository->otherRenterEmails($this->bookingId);
        $this->assertCount(1, $others);
        $this->assertSame(9, $others[0]->learnedFromMessageId);
    }

    /**
     * Only a unique-key violation — the address is already there — answers
     * « not new » (#1221). A write the database refuses for any other reason
     * is not a duplicate: it used to be taken for one, so the address
     * learned from a filed message was lost without a word. Its neighbours
     * (the milestone marks, the mail reads) already tell the two apart by
     * the SQLSTATE.
     */
    public function testAWriteTheDatabaseRefusesForAnotherReasonIsNotTakenForADuplicate(): void
    {
        $this->makeTheInsertFailWith(new \PDOException('SQLSTATE[HY000]: General error: server has gone away'));

        $this->expectException(\PDOException::class);
        $this->repository->addRenterEmail($this->bookingId, 'tresorier@groupe.example', 9);
    }

    /** …and a unique-key violation is still the one thing that answers « not new ». */
    public function testAUniqueKeyViolationIsStillADuplicate(): void
    {
        $this->makeTheInsertFailWith(self::refusal('Duplicate entry', 1062));

        $this->assertFalse($this->repository->addRenterEmail($this->bookingId, 'tresorier@groupe.example', 9));
    }

    /**
     * …but not every refusal under SQLSTATE 23000: a foreign key (the
     * booking deleted between the read and the write, error 1452) shares it,
     * and is a write that failed, not an address already there.
     */
    public function testAForeignKeyViolationIsNotTakenForADuplicate(): void
    {
        $this->makeTheInsertFailWith(self::refusal('Cannot add or update a child row', 1452));

        $this->expectException(\PDOException::class);
        $this->repository->addRenterEmail($this->bookingId, 'tresorier@groupe.example', 9);
    }

    private static function refusal(string $message, int $driverCode): \PDOException
    {
        $failure = new \PDOException($message, 23000);
        $failure->errorInfo = ['23000', $driverCode, $message];

        return $failure;
    }

    /**
     * The INSERT must fail when it RUNS: SQLite compiles a trigger's body
     * when the statement is prepared, which the repository does outside its
     * try, so a trigger cannot play a failure of the write itself. A
     * statement class whose execute() throws can.
     */
    private function makeTheInsertFailWith(\PDOException $failure): void
    {
        $statement = new class extends \PDOStatement {
            public static ?\PDOException $failure = null;

            public function execute(?array $params = null): bool
            {
                throw self::$failure ?? new \LogicException('no failure was set');
            }
        };
        $statement::$failure = $failure;
        $this->pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [$statement::class]);
    }

    public function testAnInvalidAddressIsStillRefusedWithoutTouchingTheDatabase(): void
    {
        $this->pdo->exec('DROP TABLE rental_booking_emails');

        $this->assertFalse($this->repository->addRenterEmail($this->bookingId, 'not an address'));
        $this->assertFalse($this->repository->addRenterEmail($this->bookingId, '   '));
    }
}
