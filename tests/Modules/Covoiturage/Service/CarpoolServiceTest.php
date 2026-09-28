<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Service;

use Core\Geo\GeoPointException;
use Core\Security\Role;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Calendar\Api\EventSummary;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Service\CarpoolException;
use Modules\Covoiturage\Service\CarpoolService;
use Modules\Covoiturage\Service\DuplicateCarpoolException;
use Modules\Covoiturage\Service\LocationMismatchException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;
use Tests\Modules\Covoiturage\FakeCalendar;

/**
 * Organising a carpool: who may, the two guards at creation, the place and
 * its point, and why a carpool with cars cannot be deleted.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CarpoolServiceTest extends TestCase
{
    private \PDO $pdo;
    private CarpoolRepository $carpools;
    private CarpoolService $service;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $this->pdo->exec(
            "INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', "
            . (int) $this->pdo->lastInsertId() . ", 'Louveteaux')"
        );
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->carpools = new CarpoolRepository($this->pdo);
        $this->service = new CarpoolService(
            $this->carpools,
            new OfferRepository($this->pdo, H::encryption()),
            H::sections($this->pdo),
            H::members($this->pdo),
            new FakeCalendar([
                new EventSummary(501, "Fête d'unité — Baladins", 'Baladins', H::day(20), H::day(20), null, 'Plaine de Basse-Wavre', 10, 'Baladins'),
                new EventSummary(502, "Fête d'unité — Louveteaux", 'Louveteaux', H::day(20), H::day(20), null, 'Plaine de Basse-Wavre', 20, 'Louveteaux'),
                new EventSummary(503, 'Week-end — Éclaireurs', 'Éclaireurs', H::day(30), H::day(31), null, 'Gîte de Han', 30, 'Éclaireurs'),
                new EventSummary(504, 'Réunion', 'Animateurs', H::day(5), H::day(5), null, null, null, null),
            ])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'event_ids' => ['501', '502'],
            'address' => '',
            'outbound_date' => H::day(20),
            'return_date' => '',
        ], $overrides);
    }

    public function testOnlyAChiefCreatesACarpool(): void
    {
        $this->expectException(CarpoolException::class);
        $this->service->create($this->input(), H::viewer(1, Role::INTENDANT));
    }

    public function testOneCarpoolServesAnEventReplicatedOnSeveralCalendars(): void
    {
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        $carpool = $this->carpools->findById($id);

        $this->assertCount(2, $carpool?->events ?? []);
        $this->assertSame([10, 20], $carpool->sectionIds());
        // The address is taken from the events when left empty.
        $this->assertSame('Plaine de Basse-Wavre', $carpool->address);
        $this->assertSame("Fête d'unité", $carpool->title());
    }

    public function testAnEventThatAlreadyHasACarpoolOffersToJoinIt(): void
    {
        $existing = $this->service->create($this->input(['event_ids' => ['501']]), H::viewer(1, Role::CHIEF));

        try {
            $this->service->create($this->input(['event_ids' => ['501', '502']]), H::viewer(2, Role::CHIEF));
            $this->fail('A second carpool was created for the same event.');
        } catch (DuplicateCarpoolException $e) {
            $this->assertSame($existing, $e->existingCarpoolId);
            $this->assertStringContainsString('Rejoignez-le', $e->getMessage());
        }

        // Editing the existing carpool to add the other event is fine.
        $carpool = $this->carpools->findById($existing);
        $this->assertNotNull($carpool);
        $this->service->update($carpool, $this->input(['event_ids' => ['501', '502']]), H::viewer(1, Role::CHIEF));
        $this->assertCount(2, $this->carpools->findById($existing)?->events ?? []);
    }

    public function testTheSearchWarnsOnAnEventThatAlreadyHasACarpool(): void
    {
        $this->service->create($this->input(['event_ids' => ['502']]), H::viewer(1, Role::CHIEF));

        $rows = [];
        foreach ($this->service->searchEvents('fête', H::viewer(1, Role::CHIEF)) as $row) {
            $rows[$row->id] = $row;
        }

        $this->assertNull($rows[501]->warning);
        $this->assertStringContainsString('existe déjà', (string) $rows[502]->warning);
        $this->assertSame('Louveteaux', $rows[502]->badge);
        $this->assertStringContainsString('calendrier Louveteaux', (string) $rows[502]->subtitle);
    }

    public function testEventsGivingDifferentPlacesNeedAConfirmation(): void
    {
        try {
            $this->service->create($this->input(['event_ids' => ['501', '503']]), H::viewer(1, Role::CHIEF));
            $this->fail('Two destinations went through unconfirmed.');
        } catch (LocationMismatchException $e) {
            $this->assertSame(['Plaine de Basse-Wavre', 'Gîte de Han'], $e->locations);
        }

        $id = $this->service->create(
            $this->input(['event_ids' => ['501', '503'], 'confirm_locations' => '1', 'address' => 'Gîte de Han']),
            H::viewer(1, Role::CHIEF)
        );
        $this->assertSame('Gîte de Han', $this->carpools->findById($id)?->address);
    }

    /**
     * **Issue #650 replaced a required field with a deduction.** A carpool
     * with no event used to be refused until a chief picked « Section
     * concernée » from a list; now nothing is asked, and the section is the
     * creator's own — so the carpool saves, and saves with that section.
     *
     * The refusal this test used to assert is gone on purpose: the message
     * it looked for ("Sans évènement, choisissez la section concernée…")
     * described a field that no longer exists.
     */
    public function testWithoutAnEventTheCarpoolTakesItsCreatorsOwnSection(): void
    {
        H::linkAccountToSection($this->pdo, 1, $this->sectionId);

        $id = $this->service->create(
            $this->input(['event_ids' => [], 'address' => 'Bastogne']),
            H::viewer(1, Role::CHIEF, [$this->sectionId])
        );

        $this->assertSame([$this->sectionId], $this->carpools->findById($id)?->sectionIds());
    }

    /**
     * **A hidden section still governs its chief's carpools** — raised in
     * review of #650, and the asymmetry behind it is real, not hypothetical.
     *
     * `SectionStaffAuthorizationService::getStaffedSections()` resolves a
     * chief's sections through `getSection()`, which filters on neither
     * `is_active` nor `is_visible`. A chief therefore staffs a hidden
     * section. But `getAllWithBranches()` — the list every section picker
     * passes to `SectionPickerHelper` — drops it. Resolving the creator's
     * section against that list stored NO section for such a chief, and
     * their section's staff lost the passengers this change exists to show
     * them.
     *
     * So the resolution goes through the Desk code and
     * `SectionService::findByDeskCode()`, which filters on neither flag.
     * Put `getAllWithBranches()` back in `creatorSectionId()` and this test
     * goes red on its own.
     */
    public function testAHiddenSectionIsStillTheCreatorsOwn(): void
    {
        $this->pdo->prepare('UPDATE sections SET is_visible = 0 WHERE id = ?')->execute([$this->sectionId]);
        H::linkAccountToSection($this->pdo, 1, $this->sectionId);

        $id = $this->service->create(
            $this->input(['event_ids' => [], 'address' => 'Bastogne']),
            H::viewer(1, Role::CHIEF, [$this->sectionId])
        );

        $this->assertSame(
            [$this->sectionId],
            $this->carpools->findById($id)?->sectionIds(),
            "a chief of a hidden section created a carpool their own section cannot see"
        );
    }

    /**
     * **The form's section is never read (issue #650).** The field is gone,
     * but a hand-built POST can still carry `section_id` — and honouring it
     * would let the sender hand ANY section's animateurs the passengers of
     * a carpool, which is the one thing this deduction must not allow.
     *
     * The posted id here is a real, existing section, so nothing but the
     * "don't read the input" rule can refuse it: the carpool must come out
     * with the creator's section, not with the one posted.
     */
    public function testAPostedSectionIsIgnored(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('ECL', 'Éclaireurs', 40)");
        $this->pdo->exec(
            "INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('ECL01', "
            . (int) $this->pdo->lastInsertId() . ", 'Éclaireurs')"
        );
        $otherSectionId = (int) $this->pdo->lastInsertId();
        H::linkAccountToSection($this->pdo, 1, $this->sectionId);

        $id = $this->service->create(
            $this->input([
                'event_ids' => [],
                'address' => 'Bastogne',
                'section_id' => (string) $otherSectionId,
            ]),
            H::viewer(1, Role::CHIEF, [$this->sectionId])
        );

        $this->assertSame(
            [$this->sectionId],
            $this->carpools->findById($id)?->sectionIds(),
            'the section posted by hand was stored, so a request can choose who sees the passengers'
        );
    }

    /**
     * **The main-function rule is blind to role; the access check is not**
     * (raised in review of #664). `MemberProfile::getMainFunction()` returns
     * whichever function Desk flagged « Fonction principale », or simply the
     * first one, with no regard for its role. `CarpoolViewer::isStaffOf()`
     * reads `staffedSectionIds`, which `StaffedSectionRepository` builds
     * WITH `f.role IN ('chief', 'admin')` — « without the role filter, every
     * animé would come back as an animateur of their own section », says its
     * own comment.
     *
     * So the two can name different sections. Here the creator's
     * main-flagged function is in the Louveteaux section while they staff
     * only another one: freezing the carpool onto Louveteaux would hand that
     * staff the passengers of children they do not follow, and leave the
     * creator's own colleagues with nothing — the leak this whole change was
     * written to avoid, reached through the main-function flag instead of
     * through the « first available section » fallback.
     *
     * Remove the intersection with `$viewer->staffedSectionIds` from
     * `creatorSectionId()` and this test goes red on its own.
     */
    public function testASectionTheCreatorDoesNotStaffIsNeverFrozenOntoTheCarpool(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('ECL', 'Éclaireurs', 40)");
        $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)')
            ->execute(['ECL01', (int) $this->pdo->lastInsertId(), 'Éclaireurs']);
        $staffedElsewhere = (int) $this->pdo->lastInsertId();

        // Their Desk main function names Louveteaux; the sections they
        // actually staff hold only Éclaireurs.
        H::linkAccountToSection($this->pdo, 1, $this->sectionId);
        $viewer = H::viewer(1, Role::CHIEF, [$staffedElsewhere]);

        $this->assertNull(
            $this->service->creatorSectionId($viewer),
            'a section the creator does not staff was named as theirs'
        );

        $id = $this->service->create(
            $this->input(['event_ids' => [], 'address' => 'Bastogne']),
            $viewer
        );
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);
        $this->assertNull($carpool->sectionId, 'that section was frozen onto the carpool anyway');
        $this->assertFalse(
            H::viewer(20, Role::CHIEF, [$this->sectionId])->seesPassengersOf($carpool),
            'the staff of a section the creator does not staff was given the passengers'
        );
    }

    /**
     * A creator with no section of their own: the carpool is saved WITHOUT
     * one, never with a section picked in its place — the reason #650 asked
     * for a variant of SectionPickerHelper::resolveDefault() instead of
     * reusing it, since its « first available section » fallback would have
     * handed these passengers to whichever section sorts first.
     */
    public function testACreatorWithNoSectionLeavesTheCarpoolWithNone(): void
    {
        H::linkAccountToSection($this->pdo, 1, null);

        $id = $this->service->create(
            $this->input(['event_ids' => [], 'address' => 'Bastogne']),
            H::viewer(1, Role::CHIEF)
        );

        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);
        $this->assertNull($carpool->sectionId, 'a section was chosen for a creator who has none');
        $this->assertSame([], $carpool->sectionIds());
    }

    /**
     * An account linked to no member at all — the same requirement as the
     * test above, through the other door the rule leaves open.
     */
    public function testACreatorLinkedToNoMemberLeavesTheCarpoolWithNoSection(): void
    {
        $id = $this->service->create(
            $this->input(['event_ids' => [], 'address' => 'Bastogne']),
            H::viewer(1, Role::CHIEF)
        );

        $this->assertNull($this->carpools->findById($id)?->sectionId);
    }

    /**
     * **With events, the creator's section is ADDED, not replaced** — the
     * union issue #650 asked for. A carpool for two Unit-wide events keeps
     * both their sections and gains its creator's, so a chief who organised
     * the trip does not lose sight of it.
     */
    public function testWithEventsTheCreatorsSectionIsAddedToTheirs(): void
    {
        H::linkAccountToSection($this->pdo, 1, $this->sectionId);

        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF, [$this->sectionId]));

        $sectionIds = $this->carpools->findById($id)?->sectionIds() ?? [];
        // 10 and 20 are the sections of events 501 and 502 in setUp().
        $expected = [$this->sectionId, 10, 20];
        sort($sectionIds);
        sort($expected);
        $this->assertSame($expected, $sectionIds);
    }

    /**
     * **The section is frozen at creation (#650).** Editing must not
     * recompute it — neither for the creator, who may have changed section
     * since, nor for another chief, whose own section would otherwise
     * silently replace it and take the first one's staff off the carpool.
     */
    public function testEditingNeverRecomputesTheSection(): void
    {
        H::linkAccountToSection($this->pdo, 1, $this->sectionId);
        $id = $this->service->create(
            $this->input(['event_ids' => [], 'address' => 'Bastogne']),
            H::viewer(1, Role::CHIEF, [$this->sectionId])
        );

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('ECL', 'Éclaireurs', 40)");
        $this->pdo->exec(
            "INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('ECL01', "
            . (int) $this->pdo->lastInsertId() . ", 'Éclaireurs')"
        );
        $otherSectionId = (int) $this->pdo->lastInsertId();
        H::linkAccountToSection($this->pdo, 2, $otherSectionId);

        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);
        $this->service->update(
            $carpool,
            $this->input(['event_ids' => [], 'address' => 'Bastogne 2']),
            H::viewer(2, Role::CHIEF, [$this->sectionId])
        );

        $after = $this->carpools->findById($id);
        $this->assertSame('Bastogne 2', $after?->address, 'the edit did not go through at all');
        $this->assertSame(
            $this->sectionId,
            $after?->sectionId,
            "the editor's own section replaced the creator's"
        );
    }

    public function testAnEventTheChiefCannotSeeIsRefused(): void
    {
        $this->expectException(CarpoolException::class);
        $this->service->create($this->input(['event_ids' => ['999']]), H::viewer(1, Role::CHIEF));
    }

    public function testTheDatesMakeSense(): void
    {
        foreach ([
            ['outbound_date' => H::day(-1)],
            ['outbound_date' => H::day(10), 'return_date' => H::day(9)],
            ['outbound_date' => 'demain'],
        ] as $dates) {
            try {
                $this->service->create($this->input($dates), H::viewer(1, Role::CHIEF));
                $this->fail('Accepted dates: ' . json_encode($dates));
            } catch (CarpoolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAPointPlacedBeforeSavingIsAHumansPoint(): void
    {
        $id = $this->service->create(
            $this->input(['latitude' => '50,7201', 'longitude' => '4.6412']),
            H::viewer(1, Role::CHIEF)
        );
        $carpool = $this->carpools->findById($id);

        $this->assertSame('50.720100, 4.641200', $carpool?->point?->line());
        $this->assertTrue($carpool->pointIsManual);
    }

    public function testWithoutAPointTheAddressIsLeftToTheGeocodingTask(): void
    {
        $id = $this->service->create($this->input(['latitude' => '', 'longitude' => '']), H::viewer(1, Role::CHIEF));

        $this->assertFalse($this->carpools->findById($id)?->pointIsManual);
        $this->assertSame($id, $this->carpools->findNextToGeocode()?->id);
    }

    public function testHalfAPointIsRefused(): void
    {
        $this->expectException(GeoPointException::class);
        $this->service->create($this->input(['latitude' => '50.72', 'longitude' => '']), H::viewer(1, Role::CHIEF));
    }

    public function testAChangedAddressSendsAnAutomaticPointBackToTheQueue(): void
    {
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        $this->carpools->points()->recordGeocoding($id, new \Core\Geo\GeoPoint(50.7, 4.6), new \DateTimeImmutable());
        $this->assertNull($this->carpools->findNextToGeocode());

        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);
        $this->service->update(
            $carpool,
            $this->input(['address' => 'Plaine de Basse-Wavre, entrée nord', 'latitude' => '50.700000', 'longitude' => '4.600000']),
            H::viewer(1, Role::CHIEF)
        );

        $this->assertSame($id, $this->carpools->findNextToGeocode()?->id);
        $this->assertFalse($this->carpools->findById($id)?->pointIsManual, 'An unchanged point is not a human decision.');
    }

    public function testAPinTheAddressLookupPlacedAndNobodyTouchedIsNotAHumansPoint(): void
    {
        // #642: the form found the address and left the pin on it.
        $id = $this->service->create(
            $this->input([
                'latitude' => '50.7201',
                'longitude' => '4.6412',
                'point_automatic' => '1',
                'point_address' => 'Plaine de Basse-Wavre',
            ]),
            H::viewer(1, Role::CHIEF)
        );
        $carpool = $this->carpools->findById($id);

        $this->assertSame('50.720100, 4.641200', $carpool?->point?->line());
        $this->assertFalse($carpool->pointIsManual);
        $this->assertNull($this->carpools->findNextToGeocode(), 'already found: the task has nothing left to do');
    }

    public function testANewAddressFoundOnThePageReplacesTheAutomaticPoint(): void
    {
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        $this->carpools->points()->recordGeocoding($id, new \Core\Geo\GeoPoint(50.7, 4.6), new \DateTimeImmutable());
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        $this->service->update(
            $carpool,
            $this->input([
                'address' => 'Gîte de Han, rue des Grottes 12',
                'latitude' => '50.125000',
                'longitude' => '5.187000',
                'point_automatic' => '1',
                'point_address' => 'Gîte de Han, rue des Grottes 12',
            ]),
            H::viewer(1, Role::CHIEF)
        );

        $after = $this->carpools->findById($id);
        $this->assertSame('50.125000, 5.187000', $after?->point?->line());
        $this->assertFalse($after->pointIsManual);
        $this->assertNull($this->carpools->findNextToGeocode());
    }

    public function testANewAddressFoundOnTheSameCoordinatesIsStillWrittenBack(): void
    {
        // The page dropped any pin left from the old address, so an
        // automatic point posted after a change of address was found for
        // the new one — even when it lands where the old one was.
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        $this->carpools->points()->recordGeocoding($id, new \Core\Geo\GeoPoint(50.7, 4.6), new \DateTimeImmutable());
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        $this->service->update(
            $carpool,
            $this->input([
                'address' => 'Plaine de Basse-Wavre, entrée nord',
                'latitude' => '50.700000',
                'longitude' => '4.600000',
                'point_automatic' => '1',
                'point_address' => 'Plaine de Basse-Wavre, entrée nord',
            ]),
            H::viewer(1, Role::CHIEF)
        );

        $after = $this->carpools->findById($id);
        $this->assertSame('50.700000, 4.600000', $after?->point?->line());
        $this->assertFalse($after->pointIsManual);
        $this->assertNull($this->carpools->findNextToGeocode(), 'found: nothing left for the task');
    }

    public function testAnOldAddressPinSentBeforeTheLookupAnsweredIsNotTheNewAddressPoint(): void
    {
        // The address was edited and the form sent at once: the untouched
        // pin still stands where the OLD address was found.
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        $this->carpools->points()->recordGeocoding($id, new \Core\Geo\GeoPoint(50.7, 4.6), new \DateTimeImmutable());
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        $this->service->update(
            $carpool,
            $this->input([
                'address' => 'Plaine de Basse-Wavre, entrée nord',
                'latitude' => '50.700000',
                'longitude' => '4.600000',
                'point_automatic' => '1',
                'point_address' => 'Plaine de Basse-Wavre',
            ]),
            H::viewer(1, Role::CHIEF)
        );

        $this->assertSame($id, $this->carpools->findNextToGeocode()?->id, 'left for the task to find');
        $this->assertFalse($this->carpools->findById($id)?->pointIsManual);

        $created = $this->service->create(
            $this->input([
                'event_ids' => [],
                'address' => 'Bastogne, place McAuliffe',
                'latitude' => '50.700000',
                'longitude' => '4.600000',
                'point_automatic' => '1',
                'point_address' => 'Plaine de Basse-Wavre',
            ]),
            H::viewer(1, Role::CHIEF)
        );
        $this->assertNull($this->carpools->findById($created)?->point, 'a stale pin is not saved on creation either');
    }

    public function testAnAutomaticPinDroppedForANewAddressLeavesThePointToTheTask(): void
    {
        // The page dropped the old address's untouched pin: empty
        // coordinates, still `point_automatic`. Not a human's « no point ».
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        $this->carpools->points()->recordGeocoding($id, new \Core\Geo\GeoPoint(50.7, 4.6), new \DateTimeImmutable());
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        $this->service->update(
            $carpool,
            $this->input([
                'address' => 'Place du Marché 1, Namur',
                'latitude' => '',
                'longitude' => '',
                'point_automatic' => '1',
            ]),
            H::viewer(1, Role::CHIEF)
        );

        $after = $this->carpools->findById($id);
        $this->assertNull($after?->point);
        $this->assertFalse($after->pointIsManual);
        $this->assertSame($id, $this->carpools->findNextToGeocode()?->id);

        $created = $this->service->create(
            $this->input([
                'event_ids' => [],
                'address' => 'Bastogne',
                'latitude' => '',
                'longitude' => '',
                'point_automatic' => '1',
            ]),
            H::viewer(1, Role::CHIEF)
        );
        $this->assertFalse($this->carpools->findById($created)?->pointIsManual);
    }

    public function testAPinRemovedByHandBeforeAnyPointWasSavedIsLockedAsNoPoint(): void
    {
        // The page placed a pin from the address and the chief clicked
        // « Retirer » before the first save: the task must not put it back.
        $removed = ['latitude' => '', 'longitude' => '', 'point_automatic' => '0', 'point_manual' => '1'];
        $id = $this->service->create($this->input($removed), H::viewer(1, Role::CHIEF));

        $this->assertNull($this->carpools->findById($id)?->point);
        $this->assertTrue($this->carpools->findById($id)?->pointIsManual);
        $this->assertNull($this->carpools->findNextToGeocode());

        // Same on an edit of a carpool the task has not placed yet.
        $alone = ['event_ids' => [], 'address' => 'Bastogne'];
        $other = $this->service->create($this->input($alone), H::viewer(1, Role::CHIEF));
        $carpool = $this->carpools->findById($other);
        $this->assertNotNull($carpool);
        $this->assertFalse($carpool->pointIsManual);
        $this->service->update($carpool, $this->input($alone + $removed), H::viewer(1, Role::CHIEF));

        $this->assertTrue($this->carpools->findById($other)?->pointIsManual);
        $this->assertNull($this->carpools->findNextToGeocode());
    }

    public function testEmptyCoordinatesWithoutTheRemovalLeaveThePointToTheTask(): void
    {
        $id = $this->service->create(
            $this->input(['latitude' => '', 'longitude' => '', 'point_automatic' => '0', 'point_manual' => '0']),
            H::viewer(1, Role::CHIEF)
        );

        $this->assertFalse($this->carpools->findById($id)?->pointIsManual);
        $this->assertSame($id, $this->carpools->findNextToGeocode()?->id);
    }

    public function testTheFormCannotTurnAHandPlacedPointBackIntoAnAutomaticOne(): void
    {
        $id = $this->service->create(
            $this->input(['latitude' => '50.7201', 'longitude' => '4.6412']),
            H::viewer(1, Role::CHIEF)
        );
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        $this->service->update(
            $carpool,
            $this->input([
                'latitude' => '50.100000',
                'longitude' => '4.100000',
                'point_automatic' => '1',
                'point_address' => 'Plaine de Basse-Wavre',
            ]),
            H::viewer(1, Role::CHIEF)
        );

        $after = $this->carpools->findById($id);
        $this->assertSame('50.720100, 4.641200', $after?->point?->line(), 'GeoPointStore\'s fence holds');
        $this->assertTrue($after->pointIsManual);
    }

    public function testTheReturnCannotBeRemovedOnceACarIsProposedForIt(): void
    {
        // Without a return date the return tab disappears, and with it the
        // cars proposed for it and every seat already granted.
        $id = $this->service->create($this->input(['return_date' => H::day(22)]), H::viewer(1, Role::CHIEF));
        H::offer($this->pdo, $id, 7, 4, 'return');
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        try {
            $this->service->update($carpool, $this->input(['return_date' => '']), H::viewer(1, Role::CHIEF));
            $this->fail('The return was removed from under a car proposed for it.');
        } catch (CarpoolException $e) {
            $this->assertSame(CarpoolService::RETURN_REMOVAL_REFUSED, $e->getMessage());
        }
        $this->assertSame(H::day(22), $this->carpools->findById($id)?->returnDate);
    }

    public function testTheReturnCanBeRemovedWhileOnlyOutboundCarsExist(): void
    {
        $id = $this->service->create($this->input(['return_date' => H::day(22)]), H::viewer(1, Role::CHIEF));
        H::offer($this->pdo, $id, 7);
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        $this->service->update($carpool, $this->input(['return_date' => '']), H::viewer(1, Role::CHIEF));

        $this->assertNull($this->carpools->findById($id)?->returnDate);
    }

    public function testACarpoolWithACarCannotBeDeleted(): void
    {
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        H::offer($this->pdo, $id, 7);
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        try {
            $this->service->delete($carpool, H::viewer(1, Role::CHIEF));
            $this->fail('A carpool families organised around was deleted.');
        } catch (CarpoolException $e) {
            $this->assertSame(CarpoolService::DELETE_REFUSED, $e->getMessage());
        }
        $this->assertNotNull($this->carpools->findById($id));
    }

    public function testAnEmptyCarpoolIsDeletedByWhoeverMayOrganizeIt(): void
    {
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        try {
            $this->service->delete($carpool, H::viewer(9, Role::CHIEF, [30]));
            $this->fail('The staff of an unlinked section deleted it.');
        } catch (CarpoolException) {
        }

        $this->service->delete($carpool, H::viewer(9, Role::CHIEF, [20]));
        $this->assertNull($this->carpools->findById($id));
    }

    /**
     * Without the calendar module there is no event to link, and since #650
     * nothing to ask either: the carpool is simply managed by its creator's
     * section. Before, the same call was REFUSED unless the form carried a
     * section — which is what this test used to assert, and which a site
     * without a calendar had no way to make obvious.
     */
    public function testWithoutTheCalendarACarpoolStillTakesItsCreatorsSection(): void
    {
        $service = new CarpoolService(
            $this->carpools,
            new OfferRepository($this->pdo, H::encryption()),
            H::sections($this->pdo),
            H::members($this->pdo),
            null
        );

        $this->assertFalse($service->hasCalendar());
        $this->assertSame([], $service->searchEvents('fête', H::viewer(1, Role::CHIEF, [$this->sectionId])));

        H::linkAccountToSection($this->pdo, 1, $this->sectionId);
        $id = $service->create(
            $this->input(['event_ids' => [], 'address' => 'Bastogne']),
            H::viewer(1, Role::CHIEF, [$this->sectionId])
        );

        $this->assertSame([$this->sectionId], $this->carpools->findById($id)?->sectionIds());

        // And a posted event id is REFUSED rather than dropped: with no
        // calendar there is nothing to resolve it against, so the carpool
        // would silently lose the section the form said it concerned.
        $this->expectException(CarpoolException::class);
        $service->create($this->input(['address' => 'Bastogne']), H::viewer(1, Role::CHIEF, [$this->sectionId]));
    }
}
