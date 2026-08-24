<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Calendar;

use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Calendar\Api\VirtualEvent;
use Modules\Calendar\Api\VirtualEventViewer;
use Modules\Calendar\Service\IcsBuilder;
use Modules\Calendar\Service\VirtualEventRegistry;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Calendar\PublishFrom;
use Modules\Rental\Calendar\RentalVirtualEventProvider;
use Modules\Rental\Calendar\RenterFeedBuilder;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBlockRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Publishing rental occupancy onto the calendar (§6.30–§6.32).
 *
 * The half of this file that matters most is the privacy rule, and it is
 * tested the way the spec states it: **the detailed version is never built
 * for a reader who may not see it**, so the assertions are about what is
 * absent from the object itself rather than about what a template happened
 * to render. A field that is never constructed cannot leak through HTML,
 * JSON, a tooltip or an ICS line — and each of those four is checked.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalVirtualEventProviderTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;
    private RentalBookingRepository $bookingRepository;
    private RentalBlockRepository $blockRepository;
    private RentalVirtualEventProvider $provider;
    private int $scoutYearId;
    private int $assetId;
    private const CALENDAR_ID = 3;
    private const OTHER_CALENDAR_ID = 9;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->blockRepository = new RentalBlockRepository($this->pdo);

        $memberService = new MemberService(
            new MemberYearRepository($this->pdo),
            $this->encryption,
            Connection::withPdo($this->pdo)
        );

        $this->provider = new RentalVirtualEventProvider(
            $this->assetRepository,
            $this->bookingRepository,
            $this->blockRepository,
            new RentalAuthorizationService($memberService, $this->assetRepository, $this->managerRepository),
            'https://unite.test'
        );

        $this->scoutYearId = (new \Core\Config\ScoutYearService($this->pdo))->getCurrentYear()['id'];
        $this->assetId = $this->createAsset('Local Saint-Georges', 'local-saint-georges');
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function createAsset(string $name, string $slug, bool $publish = true, int $calendarId = self::CALENDAR_ID): int
    {
        $id = $this->assetRepository->create('Local', $name, $slug, 60, 1, '18:00', '11:00', '+32 470 00 00 00', true);
        if ($publish) {
            $this->assetRepository->saveCalendarPublication($id, true, [$calendarId], PublishFrom::CONFIRMATION);
        }

        return $id;
    }

    private function createBooking(
        string $reference = 'LOC-2027-0001',
        ?int $assetId = null,
        BookingStatus $status = BookingStatus::CONFIRMED,
        ?\DateTimeImmutable $holdUntil = null
    ): RentalBooking {
        $created = $this->bookingRepository->create(
            $assetId ?? $this->assetId,
            $reference,
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne.martin@example.be',
                'phone' => '+32 495 11 22 33',
                'organisation' => 'Les Scouts de Nulle Part',
                'purpose' => null,
                'comment' => null,
            ],
            null,
            $holdUntil,
            $holdUntil !== null ? HoldOrigin::AUTOMATIC : null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );

        if ($status !== BookingStatus::RECEIVED) {
            $this->bookingRepository->setStatus($created['id'], $status, new \DateTimeImmutable('2027-01-02 10:00:00'));
        }

        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }

    private function addManager(string $email, ?int $assetId = null): int
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-' . strtoupper(substr(md5($email), 0, 8)));
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, $email);
        $this->managerRepository->grant($assetId ?? $this->assetId, $memberId, false);

        return $memberId;
    }

    /**
     * A member whose main function sits in the real "Staff d'U" section —
     * the same shape Core\Member\UnitStaffSectionService syncs, since that
     * desk_code is exactly what MemberService::isUnitChief() looks for.
     */
    private function makeUnitStaff(string $email): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, 50)');
        $stmt->execute(['STAFFDU', "Staff d'U"]);
        $branchId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['STAFFDU', $branchId, "Staff d'U"]);
        $sectionId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)');
        $stmt->execute(['CU', "Chef d'Unité", 'chief']);
        $functionId = (int) $this->pdo->lastInsertId();

        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-STAFFDU1');
        $memberYearId = RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, $email);

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        return $memberId;
    }

    private function viewer(?string $email, array $calendarIds = [self::CALENDAR_ID], string $role = 'identified'): VirtualEventViewer
    {
        return new VirtualEventViewer(Role::fromString($role), $email, $this->scoutYearId, $calendarIds);
    }

    /**
     * @return VirtualEvent[]
     */
    private function collect(VirtualEventViewer $viewer): array
    {
        return $this->provider->findVirtualEvents(
            new \DateTimeImmutable('2027-06-01'),
            new \DateTimeImmutable('2027-08-31'),
            $viewer
        );
    }

    // ── The privacy rule (§6.30) ────────────────────────────────────────

    public function testAnOrdinaryReaderSeesOnlyThatTheAssetIsLet(): void
    {
        $this->createBooking();

        $events = $this->collect($this->viewer('nobody@test.be'));

        $this->assertCount(1, $events);
        $this->assertSame('Local Saint-Georges — loué', $events[0]->title);
    }

    public function testNothingAboutTheRenterIsEvenBuiltForAnOrdinaryReader(): void
    {
        // The assertion is on the OBJECT, not on a rendering: a field that
        // is never constructed cannot leak through any serialisation.
        $this->createBooking();

        $serialized = (string) json_encode($this->collect($this->viewer('nobody@test.be')));

        foreach ([
            'Jeanne Martin',
            'jeanne.martin@example.be',
            '+32 495 11 22 33',
            'Les Scouts de Nulle Part',
            'LOC-2027-0001',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function testNothingLeaksThroughTheIcsSerialisationEither(): void
    {
        $this->createBooking();
        $ics = (new IcsBuilder())->build('Animateurs', [], $this->collect($this->viewer('nobody@test.be')));

        foreach (['Jeanne', 'example.be', 'Nulle Part', 'LOC-2027'] as $secret) {
            $this->assertStringNotContainsString($secret, $ics);
        }
        $this->assertStringContainsString('lou', $ics);
    }

    public function testNoUrlIsOfferedToAnOrdinaryReader(): void
    {
        // A link into the managed space is itself an invitation to probe it.
        $this->createBooking();

        $this->assertNull($this->collect($this->viewer('nobody@test.be'))[0]->url);
    }

    public function testAnAnonymousReaderGetsTheSameMinimalRendering(): void
    {
        $this->createBooking();

        $events = $this->collect($this->viewer(null, [self::CALENDAR_ID], 'public'));

        $this->assertCount(1, $events);
        $this->assertSame('Local Saint-Georges — loué', $events[0]->title);
        $this->assertNull($events[0]->description);
    }

    public function testAManagerOfTheAssetSeesTheDetail(): void
    {
        $this->addManager('manager@test.be');
        $this->createBooking();

        $events = $this->collect($this->viewer('manager@test.be'));

        $this->assertSame('Local Saint-Georges — Les Scouts de Nulle Part', $events[0]->title);
        $this->assertStringContainsString('LOC-2027-0001', (string) $events[0]->description);
        $this->assertStringContainsString('Jeanne Martin', (string) $events[0]->description);
        $this->assertStringContainsString('20 participants', (string) $events[0]->description);
        $this->assertStringContainsString('/mes-locations/', (string) $events[0]->url);
    }

    public function testUnitStaffSeeTheDetailWithoutAnyPerAssetGrant(): void
    {
        // §6.30: the Staff d'U sees every asset's detail by virtue of their
        // function, not by being granted each hall one at a time.
        $this->makeUnitStaff('cheftaine@unite.test');
        $this->createBooking();

        $event = $this->collect($this->viewer('cheftaine@unite.test'))[0];

        $this->assertStringContainsString('Les Scouts de Nulle Part', $event->title);
        $this->assertNotNull($event->description);
        $this->assertStringContainsString('jeanne.martin@example.be', $event->description);
    }

    public function testUnitStaffSeeTheDetailOfAnAssetTheyWereNeverGranted(): void
    {
        $this->makeUnitStaff('cheftaine@unite.test');
        $otherAssetId = $this->createAsset('Hangar', 'hangar');
        $this->createBooking('LOC-2027-0002', $otherAssetId);

        $titles = array_map(
            static fn(VirtualEvent $event) => $event->title,
            $this->collect($this->viewer('cheftaine@unite.test'))
        );

        $this->assertContains('Hangar — Les Scouts de Nulle Part', $titles);
    }

    public function testAManagerOfAnotherAssetGetsOnlyThePublicRendering(): void
    {
        $otherAssetId = $this->createAsset('Autre', 'autre');
        $this->addManager('autre@test.be', $otherAssetId);
        $this->createBooking();

        $events = $this->collect($this->viewer('autre@test.be'));
        $mine = array_values(array_filter($events, static fn(VirtualEvent $e) => str_contains($e->title, 'Saint-Georges')));

        $this->assertSame('Local Saint-Georges — loué', $mine[0]->title);
        $this->assertNull($mine[0]->description);
    }

    public function testRevokingAManagersGrantRemovesTheDetailImmediately(): void
    {
        // Rights are recomputed at generation time, never remembered from
        // when a feed token was issued.
        $memberId = $this->addManager('manager@test.be');
        $this->createBooking();
        $this->assertStringContainsString(
            'Nulle Part',
            $this->collect($this->viewer('manager@test.be'))[0]->title
        );

        $this->managerRepository->revoke($this->assetId, $memberId);

        $this->assertSame(
            'Local Saint-Georges — loué',
            $this->collect($this->viewer('manager@test.be'))[0]->title
        );
    }

    public function testAChiefWithNoGrantIsAnOrdinaryReader(): void
    {
        // No role bypass: `chief` alone never substitutes for managing it.
        $this->createBooking();

        $this->assertSame(
            'Local Saint-Georges — loué',
            $this->collect($this->viewer('chief@test.be', [self::CALENDAR_ID], 'chief'))[0]->title
        );
    }

    // ── Which calendar (§6.30) ──────────────────────────────────────────

    public function testAnAssetPublishingElsewhereIsAbsentFromThisCalendar(): void
    {
        // Without this, a bare feed for the Animateurs calendar would
        // quietly carry another calendar's occupancy.
        $this->createBooking();

        $this->assertSame([], $this->collect($this->viewer('nobody@test.be', [self::OTHER_CALENDAR_ID])));
    }

    public function testAnAssetWithPublicationOffIsNeverPublished(): void
    {
        $this->assetRepository->saveCalendarPublication($this->assetId, false, [self::CALENDAR_ID], PublishFrom::CONFIRMATION);
        $this->createBooking();

        $this->assertSame([], $this->collect($this->viewer('nobody@test.be')));
    }

    public function testAReaderLookingAtNoCalendarSeesNothing(): void
    {
        $this->createBooking();

        $this->assertSame([], $this->collect($this->viewer('manager@test.be', [])));
    }

    // ── Confirmation vs hold (§6.30) ────────────────────────────────────

    public function testWithPublishFromConfirmationAHeldRequestIsNotPublished(): void
    {
        // A request that comes to nothing must never have appeared.
        $this->createBooking(
            'LOC-2027-0001',
            null,
            BookingStatus::RECEIVED,
            (new \DateTimeImmutable())->modify('+2 days')
        );

        $this->assertSame([], $this->collect($this->viewer('nobody@test.be')));
    }

    public function testWithPublishFromHoldAHeldRequestIsPublished(): void
    {
        $this->assetRepository->saveCalendarPublication($this->assetId, true, [self::CALENDAR_ID], PublishFrom::HOLD);
        $this->createBooking(
            'LOC-2027-0001',
            null,
            BookingStatus::RECEIVED,
            (new \DateTimeImmutable())->modify('+2 days')
        );

        $this->assertCount(1, $this->collect($this->viewer('nobody@test.be')));
    }

    public function testAConfirmedBookingIsPublishedUnderEitherSetting(): void
    {
        // The case both options agree on.
        foreach ([PublishFrom::CONFIRMATION, PublishFrom::HOLD] as $setting) {
            $this->assetRepository->saveCalendarPublication($this->assetId, true, [self::CALENDAR_ID], $setting);
            $this->pdo->exec('DELETE FROM rental_bookings');
            $this->createBooking();

            $this->assertCount(1, $this->collect($this->viewer('nobody@test.be')), $setting->value);
        }
    }

    public function testALapsedHoldStopsBeingPublished(): void
    {
        $this->assetRepository->saveCalendarPublication($this->assetId, true, [self::CALENDAR_ID], PublishFrom::HOLD);
        $this->createBooking(
            'LOC-2027-0001',
            null,
            BookingStatus::RECEIVED,
            (new \DateTimeImmutable())->modify('-1 hour')
        );

        $this->assertSame([], $this->collect($this->viewer('nobody@test.be')));
    }

    // ── Cancellation (§6.32) ────────────────────────────────────────────

    public function testACancelledBookingProducesACancelledEventNotAnOmission(): void
    {
        // A subscriber who already has it must be told it is off; dropping
        // it from the feed leaves it in their calendar forever.
        $this->createBooking('LOC-2027-0001', null, BookingStatus::CANCELLED);

        $events = $this->collect($this->viewer('nobody@test.be'));

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->isCancelled);
    }

    public function testACancelledEventSerialisesAsCancelled(): void
    {
        $this->createBooking('LOC-2027-0001', null, BookingStatus::CANCELLED);
        $ics = (new IcsBuilder())->build('Animateurs', [], $this->collect($this->viewer('nobody@test.be')));

        $this->assertStringContainsString('STATUS:CANCELLED', $ics);
    }

    public function testACancelledEventCarriesAHigherSequenceSoClientsReplaceTheirCopy(): void
    {
        $this->createBooking('LOC-2027-0001', null, BookingStatus::CANCELLED);

        $this->assertSame(1, $this->collect($this->viewer('nobody@test.be'))[0]->sequence);
    }

    public function testARequestRefusedBeforeItWasEverPublishedIsNotAnnounced(): void
    {
        // Nothing was ever shown, so there is nothing to cancel — and
        // announcing a cancellation for it would disclose that somebody
        // asked.
        $this->createBooking('LOC-2027-0001', null, BookingStatus::RECEIVED);

        $this->assertSame([], $this->collect($this->viewer('nobody@test.be')));
    }

    // ── Manual blocks ───────────────────────────────────────────────────

    public function testABlockAppearsAsUnavailableToAnOrdinaryReader(): void
    {
        $this->blockRepository->create($this->assetId, '2027-07-10', '2027-07-12', 1, 'Chantier toiture', null);

        $events = $this->collect($this->viewer('nobody@test.be'));

        $this->assertCount(1, $events);
        $this->assertSame('Local Saint-Georges — indisponible', $events[0]->title);
        // Why the unit cannot let its hall is nobody else's business.
        $this->assertNull($events[0]->description);
    }

    public function testAManagerSeesWhyTheAssetIsBlocked(): void
    {
        $this->addManager('manager@test.be');
        $this->blockRepository->create($this->assetId, '2027-07-10', '2027-07-12', 1, 'Chantier toiture', null);

        $this->assertSame(
            'Chantier toiture',
            $this->collect($this->viewer('manager@test.be'))[0]->description
        );
    }

    // ── Times (§6.30) ───────────────────────────────────────────────────

    public function testTheAssetsArrivalAndDepartureTimesAreUsedWhenKnown(): void
    {
        $this->createBooking();

        $event = $this->collect($this->viewer('nobody@test.be'))[0];

        $this->assertSame('18:00', $event->startTime);
        $this->assertSame('11:00', $event->endTime);
        $this->assertFalse($event->isAllDay());
    }

    public function testWithoutTimesTheOccupancyIsAnAllDayEvent(): void
    {
        // The honest rendering of "we do not know the hour".
        $bare = $this->assetRepository->create('Terrain', 'Terrain', 'terrain', null, 1, null, null, null, true);
        $this->assetRepository->saveCalendarPublication($bare, true, [self::CALENDAR_ID], PublishFrom::CONFIRMATION);
        $this->createBooking('LOC-2027-0002', $bare);

        $events = array_values(array_filter(
            $this->collect($this->viewer('nobody@test.be')),
            static fn(VirtualEvent $e) => str_contains($e->title, 'Terrain')
        ));

        $this->assertTrue($events[0]->isAllDay());
    }

    // ── Windows and N+1 (§6.31) ─────────────────────────────────────────

    public function testABookingOutsideTheWindowIsNotReturned(): void
    {
        $this->createBooking();

        $this->assertSame([], $this->provider->findVirtualEvents(
            new \DateTimeImmutable('2027-09-01'),
            new \DateTimeImmutable('2027-09-30'),
            $this->viewer('nobody@test.be')
        ));
    }

    public function testABookingTouchingTheWindowEdgeIsReturned(): void
    {
        $this->createBooking();

        $this->assertCount(1, $this->provider->findVirtualEvents(
            new \DateTimeImmutable('2027-07-03'),
            new \DateTimeImmutable('2027-07-20'),
            $this->viewer('nobody@test.be')
        ));
    }

    public function testAWholeWindowCostsAFixedNumberOfQueriesWhateverItContains(): void
    {
        // §6.31: one call for a range, never one per day or per entity. Six
        // assets and twelve bookings must cost exactly what one of each
        // does.
        for ($i = 2; $i <= 6; $i++) {
            $assetId = $this->createAsset('Bien ' . $i, 'bien-' . $i);
            $this->createBooking('LOC-2027-01' . $i, $assetId);
            $this->blockRepository->create($assetId, '2027-07-20', '2027-07-22', 1, null, null);
        }
        $this->createBooking('LOC-2027-0001');

        // Counted through PDO's own statement construction: every read in
        // this path goes through prepare() or query().
        $before = $this->countQueriesForAWindow();

        // One asset only, for comparison.
        $this->pdo->exec('DELETE FROM rental_bookings WHERE reference != "LOC-2027-0001"');
        $this->pdo->exec('DELETE FROM rental_blocks');
        $this->pdo->exec('DELETE FROM rental_assets WHERE id != ' . $this->assetId);
        $after = $this->countQueriesForAWindow();

        // The property that matters: six assets, six blocks and six
        // bookings cost exactly what one of each does.
        $this->assertSame($after, $before, 'The query count must not grow with the number of assets or bookings.');

        // And the fixed cost itself stays small. The budget below is what
        // one generation legitimately needs:
        //   1. findPublishingToCalendar() — which assets publish at all
        //   2. the calendars those assets publish onto, for the whole set
        //      in one IN() — an asset now has several, and resolving them
        //      per asset is exactly the regression this test exists for
        //   3-4. the reader's identity, resolved ONCE for the whole
        //        generation by RentalAuthorizationService — never once per
        //        asset, which is the property that actually matters here
        //   5. every booking of every publishing asset, in one range query
        //   6. every block of those assets, likewise
        // A regression that reintroduced a per-asset or per-day query would
        // break the assertion above; this one catches a new fixed query
        // creeping into the path.
        $this->assertLessThanOrEqual(self::WINDOW_QUERY_BUDGET, $before);
    }

    /**
     * The fixed number of statements one whole-window generation may cost,
     * whatever the window contains. See the itemised list above.
     */
    private const WINDOW_QUERY_BUDGET = 6;

    /**
     * Counts the statements one whole-window generation issues, by building
     * the provider on a PDO that tallies `prepare()` and `query()`.
     */
    private function countQueriesForAWindow(): int
    {
        $counter = new class ($this->pdo) extends \PDO {
            public int $count = 0;

            public function __construct(private \PDO $inner)
            {
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->count++;

                return $this->inner->prepare($query, $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $this->count++;

                return $this->inner->query($query);
            }

            public function exec(string $statement): int|false
            {
                return $this->inner->exec($statement);
            }
        };

        $memberService = new MemberService(
            new MemberYearRepository($counter),
            $this->encryption,
            Connection::withPdo($counter)
        );
        $assetRepository = new RentalAssetRepository($counter, $this->encryption);
        $provider = new RentalVirtualEventProvider(
            $assetRepository,
            new RentalBookingRepository($counter, $this->encryption),
            new RentalBlockRepository($counter),
            new RentalAuthorizationService(
                $memberService,
                $assetRepository,
                new RentalAssetManagerRepository($counter)
            ),
            'https://unite.test'
        );

        $provider->findVirtualEvents(
            new \DateTimeImmutable('2027-06-01'),
            new \DateTimeImmutable('2027-08-31'),
            $this->viewer('nobody@test.be')
        );

        return $counter->count;
    }

    // ── Several calendars at once ───────────────────────────────────────

    public function testAnAssetPublishesOntoEveryCalendarItWasGiven(): void
    {
        // A hall is very often both the unit's business and one section's.
        // With a single `calendar_id` the other group simply never saw it.
        $this->assetRepository->saveCalendarPublication(
            $this->assetId,
            true,
            [self::CALENDAR_ID, self::CALENDAR_ID + 1],
            PublishFrom::CONFIRMATION
        );
        $this->createBooking();

        $reader = $this->viewer('nobody@test.be', [self::CALENDAR_ID, self::CALENDAR_ID + 1]);
        $calendarIds = array_map(
            static fn($event) => $event->calendarId,
            $this->collect($reader)
        );

        sort($calendarIds);
        $this->assertSame([self::CALENDAR_ID, self::CALENDAR_ID + 1], $calendarIds);
    }

    public function testTheSameBookingOnTwoCalendarsSurvivesTheRegistrysDeduplication(): void
    {
        // The registry keys events by UID alone, so two events sharing one
        // would silently collapse into whichever calendar came first —
        // which is why the UID carries the calendar.
        $this->assetRepository->saveCalendarPublication(
            $this->assetId,
            true,
            [self::CALENDAR_ID, self::CALENDAR_ID + 1],
            PublishFrom::CONFIRMATION
        );
        $this->createBooking();

        $registry = new VirtualEventRegistry();
        $registry->register($this->provider);

        $events = $registry->collect(
            new \DateTimeImmutable('2027-06-01'),
            new \DateTimeImmutable('2027-08-31'),
            $this->viewer('nobody@test.be', [self::CALENDAR_ID, self::CALENDAR_ID + 1])
        );

        $this->assertCount(2, $events);
    }

    public function testAReaderOnlySeesTheCalendarsTheyAreActuallyLookingAt(): void
    {
        // The filter that stops a bare feed for one calendar quietly
        // carrying the occupancy of an asset published onto another.
        $this->assetRepository->saveCalendarPublication(
            $this->assetId,
            true,
            [self::CALENDAR_ID, self::CALENDAR_ID + 1],
            PublishFrom::CONFIRMATION
        );
        $this->createBooking();

        $events = $this->collect($this->viewer('nobody@test.be', [self::CALENDAR_ID + 1]));

        $this->assertCount(1, $events);
        $this->assertSame(self::CALENDAR_ID + 1, $events[0]->calendarId);
    }

    public function testUntickingThePublicationBoxStopsEveryCalendarAtOnce(): void
    {
        $this->assetRepository->saveCalendarPublication(
            $this->assetId,
            false,
            [self::CALENDAR_ID, self::CALENDAR_ID + 1],
            PublishFrom::CONFIRMATION
        );
        $this->createBooking();

        $this->assertSame(
            [],
            $this->collect($this->viewer('nobody@test.be', [self::CALENDAR_ID, self::CALENDAR_ID + 1]))
        );
    }

    public function testABlockAlsoReachesEveryCalendar(): void
    {
        $this->assetRepository->saveCalendarPublication(
            $this->assetId,
            true,
            [self::CALENDAR_ID, self::CALENDAR_ID + 1],
            PublishFrom::CONFIRMATION
        );
        $this->blockRepository->create($this->assetId, '2027-07-10', '2027-07-12', 1, 'Chantier', null);

        $events = $this->collect($this->viewer('nobody@test.be', [self::CALENDAR_ID, self::CALENDAR_ID + 1]));

        $this->assertCount(2, $events);
        $this->assertSame(
            [$events[0]->uid, $events[1]->uid],
            array_unique([$events[0]->uid, $events[1]->uid]),
            'Two calendars means two distinct UIDs.'
        );
    }

    // ── Stable UIDs and deduplication (§6.32) ───────────────────────────

    public function testTheUidIsDerivedFromTheBookingAndIsStable(): void
    {
        $booking = $this->createBooking();

        $first = $this->collect($this->viewer('nobody@test.be'))[0]->uid;
        $second = $this->collect($this->viewer('nobody@test.be'))[0]->uid;

        $this->assertSame($first, $second);
        $this->assertSame(RentalVirtualEventProvider::bookingUid($booking->id, self::CALENDAR_ID), $first);
    }

    public function testTheSameBookingReachingAReaderTwiceIsOneEvent(): void
    {
        // A reader subscribed to two calendars that both carry it must see
        // one event, not two.
        $booking = $this->createBooking();
        $event = $this->collect($this->viewer('nobody@test.be'))[0];

        $merged = VirtualEventRegistry::mergeUnique([$event], [$event]);

        $this->assertCount(1, $merged);
    }

    public function testABookingAndABlockNeverShareAUid(): void
    {
        $this->assertNotSame(
            RentalVirtualEventProvider::bookingUid(1, self::CALENDAR_ID),
            RentalVirtualEventProvider::blockUid(1, self::CALENDAR_ID)
        );
    }

    // ── The registry (§6.31) ────────────────────────────────────────────

    public function testTheRegistryDeduplicatesByUidAcrossProviders(): void
    {
        $registry = new VirtualEventRegistry();
        $registry->register($this->provider);
        $registry->register($this->provider);
        $this->createBooking();

        $events = $registry->collect(
            new \DateTimeImmutable('2027-06-01'),
            new \DateTimeImmutable('2027-08-31'),
            $this->viewer('nobody@test.be')
        );

        $this->assertCount(1, $events);
    }

    public function testAProviderThatThrowsNeverTakesTheCalendarDown(): void
    {
        // A calendar missing one module's events beats a calendar that 500s
        // for everyone.
        $registry = new VirtualEventRegistry();
        $registry->register(new class implements \Modules\Calendar\Api\VirtualEventProviderInterface {
            public function providerId(): string
            {
                return 'exploding';
            }

            public function findVirtualEvents(
                \DateTimeImmutable $windowStart,
                \DateTimeImmutable $windowEnd,
                VirtualEventViewer $viewer
            ): array {
                throw new \RuntimeException('boom');
            }
        });
        $registry->register($this->provider);
        $this->createBooking();

        $events = $registry->collect(
            new \DateTimeImmutable('2027-06-01'),
            new \DateTimeImmutable('2027-08-31'),
            $this->viewer('nobody@test.be')
        );

        $this->assertCount(1, $events);
    }

    public function testAnEmptyRegistryReportsItselfAsSuchSoCallersCanSkipTheWork(): void
    {
        $registry = new VirtualEventRegistry();

        $this->assertFalse($registry->hasProviders());
        $registry->register($this->provider);
        $this->assertTrue($registry->hasProviders());
    }

    // ── The renter's own feed (§6.32) ───────────────────────────────────

    public function testTheRenterFeedCarriesTheirBookingAndTheirContactsOnly(): void
    {
        $booking = $this->createBooking();
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        $event = (new RenterFeedBuilder('https://unite.test'))->build(
            $booking,
            $asset,
            'the-token',
            [['manager' => null, 'display_name' => 'Marc', 'email' => 'marc@test.be', 'phone' => '+32 470 1']]
        );

        $this->assertSame('Local Saint-Georges', $event->title);
        $this->assertStringContainsString('LOC-2027-0001', (string) $event->description);
        $this->assertStringContainsString('Marc', (string) $event->description);
        $this->assertStringContainsString('+32 470 00 00 00', (string) $event->description);
        $this->assertStringContainsString('/locations/suivi/', (string) $event->url);
    }

    public function testTheRenterFeedSharesTheBookingsUidSoOneBookingIsOneEvent(): void
    {
        $booking = $this->createBooking();
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        $event = (new RenterFeedBuilder())->build($booking, $asset, 'the-token');

        // The renter's own feed is its own calendar, so its UIDs are the
        // ones the provider would give on calendar 0 — see RenterFeedBuilder.
        $this->assertSame(RentalVirtualEventProvider::bookingUid($booking->id, 0), $event->uid);
    }

    public function testACancelledBookingStillProducesAFeedRatherThanAnError(): void
    {
        $booking = $this->createBooking('LOC-2027-0001', null, BookingStatus::CANCELLED);
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        $event = (new RenterFeedBuilder())->build($booking, $asset, 'the-token');
        $ics = (new IcsBuilder())->build('Location', [], [$event]);

        $this->assertTrue($event->isCancelled);
        $this->assertStringContainsString('STATUS:CANCELLED', $ics);
    }

    public function testARequestNobodyHasAgreedToYetIsPublishedAsTentative(): void
    {
        // Published as CONFIRMED it reads, in the renter's own calendar,
        // exactly like the week they have been promised — and a refusal a
        // fortnight later leaves them having planned around it.
        $booking = $this->createBooking('LOC-2027-0001', null, BookingStatus::RECEIVED);
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        $event = (new RenterFeedBuilder())->build($booking, $asset, 'the-token');
        $ics = (new IcsBuilder())->build('Location', [], [$event]);

        $this->assertTrue($event->isTentative);
        $this->assertStringContainsString('STATUS:TENTATIVE', $ics);
    }

    public function testAConfirmedBookingIsPublishedAsConfirmed(): void
    {
        $booking = $this->createBooking('LOC-2027-0001', null, BookingStatus::CONFIRMED);
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        $event = (new RenterFeedBuilder())->build($booking, $asset, 'the-token');
        $ics = (new IcsBuilder())->build('Location', [], [$event]);

        $this->assertFalse($event->isTentative);
        $this->assertStringContainsString('STATUS:CONFIRMED', $ics);
    }

    public function testACancelledBookingIsCancelledEvenThoughItIsNotFirmEither(): void
    {
        // Cancelled wins: a subscriber who already has the event has to be
        // told it is off, not that it is undecided.
        $booking = $this->createBooking('LOC-2027-0001', null, BookingStatus::CANCELLED);
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        $ics = (new IcsBuilder())->build(
            'Location',
            [],
            [(new RenterFeedBuilder())->build($booking, $asset, 'the-token')]
        );

        $this->assertStringContainsString('STATUS:CANCELLED', $ics);
        $this->assertStringNotContainsString('STATUS:TENTATIVE', $ics);
    }

    public function testTheRenterFeedNeverCarriesAPrice(): void
    {
        $booking = $this->createBooking();
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        $event = (new RenterFeedBuilder())->build($booking, $asset, 'the-token');

        $this->assertStringNotContainsString('€', (string) $event->description);
    }
}
