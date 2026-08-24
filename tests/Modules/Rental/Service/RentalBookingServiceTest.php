<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalException;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The booking aggregate: the reference, the encrypted renter identity, the
 * capability token, the price snapshot, the versioned acceptances, and the
 * single hold mechanism with its two origins (§6.13, §6.14, §6.15).
 */
class RentalBookingServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalBookingRepository $repository;
    private RentalBookingService $service;
    private int $assetId;

    /** @var array<int, array{type: string, description: string, context: ?string}> */
    private array $journalEntries = [];

    private const NOW = '2027-06-01 10:00:00';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO rental_assets (asset_type, name, slug) VALUES (?, ?, ?)');
        $stmt->execute(['Local', 'Local Saint-Georges', 'local-saint-georges']);
        $this->assetId = (int) $this->pdo->lastInsertId();

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RentalBookingRepository($this->pdo, $this->encryption);

        $entries = &$this->journalEntries;
        $journalRepository = new class ($entries) extends JournalRepository {
            /** @param array<int, array{type: string, description: string, context: ?string}> $entries */
            public function __construct(private array &$entries)
            {
            }

            public function insert(
                string $category,
                string $type,
                string $level,
                string $description,
                ?string $context = null,
                ?int $userId = null,
                ?string $ipAddress = null
            ): void {
                $this->entries[] = compact('type', 'description', 'context');
            }
        };

        $this->service = new RentalBookingService($this->repository, new JournalService($journalRepository));
    }

    private function now(string $when = self::NOW): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{booking: \Modules\Rental\Booking\RentalBooking, tracking_token: string}
     */
    private function submit(array $overrides = []): array
    {
        return $this->service->createFromPublicRequest(
            $overrides['asset_id'] ?? $this->assetId,
            $overrides['arrival'] ?? '2027-07-17',
            $overrides['departure'] ?? '2027-07-20',
            $overrides['units'] ?? 1,
            $overrides['persons'] ?? 35,
            $overrides['category_id'] ?? null,
            array_merge([
                'name' => 'Marie Dupont',
                'email' => 'marie@example.org',
                'phone' => '+32 470 12 34 56',
                'organisation' => 'Unité Saint-Michel',
                'purpose' => 'Camp de section',
                'comment' => 'Nous arriverons vers 18 h.',
            ], $overrides['renter'] ?? []),
            $overrides['price'] ?? null,
            array_merge([
                'conditions_version' => '2027-01',
                'conditions_text' => 'Les conditions de location.',
                'privacy_version' => '2027-01',
                'privacy_text' => 'La politique de confidentialité.',
            ], $overrides['acceptances'] ?? []),
            $this->now($overrides['now'] ?? self::NOW),
            $overrides['hold_hours'] ?? RentalBookingService::DEFAULT_AUTOMATIC_HOLD_HOURS
        );
    }

    // ── Reference allocation ────────────────────────────────────────────

    public function testTheFirstBookingOfAYearIsNumberOne(): void
    {
        $booking = $this->submit()['booking'];

        $this->assertSame('LOC-2027-0001', $booking->reference);
    }

    public function testReferencesIncrementWithinAYear(): void
    {
        $first = $this->submit()['booking'];
        $second = $this->submit()['booking'];
        $third = $this->submit()['booking'];

        $this->assertSame(['LOC-2027-0001', 'LOC-2027-0002', 'LOC-2027-0003'], [
            $first->reference,
            $second->reference,
            $third->reference,
        ]);
    }

    public function testACollidingReferenceIsRetriedRatherThanShownToTheVisitor(): void
    {
        // The counter can hand out a number a row already holds — a
        // restored backup, a hand-inserted booking, a reset counter. The
        // visitor on the public request form must never meet the driver's
        // "Integrity constraint violation" as a 500.
        $service = new RentalBookingService(
            $this->repositoryHandingOut([1, 2]),
            new JournalService($this->silentJournal())
        );
        $this->submit();

        $result = $service->createFromPublicRequest(
            $this->assetId,
            '2027-08-01',
            '2027-08-04',
            1,
            20,
            null,
            ['name' => 'Marie Dupont', 'email' => 'marie@example.org', 'phone' => null,
             'organisation' => null, 'purpose' => null, 'comment' => null],
            null,
            ['conditions_version' => '2027-01', 'conditions_text' => 'x',
             'privacy_version' => '2027-01', 'privacy_text' => 'y'],
            $this->now()
        );

        $this->assertSame('LOC-2027-0002', $result['booking']->reference);
    }

    public function testACounterStuckOnATakenNumberRefusesInFrenchNeverWithAPdoException(): void
    {
        // Two failures in a row is a broken counter, not contention. The
        // visitor still gets one French sentence, and the driver's message
        // goes to the journal through $previous.
        $service = new RentalBookingService(
            $this->repositoryHandingOut([1, 1]),
            new JournalService($this->silentJournal())
        );
        $this->submit();

        try {
            $service->createFromPublicRequest(
                $this->assetId,
                '2027-08-01',
                '2027-08-04',
                1,
                20,
                null,
                ['name' => 'Marie Dupont', 'email' => 'marie@example.org', 'phone' => null,
                 'organisation' => null, 'purpose' => null, 'comment' => null],
                null,
                ['conditions_version' => '2027-01', 'conditions_text' => 'x',
                 'privacy_version' => '2027-01', 'privacy_text' => 'y'],
                $this->now()
            );
            $this->fail('The second collision must be refused.');
        } catch (RentalException $e) {
            $this->assertStringContainsString("n'a pas pu être enregistrée", $e->getMessage());
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    public function testTheSequenceClaimTakesARowLockOutsideSqlite(): void
    {
        // SQLite has no row locks and does not need them — its
        // transactions are whole-database. MySQL does, and without the
        // lock two submissions read the same last_sequence.
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/modules/rental/src/Repository/RentalBookingRepository.php'
        );
        $this->assertNotFalse($source);
        $this->assertStringContainsString("\$sql .= ' FOR UPDATE';", $source);
    }

    /**
     * A repository whose counter hands out $sequences in order, so a
     * collision can be reproduced without two real processes.
     *
     * @param int[] $sequences
     */
    private function repositoryHandingOut(array $sequences): RentalBookingRepository
    {
        return new class ($this->pdo, $this->encryption, $sequences) extends RentalBookingRepository {
            /** @param int[] $sequences */
            public function __construct(
                \PDO $pdo,
                EncryptionService $encryption,
                private array $sequences
            ) {
                parent::__construct($pdo, $encryption);
            }

            public function claimNextReferenceSequence(int $year): int
            {
                return (int) (array_shift($this->sequences) ?? 99);
            }
        };
    }

    private function silentJournal(): JournalRepository
    {
        return new class extends JournalRepository {
            public function __construct()
            {
            }

            public function insert(
                string $category,
                string $type,
                string $level,
                string $description,
                ?string $context = null,
                ?int $userId = null,
                ?string $ipAddress = null
            ): void {
            }
        };
    }

    public function testTheSequenceRestartsInANewYear(): void
    {
        $this->submit();
        $next = $this->submit(['now' => '2028-01-05 09:00:00'])['booking'];

        $this->assertSame('LOC-2028-0001', $next->reference);
    }

    public function testTheYearComesFromWhenTheRequestWasMadeNotFromTheStayDates(): void
    {
        // A reference must stay stable, and a request made in 2027 for a 2028
        // camp is a 2027 request.
        $booking = $this->submit(['arrival' => '2028-07-17', 'departure' => '2028-07-20'])['booking'];

        $this->assertSame('LOC-2027-0001', $booking->reference);
    }

    public function testADeletedBookingNeverFreesItsNumberForReuse(): void
    {
        // Two rentals quoting the same reference to two renters is the bug
        // this guards against — which is why the sequence reads the stored
        // references and never counts rows.
        $first = $this->submit()['booking'];
        $second = $this->submit()['booking'];
        $this->repository->deleteById($second->id);

        $third = $this->submit()['booking'];

        $this->assertSame('LOC-2027-0001', $first->reference);
        $this->assertSame('LOC-2027-0003', $third->reference);
    }

    public function testTheReferenceColumnIsUniqueSoAConcurrentDuplicateCannotLand(): void
    {
        $this->submit();

        $this->expectException(\PDOException::class);
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_bookings (asset_id, reference, arrival_date, departure_date,
                renter_name_encrypted, renter_email_encrypted, renter_email_blind_index, tracking_token_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->assetId, 'LOC-2027-0001', '2027-08-01', '2027-08-03', 'x', 'y', 'z', 'h']);
    }

    // ── Personal data is encrypted ──────────────────────────────────────

    public function testEveryRenterFieldIsStoredEncryptedNotInClear(): void
    {
        // A booking is about a NON-MEMBER, with no other trace in this
        // installation — so a database copy must not hand over who they are.
        $this->submit();

        $row = $this->pdo->query('SELECT * FROM rental_bookings')->fetch(\PDO::FETCH_ASSOC);
        $serialized = implode('|', array_map(
            static fn($value) => is_string($value) ? $value : (string) $value,
            array_filter($row, static fn($v) => $v !== null)
        ));

        foreach (['Marie', 'Dupont', 'marie@example.org', '470', 'Saint-Michel', 'Camp de section', '18 h'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized, "\"{$secret}\" must not appear in clear.");
        }
    }

    public function testEveryRenterFieldRoundTripsThroughDecryption(): void
    {
        $booking = $this->submit()['booking'];

        $reloaded = $this->repository->findById($booking->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('Marie Dupont', $reloaded->renterName);
        $this->assertSame('marie@example.org', $reloaded->renterEmail);
        $this->assertSame('+32 470 12 34 56', $reloaded->renterPhone);
        $this->assertSame('Unité Saint-Michel', $reloaded->renterOrganisation);
        $this->assertSame('Camp de section', $reloaded->purpose);
        $this->assertSame('Nous arriverons vers 18 h.', $reloaded->renterComment);
    }

    public function testOptionalRenterFieldsStayNullRatherThanEncryptedEmptyStrings(): void
    {
        $booking = $this->submit(['renter' => [
            'phone' => null,
            'organisation' => '   ',
            'purpose' => '',
            'comment' => null,
        ]])['booking'];

        $reloaded = $this->repository->findById($booking->id);
        $this->assertNull($reloaded?->renterPhone);
        $this->assertNull($reloaded?->renterOrganisation);
        $this->assertNull($reloaded?->purpose);
        $this->assertNull($reloaded?->renterComment);
    }

    public function testTheEmailBlindIndexIgnoresCaseAndSurroundingSpace(): void
    {
        // A renter who capitalises differently when logging in must still
        // find their own booking.
        $this->submit(['renter' => ['email' => 'Marie@Example.ORG ']]);

        $this->assertCount(1, $this->repository->findByRenterEmail('marie@example.org'));
        $this->assertCount(1, $this->repository->findByRenterEmail('  MARIE@EXAMPLE.ORG'));
        $this->assertSame([], $this->repository->findByRenterEmail('other@example.org'));
    }

    public function testTheJournalRecordsTheReferenceButNeverTheRenter(): void
    {
        $this->submit();

        $this->assertNotSame([], $this->journalEntries);
        foreach ($this->journalEntries as $entry) {
            $serialized = $entry['description'] . ' ' . (string) $entry['context'];
            foreach (['Marie', 'Dupont', 'marie@example.org', '470', 'Saint-Michel'] as $secret) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
        }

        $this->assertSame('rental_booking_received', $this->journalEntries[0]['type']);
        $this->assertStringContainsString('LOC-2027-0001', $this->journalEntries[0]['description']);
    }

    // ── Tracking token (§13 of the conventions) ─────────────────────────

    public function testTheTrackingTokenIsLongRandomAndNeverStoredInTheClear(): void
    {
        $result = $this->submit();
        $token = $result['tracking_token'];

        $this->assertSame(64, strlen($token), '32 random bytes, hex-encoded.');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        // Encrypted rather than hashed since the module started writing to
        // the renter (see the note in schema.sql): a hash can say "yes,
        // that is the token" and nothing else, and every decision email
        // has to carry the link itself. What must still hold is that the
        // column is unreadable without the application key.
        $stored = $this->pdo->query('SELECT tracking_token_encrypted FROM rental_bookings')->fetchColumn();
        $this->assertIsString($stored);
        $this->assertStringNotContainsString($token, $stored, 'The raw token never sits in the column.');
    }

    public function testTheStoredTrackingTokenCanBeReadBackForAnEmail(): void
    {
        $result = $this->submit();

        $this->assertSame(
            $result['tracking_token'],
            $this->service->trackingTokenFor($result['booking']->id)
        );
    }

    public function testARegeneratedTokenReplacesTheOldOneEverywhere(): void
    {
        $result = $this->submit();
        $bookingId = $result['booking']->id;

        $fresh = $this->service->regenerateTrackingToken($bookingId);

        $this->assertNotSame($result['tracking_token'], $fresh);
        // Revocation is the whole point: the forwarded link stops working.
        $this->assertNull($this->service->findByTrackingToken($bookingId, $result['tracking_token']));
        $this->assertNotNull($this->service->findByTrackingToken($bookingId, $fresh));
        $this->assertSame($fresh, $this->service->trackingTokenFor($bookingId));
    }

    public function testAnEmptyTokenNeverUnlocksAnything(): void
    {
        $result = $this->submit();

        $this->assertNull($this->service->findByTrackingToken($result['booking']->id, ''));
    }

    public function testAnUnknownBookingHasNoTokenToRead(): void
    {
        $this->assertNull($this->service->trackingTokenFor(9999));
    }

    public function testTwoBookingsNeverShareATrackingToken(): void
    {
        $first = $this->submit();
        $second = $this->submit();

        $this->assertNotSame($first['tracking_token'], $second['tracking_token']);
    }

    public function testAValidTokenUnlocksItsOwnBooking(): void
    {
        $result = $this->submit();

        $found = $this->service->findByTrackingToken($result['booking']->id, $result['tracking_token']);

        $this->assertNotNull($found);
        $this->assertSame($result['booking']->id, $found->id);
    }

    public function testATokenNeverUnlocksAnotherBooking(): void
    {
        // The IDOR case: possession of one booking's token must reveal
        // nothing about any other.
        $mine = $this->submit();
        $theirs = $this->submit();

        $this->assertNull($this->service->findByTrackingToken($theirs['booking']->id, $mine['tracking_token']));
        $this->assertNull($this->service->findByTrackingToken($mine['booking']->id, $theirs['tracking_token']));
    }

    public function testAWrongEmptyOrUnknownTokenIsRefusedIdentically(): void
    {
        // Returning null for both "no such booking" and "wrong token" is what
        // stops a caller probing which references exist.
        $result = $this->submit();

        $this->assertNull($this->service->findByTrackingToken($result['booking']->id, 'wrong'));
        $this->assertNull($this->service->findByTrackingToken($result['booking']->id, ''));
        $this->assertNull($this->service->findByTrackingToken($result['booking']->id, str_repeat('0', 64)));
        $this->assertNull($this->service->findByTrackingToken(999999, $result['tracking_token']));
    }

    public function testRegeneratingTheTokenInvalidatesThePreviousOne(): void
    {
        // Revocability is what makes a capability token acceptable at all.
        $result = $this->submit();
        $old = $result['tracking_token'];

        $new = $this->service->regenerateTrackingToken($result['booking']->id);

        $this->assertNotSame($old, $new);
        $this->assertNull($this->service->findByTrackingToken($result['booking']->id, $old));
        $this->assertNotNull($this->service->findByTrackingToken($result['booking']->id, $new));
    }

    public function testATokenIsNeverWrittenToTheJournal(): void
    {
        $result = $this->submit();
        $this->service->regenerateTrackingToken($result['booking']->id);

        foreach ($this->journalEntries as $entry) {
            $serialized = $entry['description'] . ' ' . (string) $entry['context'];
            $this->assertStringNotContainsString($result['tracking_token'], $serialized);
        }
    }

    // ── Versioned acceptances (§6.13) ───────────────────────────────────

    public function testBothAcceptancesAreRecordedWithTheirVersionAndAHashOfTheText(): void
    {
        $booking = $this->submit()['booking'];

        $this->assertSame('2027-01', $booking->conditionsVersion);
        $this->assertNotNull($booking->conditionsAcceptedAt);
        $this->assertSame('2027-01', $booking->privacyVersion);
        $this->assertNotNull($booking->privacyAcknowledgedAt);

        $row = $this->pdo->query('SELECT conditions_hash, privacy_hash FROM rental_bookings')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(RentalBookingService::hashAcceptedText('Les conditions de location.'), $row['conditions_hash']);
        $this->assertSame(RentalBookingService::hashAcceptedText('La politique de confidentialité.'), $row['privacy_hash']);
    }

    public function testAMissingAcceptanceIsRefused(): void
    {
        foreach (['conditions_version', 'privacy_version'] as $missing) {
            try {
                $this->submit(['acceptances' => [$missing => null]]);
                $this->fail("A booking without {$missing} must be refused.");
            } catch (RentalException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rental_bookings')->fetchColumn());
    }

    public function testAChangedConditionsTextIsDetectableAfterTheFact(): void
    {
        // A version string alone would be worthless the first time somebody
        // edits the conditions without bumping it.
        $booking = $this->submit()['booking'];
        $row = $this->pdo->query('SELECT conditions_hash FROM rental_bookings')->fetch(\PDO::FETCH_ASSOC);

        $this->assertTrue($this->service->acceptedTextStillMatches($row['conditions_hash'], 'Les conditions de location.'));
        $this->assertFalse($this->service->acceptedTextStillMatches($row['conditions_hash'], 'Les conditions de location, revues.'));
        $this->assertFalse($this->service->acceptedTextStillMatches(null, 'Les conditions de location.'));
    }

    public function testTheAcceptedTextHashIgnoresSurroundingWhitespaceOnly(): void
    {
        $this->assertSame(
            RentalBookingService::hashAcceptedText('Un texte.'),
            RentalBookingService::hashAcceptedText("  Un texte.\n")
        );
        $this->assertNotSame(
            RentalBookingService::hashAcceptedText('Un texte.'),
            RentalBookingService::hashAcceptedText('Un  texte.')
        );
    }

    // ── Validation ──────────────────────────────────────────────────────

    public function testANameAndAValidEmailAreRequired(): void
    {
        foreach ([
            ['name' => '   '],
            ['email' => ''],
            ['email' => 'pas-une-adresse'],
        ] as $bad) {
            try {
                $this->submit(['renter' => $bad]);
                $this->fail('Expected a refusal for ' . json_encode($bad));
            } catch (RentalException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── Price snapshot (§6.11) ──────────────────────────────────────────

    public function testTheEstimatedPriceIsSnapshottedAndSurvivesAReload(): void
    {
        // The record of what the visitor was actually shown. Changing the
        // asset's rates afterwards must not rewrite it.
        $quote = new PriceQuote(
            lines: [new PriceLine('35 pers. × 3 nuits', 105, 250, 26250, PriceLine::RULE_BASE)],
            totalCents: 26250,
            nights: 3,
            persons: 35,
            quantity: 105,
            billingUnit: BillingUnit::PER_PERSON_NIGHT
        );

        $booking = $this->submit(['price' => $quote])['booking'];
        $reloaded = $this->repository->findById($booking->id);

        $this->assertSame(26250, $reloaded?->estimatedTotalCents);
        $this->assertNotNull($reloaded?->estimatedPrice);
        $this->assertSame(26250, $reloaded->estimatedPrice->totalCents);
        $this->assertSame('35 pers. × 3 nuits', $reloaded->estimatedPrice->lines[0]->label);
        $this->assertSame(BillingUnit::PER_PERSON_NIGHT, $reloaded->estimatedPrice->billingUnit);
    }

    public function testABookingWithNoPriceYetIsStillValid(): void
    {
        $booking = $this->submit(['price' => null])['booking'];

        $this->assertNull($booking->estimatedPrice);
        $this->assertNull($booking->estimatedTotalCents);
    }

    // ── The automatic hold (§6.14) ──────────────────────────────────────

    public function testSubmissionPlacesAnAutomaticHold(): void
    {
        $booking = $this->submit()['booking'];

        $this->assertSame(HoldOrigin::AUTOMATIC, $booking->holdOrigin);
        $this->assertNotNull($booking->holdUntil);
        $this->assertSame('2027-06-03 10:00:00', $booking->holdUntil->format('Y-m-d H:i:s'), '48 hours by default.');
        $this->assertTrue($booking->holdIsActive($this->now()));
        $this->assertSame(BookingStatus::RECEIVED, $booking->status);
    }

    public function testTheHoldDurationIsConfigurableAndCanBeDisabled(): void
    {
        $short = $this->submit(['hold_hours' => 6])['booking'];
        $this->assertSame('2027-06-01 16:00:00', $short->holdUntil?->format('Y-m-d H:i:s'));

        $none = $this->submit(['hold_hours' => 0])['booking'];
        $this->assertNull($none->holdUntil);
        $this->assertNull($none->holdOrigin);
    }

    public function testALapsedAutomaticHoldReleasesTheDatesWithoutEndingTheBooking(): void
    {
        // Nobody promised anything, so the request simply goes back to
        // waiting (§6.14).
        $booking = $this->submit()['booking'];

        $result = $this->service->expireLapsedHolds($this->now('2027-06-04 10:00:00'));

        $reloaded = $this->repository->findById($booking->id);
        $this->assertSame(['released' => 1, 'expired' => 0], $result);
        $this->assertNull($reloaded?->holdUntil);
        $this->assertSame(BookingStatus::RECEIVED, $reloaded?->status, 'Still waiting for a manager.');
    }

    public function testALapsedManagerHoldExpiresTheBooking(): void
    {
        // An option was promised with a deadline and not taken up.
        $booking = $this->submit()['booking'];
        $this->repository->setHold($booking->id, $this->now('2027-06-02 18:00:00'), HoldOrigin::MANAGER);

        $result = $this->service->expireLapsedHolds($this->now('2027-06-04 10:00:00'));

        $reloaded = $this->repository->findById($booking->id);
        $this->assertSame(['released' => 0, 'expired' => 1], $result);
        $this->assertSame(BookingStatus::EXPIRED, $reloaded?->status);
        $this->assertNull($reloaded?->holdUntil);
        $this->assertNotNull($reloaded?->finalAt, 'Expiry is final, and starts the retention clock.');
    }

    public function testAHoldThatHasNotLapsedIsLeftAlone(): void
    {
        $booking = $this->submit()['booking'];

        $result = $this->service->expireLapsedHolds($this->now('2027-06-02 09:00:00'));

        $this->assertSame(['released' => 0, 'expired' => 0], $result);
        $this->assertNotNull($this->repository->findById($booking->id)?->holdUntil);
    }

    public function testAConfirmedBookingKeepsItsDatesWhenItsHoldLapses(): void
    {
        // It is held by its status now; clearing the stale deadline is only
        // tidying up.
        $booking = $this->submit()['booking'];
        $this->repository->setStatus($booking->id, BookingStatus::CONFIRMED, $this->now());

        $this->service->expireLapsedHolds($this->now('2027-06-04 10:00:00'));

        $reloaded = $this->repository->findById($booking->id);
        $this->assertSame(BookingStatus::CONFIRMED, $reloaded?->status);
        $this->assertNull($reloaded?->holdUntil);
    }

    public function testExpiringIsIdempotent(): void
    {
        $this->submit();

        $first = $this->service->expireLapsedHolds($this->now('2027-06-04 10:00:00'));
        $second = $this->service->expireLapsedHolds($this->now('2027-06-04 10:00:00'));

        $this->assertSame(['released' => 1, 'expired' => 0], $first);
        $this->assertSame(['released' => 0, 'expired' => 0], $second);
    }

    // ── A lapsed hold frees its dates, with or without the task ─────────

    public function testALapsedHoldFreesItsDatesOnTheVeryNextPageLoad(): void
    {
        // "Availability is computed, never stored" (§22.2): a hold that
        // lapsed a minute ago frees its dates NOW, not on the next run of
        // the expiry task. Reading occupancy off the status alone made the
        // 48-hour hold decorative — an abandoned request blocked its dates
        // against every visitor for ever.
        $this->submit();
        $window = [$this->now('2027-07-01 00:00:00'), $this->now('2027-07-31 00:00:00')];

        $this->assertCount(
            1,
            $this->service->findOccupancies($this->assetId, ...[...$window, $this->now('2027-06-02 09:00:00')]),
            'While the hold runs, the dates are taken.'
        );

        $this->assertCount(
            0,
            $this->service->findOccupancies($this->assetId, ...[...$window, $this->now('2027-06-03 10:00:01')]),
            'One second past the deadline, and before any task has run.'
        );
    }

    public function testTheExpiryTaskDoesNotResurrectAReleasedRequest(): void
    {
        // The task clears `hold_until` and deliberately leaves the request
        // waiting. That must not make the dates busy again — which is
        // exactly what happened while occupancy came from the status alone.
        $this->submit();
        $window = [$this->now('2027-07-01 00:00:00'), $this->now('2027-07-31 00:00:00')];

        $this->service->expireLapsedHolds($this->now('2027-06-04 10:00:00'));

        $this->assertCount(
            0,
            $this->service->findOccupancies($this->assetId, ...[...$window, $this->now('2027-06-04 10:00:00')])
        );
    }

    public function testARequestWhoseHoldLapsedIsStillWaitingForAnAnswer(): void
    {
        // Releasing the dates refuses nothing (§22.5).
        $booking = $this->submit()['booking'];
        $this->service->expireLapsedHolds($this->now('2027-06-04 10:00:00'));

        $this->assertSame(BookingStatus::RECEIVED, $this->repository->findById($booking->id)?->status);
    }

    public function testAConfirmedBookingHoldsItsDatesWithNoHoldAtAll(): void
    {
        // A commitment, not a deadline: clearing the hold is tidying up.
        $booking = $this->submit()['booking'];
        $this->repository->setStatus($booking->id, BookingStatus::CONFIRMED, $this->now());
        $this->repository->clearHold($booking->id);

        $this->assertCount(1, $this->service->findOccupancies(
            $this->assetId,
            $this->now('2027-07-01 00:00:00'),
            $this->now('2027-07-31 00:00:00'),
            $this->now('2028-01-01 00:00:00')
        ));
    }

    public function testAManagersOptionHoldsTheDatesUntilItsOwnDeadline(): void
    {
        $booking = $this->submit()['booking'];
        $this->repository->setStatus($booking->id, BookingStatus::PROPOSED, $this->now());
        $this->repository->setHold($booking->id, $this->now('2027-06-10 12:00:00'), HoldOrigin::MANAGER);
        $window = [$this->now('2027-07-01 00:00:00'), $this->now('2027-07-31 00:00:00')];

        $this->assertCount(
            1,
            $this->service->findOccupancies($this->assetId, ...[...$window, $this->now('2027-06-09 12:00:00')])
        );
        $this->assertCount(
            0,
            $this->service->findOccupancies($this->assetId, ...[...$window, $this->now('2027-06-11 12:00:00')])
        );
    }

    public function testWithTheAutomaticHoldDisabledARequestNeverBlocksAnyone(): void
    {
        // What `automatic_hold_hours = 0` says in as many words: "les dates
        // restent alors proposées à tout le monde jusqu'à la confirmation".
        $this->submit(['hold_hours' => 0]);

        $this->assertCount(0, $this->service->findOccupancies(
            $this->assetId,
            $this->now('2027-07-01 00:00:00'),
            $this->now('2027-07-31 00:00:00'),
            $this->now()
        ));
    }

    // ── The service is the occupancy source iteration 3 was waiting for ──

    public function testABookingImmediatelyOccupiesItsAssetInTheCalendar(): void
    {
        $this->submit();

        $occupancies = $this->service->findOccupancies(
            $this->assetId,
            $this->now('2027-07-01 00:00:00'),
            $this->now('2027-07-31 00:00:00'),
            $this->now()
        );

        $this->assertCount(1, $occupancies);
        $this->assertSame('2027-07-17', $occupancies[0]->arrivalDate);
        $this->assertSame('2027-07-20', $occupancies[0]->departureDate);
        $this->assertSame(1, $occupancies[0]->units);
    }

    public function testAnOccupancyCarriesNothingAboutTheRenter(): void
    {
        // The mapping deliberately discards identity: a booking, a hold and
        // a manual block become the same shape, which is what makes them
        // indistinguishable to every public surface (§6.7/§6.14).
        $this->submit();

        $occupancy = $this->service->findOccupancies(
            $this->assetId,
            $this->now('2027-07-01 00:00:00'),
            $this->now('2027-07-31 00:00:00'),
            $this->now()
        )[0];

        $serialized = json_encode($occupancy);
        $this->assertIsString($serialized);
        foreach (['Marie', 'Dupont', 'marie@example.org', 'Saint-Michel'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function testARefusedOrCancelledBookingReleasesItsDatesImmediately(): void
    {
        // Leaving them held would quietly make an asset look busy for stays
        // that will never happen.
        $booking = $this->submit()['booking'];
        $window = [$this->now('2027-07-01 00:00:00'), $this->now('2027-07-31 00:00:00'), $this->now()];

        $this->assertCount(1, $this->service->findOccupancies($this->assetId, ...$window));

        $this->repository->setStatus($booking->id, BookingStatus::REFUSED, $this->now());
        $this->assertCount(0, $this->service->findOccupancies($this->assetId, ...$window));

        $this->repository->setStatus($booking->id, BookingStatus::CANCELLED, $this->now());
        $this->assertCount(0, $this->service->findOccupancies($this->assetId, ...$window));

        $this->repository->setStatus($booking->id, BookingStatus::CONFIRMED, $this->now());
        $this->assertCount(1, $this->service->findOccupancies($this->assetId, ...$window));
    }

    public function testAClosedBookingStillShowsAsHistoryInTheCalendar(): void
    {
        $booking = $this->submit()['booking'];
        $this->repository->setStatus($booking->id, BookingStatus::CLOSED, $this->now());

        $this->assertCount(1, $this->service->findOccupancies(
            $this->assetId,
            $this->now('2027-07-01 00:00:00'),
            $this->now('2027-07-31 00:00:00'),
            $this->now()
        ));
    }

    public function testAnotherAssetsBookingsAreNeverReturned(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO rental_assets (asset_type, name, slug) VALUES (?, ?, ?)');
        $stmt->execute(['Local', 'Autre', 'autre']);
        $otherAssetId = (int) $this->pdo->lastInsertId();

        $this->submit();

        $this->assertCount(0, $this->service->findOccupancies(
            $otherAssetId,
            $this->now('2027-07-01 00:00:00'),
            $this->now('2027-07-31 00:00:00'),
            $this->now()
        ));
    }

    public function testOccupanciesOutsideTheWindowAreNotReturned(): void
    {
        $this->submit();

        $this->assertCount(0, $this->service->findOccupancies(
            $this->assetId,
            $this->now('2027-09-01 00:00:00'),
            $this->now('2027-09-30 00:00:00'),
            $this->now()
        ));
    }

    public function testAnOccupancyTouchingTheWindowEdgeIsStillReturned(): void
    {
        // The caller widens its window to cover the grid it renders, so a
        // partial overlap has to come back or a stay crossing the month
        // boundary vanishes.
        $this->submit();

        $this->assertCount(1, $this->service->findOccupancies(
            $this->assetId,
            $this->now('2027-07-19 00:00:00'),
            $this->now('2027-08-15 00:00:00'),
            $this->now()
        ));
    }

    // ── Derived state ───────────────────────────────────────────────────

    public function testInProgressIsDerivedFromTheDatesAndNeverStored(): void
    {
        // A stored flag would need a task to flip it and would spend part of
        // every day disagreeing with the calendar (§6.15).
        $booking = $this->submit()['booking'];
        $this->repository->setStatus($booking->id, BookingStatus::CONFIRMED, $this->now());
        $confirmed = $this->repository->findById($booking->id);

        $this->assertNotNull($confirmed);
        $this->assertFalse($confirmed->isInProgress($this->now('2027-07-16 12:00:00')));
        $this->assertTrue($confirmed->isInProgress($this->now('2027-07-18 12:00:00')));
        $this->assertTrue($confirmed->isInProgress($this->now('2027-07-20 12:00:00')));
        $this->assertFalse($confirmed->isInProgress($this->now('2027-07-21 12:00:00')));
    }

    public function testAnUnconfirmedBookingIsNeverInProgress(): void
    {
        $booking = $this->submit()['booking'];

        $this->assertFalse($booking->isInProgress($this->now('2027-07-18 12:00:00')));
    }

    public function testOnlyFinalStatesStampFinalAt(): void
    {
        $booking = $this->submit()['booking'];
        $this->assertNull($booking->finalAt);

        $this->repository->setStatus($booking->id, BookingStatus::REVIEWING, $this->now());
        $this->assertNull($this->repository->findById($booking->id)?->finalAt);

        $this->repository->setStatus($booking->id, BookingStatus::CLOSED, $this->now());
        $this->assertNotNull($this->repository->findById($booking->id)?->finalAt);
    }

    public function testStatusHelpersAgreeWithTheSpecsLifecycle(): void
    {
        $this->assertTrue(BookingStatus::RECEIVED->needsAttention());
        $this->assertTrue(BookingStatus::REVIEWING->needsAttention());
        $this->assertFalse(BookingStatus::CONFIRMED->needsAttention());

        $this->assertTrue(BookingStatus::CONFIRMED->occupiesTheAsset());
        $this->assertFalse(BookingStatus::EXPIRED->occupiesTheAsset());

        $this->assertTrue(BookingStatus::REFUSED->isFinal());
        $this->assertFalse(BookingStatus::PROPOSED->isFinal());

        $this->assertTrue(HoldOrigin::MANAGER->expiryEndsTheBooking());
        $this->assertFalse(HoldOrigin::AUTOMATIC->expiryEndsTheBooking());
    }
}
