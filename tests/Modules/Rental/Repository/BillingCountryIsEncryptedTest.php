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
        // Both of them. The second says whether a statement may still NAME
        // the retired column, and leaving it latched would let one test
        // decide what the next one is even able to observe — the same
        // order-dependence, one property further along.
        (new \ReflectionProperty(RentalBookingRepository::class, 'legacyCountryAdopted'))
            ->setValue(null, false);
        (new \ReflectionProperty(RentalBookingRepository::class, 'legacyCountryColumnPresent'))
            ->setValue(null, null);

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

    /**
     * **A manager's save is not reverted by a backfill that started
     * first**, which a reviewer had to point out and which no test here
     * covered.
     *
     * The rows are snapshotted before the loop writes any of them, and
     * each iteration costs an encryption and a round trip. So worker A can
     * hold booking #42 as « FR » while worker B saves « NL » on it — and
     * A's write, keyed on `id` alone, put « FR » back with nothing to show
     * it: no error, no `updated_at`.
     *
     * Each UPDATE therefore names the clear value it was told to carry, so
     * it matches nothing once somebody else has emptied that column. The
     * save below happens at the only instant where it used to do damage:
     * between the snapshot and the writes, which is where the repository
     * prepares its UPDATE.
     */
    public function testASaveThatLandsMidBackfillIsNotRevertedByIt(): void
    {
        $booking = $this->createBooking();
        $this->giveItALegacyClearCountry($booking, 'FR');

        // Worker B, running between the snapshot and the write: it carries
        // the row over itself and stores a different country.
        $interleaving = $this->connectionThatInterleaves(function () use ($booking): void {
            $other = new RentalBookingRepository($this->pdo, $this->encryption);
            $write = $this->pdo->prepare(
                'UPDATE rental_bookings
                    SET billing_country_encrypted = ?, billing_country = NULL
                  WHERE id = ?'
            );
            $write->execute([$this->encryption->encrypt('NL', 'rental_bookings.billing_country'), $booking]);
            unset($other);
        });

        (new RentalBookingRepository($interleaving, $this->encryption))
            ->findBillingIdentity($booking);

        $this->assertSame(
            'NL',
            $this->repository->findBillingIdentity($booking)['country'],
            'the backfill wrote its stale snapshot over a country a manager had just saved'
        );
    }

    /**
     * **A database that could not answer does not disable the carry-over
     * for good.**
     *
     * The flag used to be set before the probe ran, and the catch treated
     * every `PDOException` as « no such column ». So one lock wait or lost
     * connection wrote the backfill off for the rest of the worker's life
     * — and since findBillingIdentity() reads only the new column, every
     * booking still holding a clear country then reported none at all.
     *
     * What distinguishes the two cases is the failure itself: « unknown
     * column » is an answer about this installation and never changes;
     * anything else is no answer.
     */
    public function testATransientDatabaseFailureLeavesTheCarryOverToBeRetried(): void
    {
        $booking = $this->createBooking();
        $this->giveItALegacyClearCountry($booking, 'FR');

        $refusals = 1;
        $flaky = $this->connectionThatRefusesTheProbe($refusals);

        $first = new RentalBookingRepository($flaky, $this->encryption);
        $this->assertNull(
            $first->findBillingIdentity($booking)['country'],
            'the failed probe is swallowed rather than turned into a 500, so this read finds nothing yet'
        );

        // **Nothing is reset here, and that is the test.** The next
        // repository built in this process is what the next request is,
        // and it must carry the column over — which it can only do if the
        // failed probe was not written off. Resetting the flag by
        // reflection, as setUp() does to isolate the classes from each
        // other, would hide the very thing being checked: the first
        // version of this test did exactly that, and stayed green with the
        // latch put back where it was.
        $this->assertSame(
            'FR',
            $this->repository->findBillingIdentity($booking)['country'],
            'a transient failure wrote the carry-over off instead of leaving it for the next request'
        );
    }

    /**
     * A save empties the retired column for its own booking **even when
     * the backfill it called first did not**.
     *
     * The flaky connection is the whole test. On a healthy one the
     * backfill carries the row over and empties the column on its way, so
     * the assertion below passes whether the save clears anything or not —
     * the first version of this test did exactly that, and stayed green
     * with the clearing removed. It proved the backfill worked, under a
     * name that claimed something about the save.
     *
     * With the probe refused once, the backfill returns having touched
     * nothing, and the clear value survives unless the save itself empties
     * it. Which is the case that matters: leaving it there is what let a
     * later pass revert the manager's country.
     */
    public function testASaveEmptiesTheRetiredColumnEvenWhenTheBackfillCouldNot(): void
    {
        $booking = $this->createBooking();
        $this->giveItALegacyClearCountry($booking, 'FR');

        $flaky = new RentalBookingRepository($this->connectionThatRefusesTheProbe(1), $this->encryption);
        $flaky->saveBillingIdentity($booking, ['country' => 'NL']);

        $this->assertNull(
            $this->legacyClearCountry($booking),
            'the save left a clear country behind, which a later backfill pass can revert to'
        );
        $this->assertSame('NL', $this->repository->findBillingIdentity($booking)['country']);
    }

    /**
     * **And the backfill refuses a row a save has already written, even
     * when the old clear value is still sitting there.**
     *
     * This is the reviewer's sequence, and it was a real silent data loss.
     * A save calls the backfill first; if that pass fails transiently —
     * the path deliberately swallowed so an invoice screen is not a 500 —
     * the row keeps its clear `'FR'`. The save then writes `enc('NL')`
     * beside it. The next successful pass snapshots `'FR'`, its
     * value-keyed WHERE still matches because nothing cleared it, and the
     * manager's country is reverted with no error and no `updated_at` to
     * show it.
     *
     * The clear value is put BACK by hand here on purpose. The save now
     * empties it, so the two fixes would hide each other and this test
     * would pass on the strength of the wrong one — what is being checked
     * is that `AND billing_country_encrypted IS NULL` holds on its own,
     * for the day the emptying is the thing that fails.
     */
    public function testTheBackfillDoesNotRevertACountryASaveHasAlreadyWritten(): void
    {
        $booking = $this->createBooking();
        $this->giveItALegacyClearCountry($booking, 'FR');

        $flaky = new RentalBookingRepository($this->connectionThatRefusesTheProbe(1), $this->encryption);
        $flaky->saveBillingIdentity($booking, ['country' => 'NL']);

        // The clear value as a failed emptying would have left it.
        $this->giveItALegacyClearCountry($booking, 'FR');

        $this->assertSame(
            'NL',
            $this->repository->findBillingIdentity($booking)['country'],
            'the backfill wrote a stale clear country over one a manager had already saved'
        );
    }

    /**
     * **The new column and the old one are emptied by ONE statement.**
     *
     * This is the fix for the hole a reviewer found, and the only way to
     * observe it is to count. The clearing used to be a second statement
     * whose failure was swallowed, on the premise that whatever it left
     * behind was a value the backfill refuses to act on. That premise
     * fails for a country a manager CLEARS: encryptCountry() returns null,
     * `billing_country_encrypted` ends up NULL, and the guard cannot tell
     * such a row from one never carried over — so the next pass restored
     * the old value over a deliberate clearing.
     *
     * No assertion about the resulting ROW can distinguish the two
     * designs, because what the fix removes is not a wrong value but the
     * moment at which one is reachable: with two statements, a failure
     * between them leaves the row in the reverting state; with one, there
     * is no between. So what is asserted is exactly that — one write, and
     * it names both columns.
     */
    public function testTheNewCountryAndTheRetiredColumnAreWrittenByOneStatement(): void
    {
        $booking = $this->createBooking();
        $this->giveItALegacyClearCountry($booking, 'FR');

        $written = [];
        $repository = new RentalBookingRepository(
            $this->connectionThatRecordsItsWrites($written),
            $this->encryption
        );
        $repository->saveBillingIdentity($booking, ['country' => 'NL']);

        $touchingTheRow = array_values(array_filter(
            $written,
            static fn (string $sql): bool => str_contains($sql, 'UPDATE rental_bookings')
                && str_contains($sql, 'billing_country')
                // Not the backfill's own write, which is a different job
                // and names its guard: it carries EVERY row that still has
                // a clear country, not this one's save.
                && !str_contains($sql, 'billing_country_encrypted IS NULL')
        ));

        $this->assertCount(
            1,
            $touchingTheRow,
            'the save spends more than one statement on the two country columns, so a failure '
                . 'can land between them and leave the row in the state a later backfill reverts'
        );
        $this->assertStringContainsString('billing_country_encrypted = ?', $touchingTheRow[0]);
        $this->assertStringContainsString('billing_country = NULL', $touchingTheRow[0]);
    }

    /**
     * And the case that made the old design wrong, end to end: a manager
     * who CLEARS the country leaves nothing behind.
     *
     * The probe is refused once so the backfill touches nothing — without
     * that, it empties the column on its way and this passes whatever the
     * save does, which is the trap the sibling test above already fell
     * into once.
     */
    public function testClearingTheCountryEmptiesTheRetiredColumnToo(): void
    {
        $booking = $this->createBooking();
        $this->giveItALegacyClearCountry($booking, 'FR');

        $flaky = new RentalBookingRepository($this->connectionThatRefusesTheProbe(1), $this->encryption);
        $flaky->saveBillingIdentity($booking, ['country' => null]);

        $this->assertNull(
            $this->legacyClearCountry($booking),
            'clearing the country left the old value on disk, where the next backfill pass reads it '
                . 'as a row never carried over and puts it back'
        );
        $this->assertNull($this->repository->findBillingIdentity($booking)['country']);
    }

    /**
     * A save still works on an installation that has already been cleaned,
     * even when the probe never got to say so.
     *
     * The price of writing both columns in one statement is that the
     * statement NAMES the retired one — which is exactly why the clearing
     * sat apart in the first place. Normally the probe has already
     * answered and the save knows. This is the gap it cannot: the probe
     * failed transiently, so nothing is known, and the column happens to
     * be gone. The save tries the statement that names it, reads « unknown
     * column » as the answer it is, records it, and goes out again without
     * it. Anything that is NOT that answer is rethrown — a save is what the
     * caller asked for.
     */
    public function testASaveSurvivesAnInstallationWhoseColumnIsAlreadyGone(): void
    {
        $booking = $this->createBooking();
        $this->assertFalse($this->hasLegacyColumn(), 'this test needs the column absent to mean anything');

        $flaky = new RentalBookingRepository($this->connectionThatRefusesTheProbe(1), $this->encryption);
        $flaky->saveBillingIdentity($booking, ['country' => 'BE']);

        $this->assertSame(
            'BE',
            $this->repository->findBillingIdentity($booking)['country'],
            'the save failed on a column this installation no longer has'
        );
    }

    /**
     * A connection that remembers every statement prepared through it.
     *
     * @param list<string> $written
     */
    private function connectionThatRecordsItsWrites(array &$written): \PDO
    {
        return new class ($this->pdo, $written) extends \PDO {
            /**
             * @param list<string> $written
             */
            public function __construct(private \PDO $inner, private array &$written)
            {
            }

            /**
             * @param  array<int, mixed> $options
             */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->written[] = $query;

                return $this->inner->prepare($query, $options);
            }

            public function query(
                string $query,
                ?int $fetchMode = null,
                mixed ...$fetchModeArgs
            ): \PDOStatement|false {
                return $this->inner->query($query);
            }

            public function lastInsertId(?string $name = null): string|false
            {
                return $this->inner->lastInsertId($name);
            }
        };
    }

    /**
     * **An error about the NEW column must not be read as « the old one is
     * gone ».**
     *
     * The reader used to ask « is a column missing » and never which. The
     * statements here name two, and the answer drives a decision recorded
     * for the life of the process: an « unknown column
     * billing_country_encrypted » — a live request on a half-applied
     * schema — would write the carry-over off while the legacy column is
     * still there holding clear countries. Which is the whole defect this
     * class exists to remove.
     *
     * The probe answers first and alone now, so the row stays carryable
     * and the next request tries again.
     */
    public function testAFailureAboutTheNewColumnDoesNotWriteOffTheCarryOver(): void
    {
        $booking = $this->createBooking();
        $this->giveItALegacyClearCountry($booking, 'FR');

        $broken = new RentalBookingRepository(
            $this->connectionThatRefusesTheCarryOverWrite(1),
            $this->encryption
        );
        $broken->findBillingIdentity($booking);

        $this->assertSame(
            'FR',
            $this->legacyClearCountry($booking),
            'the write failed, so the clear value must still be there for the next pass'
        );
        $this->assertSame(
            'FR',
            $this->repository->findBillingIdentity($booking)['country'],
            'the carry-over was written off by a failure that said nothing about the legacy column'
        );
    }

    /**
     * And the reader itself, on literal messages, because **one column name
     * is a PREFIX of the other**.
     *
     * `billing_country` sits inside `billing_country_encrypted`, so a
     * `str_contains()` would confuse precisely the two cases being told
     * apart. `\b` saves it only because an underscore is a word character
     * and therefore no boundary — which is subtle enough to be worth a
     * fixture rather than a comment.
     */
    public function testTheReaderTellsTheTwoColumnNamesApart(): void
    {
        $reader = new \ReflectionMethod(RentalBookingRepository::class, 'saysTheColumnIsGone');

        $aboutTheLegacyColumn = new \PDOException(
            "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'billing_country' in 'field list'"
        );
        $aboutTheNewColumn = new \PDOException(
            "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'billing_country_encrypted' in 'field list'"
        );
        $aLockWait = new \PDOException(
            'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'
        );

        $this->assertTrue($reader->invoke(null, $aboutTheLegacyColumn, 'billing_country'));
        $this->assertFalse(
            $reader->invoke(null, $aboutTheNewColumn, 'billing_country'),
            'an error about billing_country_encrypted was read as the legacy column being gone'
        );
        $this->assertTrue($reader->invoke(null, $aboutTheNewColumn, 'billing_country_encrypted'));
        $this->assertFalse(
            $reader->invoke(null, $aLockWait, 'billing_country'),
            'a lock wait is not an answer about the schema'
        );
    }

    /**
     * A connection whose CARRY-OVER write fails with « unknown column
     * billing_country_encrypted », the probe succeeding normally.
     *
     * The shape of a half-applied schema, which `MigrationRunner` can leave
     * behind between two invocations and which `MaintenanceGate`'s bypass
     * lets a request reach.
     */
    private function connectionThatRefusesTheCarryOverWrite(int $refusals): \PDO
    {
        return new class ($this->pdo, $refusals) extends \PDO {
            public function __construct(private \PDO $inner, private int $refusals)
            {
            }

            /**
             * @param  array<int, mixed> $options
             */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (str_contains($query, 'billing_country_encrypted = ?') && $this->refusals > 0) {
                    $this->refusals--;

                    throw new \PDOException(
                        "SQLSTATE[42S22]: Column not found: 1054 Unknown column "
                            . "'billing_country_encrypted' in 'field list'",
                        1054
                    );
                }

                return $this->inner->prepare($query, $options);
            }

            public function query(
                string $query,
                ?int $fetchMode = null,
                mixed ...$fetchModeArgs
            ): \PDOStatement|false {
                return $this->inner->query($query);
            }

            public function lastInsertId(?string $name = null): string|false
            {
                return $this->inner->lastInsertId($name);
            }
        };
    }

    /**
     * A connection that runs `$probe` the moment the repository prepares
     * the backfill's UPDATE — after the snapshot, before any write.
     *
     * The same seam, and the same reason, as
     * DocumentTextLockOnTheRealEngineTest: `prepare()` is the one call the
     * repository makes between the two, and one process cannot pause
     * itself there.
     */
    private function connectionThatInterleaves(\Closure $probe): \PDO
    {
        return new class ($this->pdo, $probe) extends \PDO {
            /** @param \PDO $inner the real connection every call is passed to */
            public function __construct(private \PDO $inner, private \Closure $probe)
            {
                // No parent::__construct(): an in-memory SQLite opened a
                // second time is a DIFFERENT, empty database, so this
                // decorator forwards to the one connection that holds the
                // fixture rather than opening one of its own.
            }

            /**
             * @param  array<int, mixed> $options
             */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (str_contains($query, 'SET billing_country_encrypted')) {
                    ($this->probe)();
                }

                return $this->inner->prepare($query, $options);
            }

            public function query(
                string $query,
                ?int $fetchMode = null,
                mixed ...$fetchModeArgs
            ): \PDOStatement|false {
                return $this->inner->query($query);
            }

            public function lastInsertId(?string $name = null): string|false
            {
                return $this->inner->lastInsertId($name);
            }
        };
    }

    /** A connection whose first `$refusals` probes fail the way a busy one does. */
    private function connectionThatRefusesTheProbe(int $refusals): \PDO
    {
        return new class ($this->pdo, $refusals) extends \PDO {
            public function __construct(private \PDO $inner, private int $refusals)
            {
            }

            public function query(
                string $query,
                ?int $fetchMode = null,
                mixed ...$fetchModeArgs
            ): \PDOStatement|false {
                if (str_contains($query, 'billing_country IS NOT NULL') && $this->refusals > 0) {
                    $this->refusals--;
                    // What InnoDB says when a row will not come free. Note
                    // the SQLSTATE: it is NOT the « unknown column » one,
                    // which is the whole distinction being tested.
                    throw new \PDOException(
                        'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded',
                        1205
                    );
                }

                return $this->inner->query($query);
            }

            /**
             * @param  array<int, mixed> $options
             */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return $this->inner->prepare($query, $options);
            }

            public function lastInsertId(?string $name = null): string|false
            {
                return $this->inner->lastInsertId($name);
            }
        };
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
