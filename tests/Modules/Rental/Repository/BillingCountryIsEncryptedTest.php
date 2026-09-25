<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Repository\RentalBookingRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The seventh billing coordinate is encrypted like the other six (#438),
 * and the plaintext column an upgraded installation still carries is
 * emptied as its value is carried over.
 *
 * `rental_bookings` holds seven billing coordinates. Six were `BLOB`s under
 * their own encryption contexts; `billing_country` was a plain
 * `VARCHAR(2)`, while the comment above them in schema.sql said « all of it
 * is encrypted at rest ». Alone the country identifies nobody — it says a
 * bill goes to Belgium — so this closes an inconsistency rather than a
 * leak; what makes the inconsistency worth closing is that SECURITY.md §5
 * lists `country` among a member's encrypted address fields with no
 * asterisk, and a rule that tolerates one unwritten exception gets a
 * second.
 *
 * **The backfill is the part that needed a test of its own.** It is not the
 * column move `RentalAssetRepository::adoptLegacyCalendarColumn()` is: a
 * copy that left the clear value in the old column would report success and
 * change nothing whatsoever about the defect. So there is a test for the
 * emptying, separate from the one for the carrying.
 */
#[Group('database')]
final class BillingCountryIsEncryptedTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalBookingRepository $repository;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);

        // The backfill runs once per PROCESS, which is right in production
        // and wrong for a test: the first class to trigger it would decide
        // whether every later one observes anything, and the answer would
        // depend on the order the suite happened to run in. Reset here so
        // each test starts from « not carried over yet ».
        (new \ReflectionProperty(RentalBookingRepository::class, 'legacyCountryAdopted'))
            ->setValue(null, false);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RentalBookingRepository($this->pdo, $this->encryption);

        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_assets (asset_type, name, slug) VALUES (?, ?, ?)'
        );
        $stmt->execute(['Local', 'Le local', 'le-local']);
        $this->assetId = (int) $this->pdo->lastInsertId();
    }

    public function testTheCountryIsAnUnreadableBlobOnDiskAndReadableThroughTheRepository(): void
    {
        $booking = $this->createBooking();

        $this->repository->saveBillingIdentity($booking, ['country' => 'BE']);

        $stored = $this->storedCountry($booking);
        $this->assertNotNull($stored, 'nothing was written at all');
        $this->assertNotSame('BE', $stored, 'the country is stored in clear');
        // A two-letter code is too short to look for inside a JSON dump of
        // the row — « BE » occurs by chance in the base64 of anything — so
        // the assertion is on the one column that holds it, and on its
        // length: AES-256-GCM carries a nonce and a tag, so a ciphertext of
        // two bytes is not one.
        $this->assertGreaterThan(
            16,
            strlen($stored),
            'the stored value is too short to be a nonce, a ciphertext and a tag'
        );

        $this->assertSame('BE', $this->repository->findBillingIdentity($booking)['country']);
    }

    /**
     * Each coordinate under its own context, which is what stops a
     * ciphertext being moved from one column to another.
     */
    public function testTheCountryCannotBeReadUnderAnotherColumnsContext(): void
    {
        $booking = $this->createBooking();
        $this->repository->saveBillingIdentity($booking, ['country' => 'BE']);

        $this->expectException(\RuntimeException::class);
        $this->encryption->decrypt(
            (string) $this->storedCountry($booking),
            'rental_bookings.billing_address'
        );
    }

    /**
     * The normalisation now has nobody behind it: a `VARCHAR(2)` refused an
     * over-long value itself, a `BLOB` refuses nothing.
     */
    public function testTheCountryIsStillNormalisedBeforeItIsEncrypted(): void
    {
        $booking = $this->createBooking();

        $this->repository->saveBillingIdentity($booking, ['country' => ' be ']);

        $this->assertSame('BE', $this->repository->findBillingIdentity($booking)['country']);
    }

    public function testAnythingThatIsNotATwoLetterCodeIsDroppedRatherThanEncrypted(): void
    {
        $booking = $this->createBooking();

        $this->repository->saveBillingIdentity($booking, ['country' => 'Belgique']);

        $this->assertNull($this->storedCountry($booking), '« Belgique » was stored as typed');
        $this->assertNull($this->repository->findBillingIdentity($booking)['country']);
    }

    // ————— The retired plaintext column (#438) —————

    public function testAClearCountryLeftByAnEarlierReleaseIsCarriedOverOnRead(): void
    {
        $booking = $this->createBooking();
        $this->giveItALegacyClearCountry($booking, 'FR');

        $this->assertSame(
            'FR',
            $this->repository->findBillingIdentity($booking)['country'],
            'a booking upgraded from before this release lost its billing country'
        );
    }

    /**
     * **And the clear value is gone**, which is the whole point and the one
     * thing a copy-only backfill would not do.
     */
    public function testTheClearValueIsEmptiedRatherThanLeftBehind(): void
    {
        $booking = $this->createBooking();
        $this->giveItALegacyClearCountry($booking, 'FR');

        $this->repository->findBillingIdentity($booking);

        $this->assertNull(
            $this->legacyClearCountry($booking),
            'the value was encrypted into the new column and left in clear in the old one, '
                . 'which is the defect this change exists to remove'
        );
        $this->assertNotNull($this->storedCountry($booking));
    }

    /**
     * A save, not only a read: a request that writes a billing identity
     * without ever reading one would otherwise leave every OTHER booking's
     * country in clear.
     */
    public function testASaveCarriesOverTheOtherBookingsTooRatherThanOnlyItsOwn(): void
    {
        $mine = $this->createBooking('LOC-2027-0001');
        $theirs = $this->createBooking('LOC-2027-0002');
        $this->giveItALegacyClearCountry($theirs, 'NL');

        $this->repository->saveBillingIdentity($mine, ['country' => 'BE']);

        $this->assertNull($this->legacyClearCountry($theirs));
        $this->assertSame('NL', $this->repository->findBillingIdentity($theirs)['country']);
    }

    /**
     * A clear value the current writer would refuse is cleared rather than
     * carried: nothing can read « Belgique » as a country, it cannot have
     * come from today's form, and leaving it would leave a clear string on
     * disk for ever.
     */
    public function testAClearValueThatIsNotACodeIsRemovedRatherThanEncrypted(): void
    {
        $booking = $this->createBooking();
        $this->giveItALegacyClearCountry($booking, 'Belgique');

        $this->assertNull($this->repository->findBillingIdentity($booking)['country']);
        $this->assertNull($this->legacyClearCountry($booking));
    }

    /**
     * A fresh installation never had the column, and one already carried
     * over and dropped no longer has it. Both must be a no-op rather than a
     * failure on every read of a billing identity — which is what every
     * other test in this module relies on without saying so.
     */
    public function testAnInstallationWithoutTheOldColumnReadsItsBillingIdentityNormally(): void
    {
        $booking = $this->createBooking();
        $this->repository->saveBillingIdentity($booking, ['country' => 'BE']);

        $this->assertFalse(
            $this->hasLegacyColumn(),
            'the test schema still declares the retired column, so this test proves nothing'
        );
        $this->assertSame('BE', $this->repository->findBillingIdentity($booking)['country']);
    }

    /** The old column, as an installation upgraded from before #438 has it. */
    private function giveItALegacyClearCountry(int $bookingId, string $country): void
    {
        if (!$this->hasLegacyColumn()) {
            $this->pdo->exec('ALTER TABLE rental_bookings ADD COLUMN billing_country TEXT');
        }

        $stmt = $this->pdo->prepare('UPDATE rental_bookings SET billing_country = ? WHERE id = ?');
        $stmt->execute([$country, $bookingId]);
    }

    private function hasLegacyColumn(): bool
    {
        foreach ($this->pdo->query('PRAGMA table_info(rental_bookings)') as $column) {
            if ($column['name'] === 'billing_country') {
                return true;
            }
        }

        return false;
    }

    private function legacyClearCountry(int $bookingId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT billing_country FROM rental_bookings WHERE id = ?');
        $stmt->execute([$bookingId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function storedCountry(int $bookingId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT billing_country_encrypted FROM rental_bookings WHERE id = ?'
        );
        $stmt->execute([$bookingId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function createBooking(string $reference = 'LOC-2027-0042'): int
    {
        $created = $this->repository->create(
            $this->assetId,
            $reference,
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => '+32 495 11 22 33',
                'organisation' => null,
                'purpose' => null,
                'comment' => null,
            ],
            new PriceQuote(
                lines: [new PriceLine('Séjour', 3, 12000, 36000, 'base')],
                totalCents: 36000,
                nights: 3,
                persons: 20,
                quantity: 3,
                billingUnit: BillingUnit::PER_NIGHT
            ),
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );

        return (int) $created['id'];
    }
}
