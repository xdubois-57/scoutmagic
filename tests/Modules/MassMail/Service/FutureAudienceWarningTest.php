<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\FunctionRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Service\MailingListService;
use Modules\Registration\Api\ProjectedPerson;
use Modules\Registration\Api\ProjectedPopulationProvider;
use Modules\Registration\Api\ProjectedRecipient;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * Sending to a year that has not happened yet.
 *
 * A mailing list has no scout year of its own — it is a set of criteria,
 * and the year is picked in the compose dialog seconds before a send. That
 * is exactly why the warning belongs there and nowhere else: shown for the
 * current year it would be permanent noise, and permanent noise is not
 * read when it finally matters.
 *
 * Two texts, because there are two situations. With the registration
 * module the list already knows about decided passages, accepted
 * registrations and announced departures, and is merely incomplete;
 * without it there is nothing but Desk, which for a year nobody has
 * imported means very little. Both are pinned here word for word, because
 * the difference between them is the whole point.
 *
 * @group database
 */
class FutureAudienceWarningTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private int $currentYearId;
    private int $nextYearId;
    private int $previousYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->previousYearId = $this->insertYear('2025-2026', '2025-09-01', '2026-08-31');
        $this->currentYearId = $this->insertYear('2026-2027', '2026-09-01', '2027-08-31');
        $this->nextYearId = $this->insertYear('2027-2028', '2027-09-01', '2028-08-31');

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOUV', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES ('LOUV1', {$branchId}, 'Louveteaux A', 1)");
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->makeCurrentYearPublic();
    }

    // ── when the warning appears ──────────────────────────────────────

    public function testTheCurrentYearCarriesNoWarning(): void
    {
        $this->assertNull($this->serviceWithProjection()->futureAudienceWarning($this->currentYearId));
    }

    public function testAPastYearCarriesNoWarningEither(): void
    {
        $this->assertNull($this->serviceWithProjection()->futureAudienceWarning($this->previousYearId));
    }

    public function testTheNextYearWithTheRegistrationModuleSaysItIsAProjection(): void
    {
        $this->assertSame(
            "Cette liste vise l'année 2027-2028. Elle tient compte des passages décidés, des inscriptions "
                . "acceptées et des départs annoncés. Tant que tout n'est pas encodé dans Desk, elle reste une "
                . 'projection : des destinataires peuvent manquer ou changer de section.',
            $this->serviceWithProjection()->futureAudienceWarning($this->nextYearId)
        );
    }

    public function testTheNextYearWithoutTheRegistrationModuleSaysItIsDeskOnly(): void
    {
        $this->assertSame(
            "Cette liste vise l'année 2027-2028. Le module Inscriptions étant désactivé, elle ne repose que sur "
                . "les données Desk : elle ne sera exacte qu'une fois l'année suivante entièrement encodée dans Desk.",
            $this->serviceWithoutProjection()->futureAudienceWarning($this->nextYearId)
        );
    }

    /**
     * A scout year row is created the moment something needs one, so next
     * year's id can be LOWER than this year's — the same trap
     * SectionMembershipRepository fell into. Compared by id, this test's
     * fixture would answer backwards.
     */
    public function testFutureIsDecidedByTheCalendarNotByRowIds(): void
    {
        // '2028-2029' created last, so it holds the highest id; '2024-2025'
        // created after it, so a lower id than the year it precedes would
        // not be enough to catch the mistake — this one holds a HIGHER id
        // than the current year while being in the past.
        $farFuture = $this->insertYear('2028-2029', '2028-09-01', '2029-08-31');
        $longPast = $this->insertYear('2024-2025', '2024-09-01', '2025-08-31');

        $service = $this->serviceWithProjection();

        $this->assertTrue($service->isFutureScoutYear($farFuture));
        $this->assertFalse(
            $service->isFutureScoutYear($longPast),
            '2024-2025 holds a higher id than 2026-2027 and is still four years in the past.'
        );
    }

    // ── what a future-year list resolves to ───────────────────────────

    public function testAFutureYearListIsBuiltFromTheProjection(): void
    {
        $members = $this->serviceWithProjection()
            ->resolveMembers('default_active_members', null, null, $this->nextYearId);

        $this->assertSame(
            [
                ['member_id' => 11, 'email' => 'onze@example.be'],
                ['member_id' => 12, 'email' => null],
            ],
            $members,
            'A member the projection expects but whose address is unknown is still a recipient — '
                . 'the address expansion downstream has other places to look.'
        );
    }

    public function testAFutureYearSectionListOnlyHoldsThatSection(): void
    {
        $members = $this->serviceWithProjection()
            ->resolveMembers('default_section', null, $this->sectionId, $this->nextYearId);

        $this->assertSame([['member_id' => 11, 'email' => 'onze@example.be']], $members);
    }

    public function testAnAcceptedRequestIsNotSilentlyPushedThroughAMemberKeyedPipeline(): void
    {
        // The projection knows about a family whose child nobody has
        // encoded yet. Everything downstream — address expansion, the
        // recipient rows, the one-click unsubscribe link — is keyed on a
        // member id, which a request does not have. It is one of the
        // « destinataires [qui] peuvent manquer » the warning names, not a
        // recipient with an invented identity.
        foreach ($this->serviceWithProjection()->resolveMembers('default_active_members', null, null, $this->nextYearId) as $member) {
            $this->assertNotSame(0, $member['member_id']);
        }
        $this->assertCount(
            2,
            $this->serviceWithProjection()->resolveMembers('default_active_members', null, null, $this->nextYearId),
            'Three people are projected; the one that is only a request is not a recipient.'
        );
    }

    public function testTheCurrentYearStillComesFromDeskEvenWithTheProjectionAvailable(): void
    {
        // Nothing in Desk for the current year in this fixture, so an empty
        // list is the right answer — and proves the projection was NOT
        // consulted, since it would have returned two people.
        $this->assertSame(
            [],
            $this->serviceWithProjection()->resolveMembers('default_active_members', null, null, $this->currentYearId)
        );
    }

    public function testAFutureYearFallsBackToDeskWithoutTheRegistrationModule(): void
    {
        // Still empty, but for the other reason: there is no projection to
        // ask, so the list is whatever Desk holds for a year nobody has
        // imported — which is nothing, and the warning says exactly that.
        $this->assertSame(
            [],
            $this->serviceWithoutProjection()->resolveMembers('default_active_members', null, null, $this->nextYearId)
        );
    }

    public function testChiefsAndCustomListsAreNeverAnsweredFromAProjection(): void
    {
        // A projection is animés only and carries no FUNCTION, so it has
        // nothing to say about « les chefs » — answering from it would be
        // inventing recipients.
        $this->assertSame(
            [],
            $this->serviceWithProjection()->resolveMembers('default_chiefs', null, null, $this->nextYearId)
        );
    }

    // ── the configuration page's own sentence ─────────────────────────

    public function testTheConfigurationPageSaysWhichOfTheTwoWorldsItIsIn(): void
    {
        $this->assertStringContainsString(
            'projection du module Inscriptions',
            $this->serviceWithProjection()->futureAudienceNotice()
        );
        $this->assertStringContainsString(
            'ne reposent que sur les données Desk',
            $this->serviceWithoutProjection()->futureAudienceNotice()
        );
    }

    // ── fixture ───────────────────────────────────────────────────────

    private function serviceWithProjection(): MailingListService
    {
        return $this->service($this->projection());
    }

    private function serviceWithoutProjection(): MailingListService
    {
        return $this->service(null);
    }

    private function service(?ProjectedPopulationProvider $projection): MailingListService
    {
        $scoutYearService = new ScoutYearService($this->pdo);

        return new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $this->encryption),
            new SectionService(Connection::withPdo($this->pdo), $this->encryption, new MemberBadgeRepository($this->pdo)),
            new FunctionRepository($this->pdo),
            null,
            $projection,
            new ScoutYearResolver(
                $scoutYearService,
                new SettingService(new SettingRepository($this->pdo)),
                new \Core\Import\MemberYearRepository($this->pdo)
            ),
            $scoutYearService
        );
    }

    /**
     * Three projected people: two members (one with an address, one
     * without) and an accepted request nobody has encoded yet.
     */
    private function projection(): ProjectedPopulationProvider
    {
        $sectionId = $this->sectionId;

        return new class ($sectionId) implements ProjectedPopulationProvider {
            public function __construct(private int $sectionId)
            {
            }

            /** @return array<int, ProjectedPerson> */
            public function projectedPopulation(int $targetScoutYearId): array
            {
                return [
                    new ProjectedPerson(11, null, $this->sectionId, 2, 'female', false, 'continuing'),
                    new ProjectedPerson(12, null, null, null, 'male', false, 'passage'),
                    new ProjectedPerson(null, 77, $this->sectionId, 1, 'other', false, 'registration'),
                ];
            }

            /** @return array<int, \Modules\Registration\Api\ProjectedSectionTotals> */
            public function projectedSectionTotals(int $targetScoutYearId): array
            {
                return [];
            }

            /** @return array<int, ProjectedRecipient> */
            public function reachableRecipients(int $targetScoutYearId): array
            {
                return [
                    new ProjectedRecipient(11, null, 'onze@example.be'),
                    new ProjectedRecipient(null, 77, 'famille@example.be'),
                ];
            }
        };
    }

    private function insertYear(string $label, string $start, string $end): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)');
        $stmt->execute([$label, $start, $end]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * ScoutYearResolver reads the public year from a setting; without it
     * every year would be compared against whatever getCurrentYear()
     * happens to answer, which is not the question this feature asks.
     */
    private function makeCurrentYearPublic(): void
    {
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->currentYearId, 'text', 'Année publique', 'Test.');
        $settingService->set(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->currentYearId);
    }
}
