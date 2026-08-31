<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Member\AdminMemberPageService;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionService;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The blocks of /admin/members/{id} that this iteration adds on top of
 * the moved detail card.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AdminMemberPageServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private AdminMemberPageService $service;
    private MemberService $memberService;
    private MemberEmailRepository $emailRepository;
    private MemberBadgeRepository $badgeRepository;
    private SectionService $sectionService;
    private ScoutYearService $scoutYearService;
    private int $currentYearId;
    private int $pastYearId;
    private int $memberId;
    private int $memberYearId;
    private int $louveteauxId;
    private int $baladinsId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->pastYearId = $scoutYearService->ensureYear('2024-2025');
        $this->currentYearId = $scoutYearService->ensureYear('2025-2026');

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BAL', 'Baladins', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('BAL01', {$branchId}, 'Ruche')");
        $this->baladinsId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute')");
        $this->louveteauxId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('ANIM', 'Animateur', 'chief', 1)");
        $functionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('TRES', 'Trésorier', 'chief', 1)");
        $secondFunctionId = (int) $this->pdo->lastInsertId();

        // A decoy person, so members.id and member_years.id cannot be
        // equal by accident. Half of what this page gets right is asking
        // module hooks about the PERSON rather than about one year's row,
        // and a fixture where both ids are 1 cannot tell the two apart.
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D0')");

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $this->memberId, $this->currentYearId,
            $this->enc->encrypt('Margaux', 'member_years.first_name'),
            $this->enc->encrypt('VANDENBRANDE', 'member_years.last_name'),
            $this->enc->encrypt('famille@ex.be', 'member_years.email'),
        ]);
        $this->memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberYearId, $functionId, $this->louveteauxId, 1]);
        $stmt->execute([$this->memberYearId, $secondFunctionId, $this->louveteauxId, 0]);

        $memberYearRepo = new \Core\Import\MemberYearRepository($this->pdo);
        $this->memberService = new MemberService($memberYearRepo, $this->enc, $connection);
        $badgeRepository = new MemberBadgeRepository($this->pdo);
        $this->emailRepository = new MemberEmailRepository($this->pdo, $this->enc);

        $this->badgeRepository = $badgeRepository;
        $this->sectionService = new SectionService($connection, $this->enc, $badgeRepository);
        $this->scoutYearService = $scoutYearService;

        $this->service = $this->serviceWith(null, null);
    }

    /**
     * The same service, with whichever module providers the case needs.
     * Both are nullable and both default to absent, which is what a site
     * with finance and registration disabled runs.
     */
    private function serviceWith(
        ?\Core\Module\MemberPaymentProvider $payments,
        ?\Core\Module\MemberRegistrationOriginProvider $origin,
        ?\Core\Module\FormationPathProvider $formation = null,
        ?\Core\Module\MemberCampStayProvider $camps = null,
        ?\Core\Module\MemberDiscussionGroupProvider $groups = null
    ): AdminMemberPageService {
        $hooks = new \Core\Module\HookRegistry();
        if ($payments !== null) {
            $hooks->register(\Core\Module\MemberPaymentProvider::class, $payments);
        }
        if ($origin !== null) {
            $hooks->register(\Core\Module\MemberRegistrationOriginProvider::class, $origin);
        }
        if ($formation !== null) {
            $hooks->register(\Core\Module\FormationPathProvider::class, $formation);
        }
        if ($camps !== null) {
            $hooks->register(\Core\Module\MemberCampStayProvider::class, $camps);
        }
        if ($groups !== null) {
            $hooks->register(\Core\Module\MemberDiscussionGroupProvider::class, $groups);
        }

        return new AdminMemberPageService(
            $this->badgeRepository,
            new MemberPhotoService(new MemberPhotoRepository($this->pdo)),
            new SectionMembershipRepository($this->pdo),
            $this->sectionService,
            $this->scoutYearService,
            $this->emailRepository,
            new \Core\Member\MemberDocumentService(new \Core\Member\MemberDocumentRepository($this->pdo)),
            $hooks
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        return $this->service->buildPageData(
            $this->memberService->getMemberProfile($this->memberYearId),
            $this->currentYearId
        );
    }

    public function testTheYearsFunctionsComeBackMainOneFirst(): void
    {
        $functions = $this->build()['functions'];

        $this->assertCount(2, $functions);
        $this->assertSame('Animateur', $functions[0]['label']);
        $this->assertTrue($functions[0]['is_main']);
        $this->assertSame('Meute', $functions[0]['section']);
        $this->assertFalse($functions[1]['is_main']);
    }

    public function testBadgesAreTheOnesAssignedForThisScoutYear(): void
    {
        $this->pdo->exec("INSERT INTO badges (name, is_default, is_active) VALUES ('Infirmier', 0, 1)");
        $badgeId = (int) $this->pdo->lastInsertId();
        (new MemberBadgeRepository($this->pdo))->assign($this->memberYearId, $badgeId, null);

        $badges = $this->build()['badges'];

        $this->assertCount(1, $badges);
        $this->assertSame('Infirmier', $badges[0]->name);
    }

    /**
     * The history is read from member_section_periods, keyed on
     * members.id — the persistent identity — so it survives every scout
     * year the member has lived through. That is the whole point of
     * showing it.
     */
    public function testSectionHistorySpansTheYearsAndMarksTheCurrentOne(): void
    {
        $this->insertPeriod($this->pastYearId, $this->baladinsId);
        $this->insertPeriod($this->currentYearId, $this->louveteauxId);

        $history = $this->build()['section_history'];

        $this->assertCount(2, $history);
        // Most recent first.
        $this->assertSame('2025-2026', $history[0]['scout_year_label']);
        $this->assertSame('Meute', $history[0]['section_name']);
        $this->assertTrue($history[0]['is_current']);
        $this->assertSame('2024-2025', $history[1]['scout_year_label']);
        $this->assertSame('Ruche', $history[1]['section_name']);
        $this->assertFalse($history[1]['is_current']);
    }

    /**
     * The order on screen is the calendar's, never the order the
     * `scout_years` rows happened to be created in.
     * ScoutYearService::ensureYear() makes a year the first time anything
     * needs it, so an install that has already prepared next year holds a
     * LOWER id for a LATER year — which is exactly what this fixture
     * builds by creating the OLDEST year last. It matters twice over:
     * the block is truncated at SECTION_HISTORY_LIMIT, so a shuffled list
     * does not merely read oddly, it keeps the wrong rows.
     */
    public function testSectionHistoryFollowsTheCalendarNotTheOrderTheYearsWereCreated(): void
    {
        $olderYearId = $this->scoutYearService->ensureYear('2023-2024');
        $this->assertGreaterThan(
            $this->currentYearId,
            $olderYearId,
            'the fixture must give the oldest year the highest id'
        );

        $this->insertPeriod($olderYearId, $this->baladinsId, '2023-09-01');
        $this->insertPeriod($this->pastYearId, $this->baladinsId, '2024-09-01');
        $this->insertPeriod($this->currentYearId, $this->louveteauxId, '2025-09-01');

        $history = $this->build()['section_history'];

        $this->assertSame(
            ['2025-2026', '2024-2025', '2023-2024'],
            array_column($history, 'scout_year_label')
        );
        $this->assertSame([true, false, false], array_column($history, 'is_current'));
    }

    public function testSectionHistoryCollapsesTwoPeriodsOfTheSameYearAndSection(): void
    {
        // A member who left a section and came back inside one year has
        // two periods; the page shows where they were, not how many rows
        // the import wrote.
        $this->insertPeriod($this->currentYearId, $this->louveteauxId, '2025-09-01', '2025-11-30');
        $this->insertPeriod($this->currentYearId, $this->louveteauxId, '2026-01-05');

        $this->assertCount(1, $this->build()['section_history']);
    }

    public function testAMemberWithNoRecordedPeriodsGetsAnEmptyHistoryRatherThanAnError(): void
    {
        $this->assertSame([], $this->build()['section_history']);
    }

    public function testThePhotoIsResolvedForTheYearBeingShown(): void
    {
        $this->assertNull($this->build()['photo_file_id']);
    }

    /**
     * ARCHITECTURE.md §8.27: secondary addresses are strict self-service,
     * with no chief or admin bypass. Showing them is defensible; making
     * them editable is not — so the service hands back an address and a
     * state, and nothing this page could act on.
     */
    public function testSecondaryAddressesComeBackAsReadOnlyRows(): void
    {
        $this->emailRepository->create($this->memberId, 'margaux@ex.be', 'manual', 'valid', null, null);

        $rows = $this->build()['member_emails'];

        $this->assertCount(1, $rows);
        $this->assertSame('margaux@ex.be', $rows[0]['address']);
        $this->assertSame('valid', $rows[0]['status']);
        // No id, no token, no confirmation hash — nothing a mutation
        // endpoint could be built on from this data.
        $this->assertSame(['address', 'status'], array_keys($rows[0]));
    }

    /**
     * The Desk address has its own line in the Desk half of the page.
     * Repeating it under « Adresses secondaires » would say the member
     * added an address they never touched.
     */
    public function testTheDeskAddressIsNotListedAmongTheSecondaryOnes(): void
    {
        $this->emailRepository->create($this->memberId, 'famille@ex.be', 'desk', 'valid', null, null);
        $this->emailRepository->create($this->memberId, 'margaux@ex.be', 'manual', 'valid', null, null);

        $addresses = array_column($this->build()['member_emails'], 'address');

        $this->assertSame(['margaux@ex.be'], $addresses);
    }

    /**
     * The private documents block — the reverse of what this test used to
     * assert, and deliberately so: `files.owner_member_id`'s
     * no-chief-and-no-admin-bypass guarantee was withdrawn (ARCHITECTURE.md
     * §8.3, SECURITY.md §6) so a chef d'unité can answer « nous n'avons
     * rien reçu » from the member's sheet. What is pinned here is the shape
     * that makes the block useful: every year, not just the one on screen.
     */
    public function testThePrivateDocumentsSpanEveryYearNotJustTheOneOnScreen(): void
    {
        $documents = new \Core\Member\MemberDocumentRepository($this->pdo);
        $documents->create($this->memberId, $this->pastYearId, 'Attestation fiscale 2024', 11, null);
        $documents->create($this->memberId, $this->currentYearId, 'Attestation fiscale 2025', 12, null);

        $rows = $this->build()['member_documents'];

        $this->assertCount(2, $rows);
        $this->assertSame(
            // Newest first: the certificate somebody is asking about is
            // usually the last one that went out.
            ['Attestation fiscale 2025', 'Attestation fiscale 2024'],
            array_values(array_map(
                static fn(array $row): string => $row['document']->title,
                $rows
            ))
        );
    }

    /** Each row carries the season it belongs to, resolved server-side. */
    public function testEachPrivateDocumentCarriesItsScoutYearLabel(): void
    {
        (new \Core\Member\MemberDocumentRepository($this->pdo))
            ->create($this->memberId, $this->currentYearId, 'Attestation fiscale 2025', 12, null);

        $rows = $this->build()['member_documents'];

        $this->assertNotSame('', $rows[0]['year_label']);
    }

    /** The ordinary case: nothing, and no error. */
    public function testAMemberWithNoPrivateDocumentGetsAnEmptyList(): void
    {
        $this->assertSame([], $this->build()['member_documents']);
    }

    private function insertPeriod(int $scoutYearId, int $sectionId, string $start = '2025-09-01', ?string $end = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, end_date) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $sectionId, $scoutYearId, $start, $end]);
    }

    // ── the blocks optional modules contribute ─────────────────────────

    /**
     * The nullable-dependency rule (ARCHITECTURE.md §8.22): finance and
     * registration disabled must mean no block, never an error and never
     * an empty card.
     */
    public function testWithNoModulesAtAllThePageStillBuildsAndOffersNothing(): void
    {
        $data = $this->build();

        $this->assertSame([], $data['open_payments']);
        $this->assertSame([], $data['settled_payments']);
        $this->assertFalse($data['settled_payments_capped']);
        $this->assertNull($data['registration_origin']);
        $this->assertNull($data['formation_path']);
        $this->assertSame([], $data['camp_stays']);
        $this->assertFalse($data['camp_stays_capped']);
        $this->assertSame([], $data['discussion_groups']);
    }

    // ── the « parcours » blocks ────────────────────────────────────────

    /**
     * Three hooks take a `members.id`, and the training path takes a
     * scout year on top — deliberately, because where somebody stood is
     * a statement about a season, not a fact that accumulates.
     */
    public function testTheParcoursHooksAreAskedAboutThePersonAndTheYearOnScreen(): void
    {
        $formation = new class implements \Core\Module\FormationPathProvider {
            public ?int $memberAskedFor = null;
            public ?int $yearAskedFor = null;

            public function getFormationPath(int $memberId, int $scoutYearId): ?\Core\Module\FormationPathView
            {
                $this->memberAskedFor = $memberId;
                $this->yearAskedFor = $scoutYearId;

                return null;
            }
        };
        $camps = new class implements \Core\Module\MemberCampStayProvider {
            public ?int $askedFor = null;

            public function getCampStays(int $memberId): array
            {
                $this->askedFor = $memberId;

                return [];
            }
        };
        $groups = new class implements \Core\Module\MemberDiscussionGroupProvider {
            public ?int $askedFor = null;

            public function getDiscussionGroups(int $memberId): array
            {
                $this->askedFor = $memberId;

                return [];
            }
        };

        $this->serviceWith(null, null, $formation, $camps, $groups)->buildPageData(
            $this->memberService->getMemberProfile($this->memberYearId),
            $this->currentYearId
        );

        $this->assertSame($this->memberId, $formation->memberAskedFor);
        $this->assertSame($this->currentYearId, $formation->yearAskedFor);
        $this->assertSame($this->memberId, $camps->askedFor);
        $this->assertSame($this->memberId, $groups->askedFor);
    }

    public function testAFullCampListIsFlaggedAsCapped(): void
    {
        $stays = [];
        for ($i = 0; $i < \Core\Module\MemberCampStayProvider::LIMIT; $i++) {
            $stays[] = new \Core\Module\MemberCampStayView('Lieu ' . $i, '2026', 'Meute', '2025-2026', '/chefs/camps/sejours/' . $i);
        }

        $camps = new class ($stays) implements \Core\Module\MemberCampStayProvider {
            /** @param list<\Core\Module\MemberCampStayView> $stays */
            public function __construct(private array $stays)
            {
            }

            public function getCampStays(int $memberId): array
            {
                return $this->stays;
            }
        };

        $data = $this->serviceWith(null, null, null, $camps, null)->buildPageData(
            $this->memberService->getMemberProfile($this->memberYearId),
            $this->currentYearId
        );

        $this->assertCount(\Core\Module\MemberCampStayProvider::LIMIT, $data['camp_stays']);
        $this->assertTrue($data['camp_stays_capped']);
    }

    /**
     * Both hooks take the PERSISTENT member id: a debt does not expire
     * when the scout year turns, and a request produced a person rather
     * than one year's row.
     */
    public function testBothHooksAreAskedAboutThePersonNotTheAnnualRow(): void
    {
        $payments = new class implements \Core\Module\MemberPaymentProvider {
            public ?int $openAskedFor = null;
            public ?int $settledAskedFor = null;

            public function getOpenPayments(int $memberId): array
            {
                $this->openAskedFor = $memberId;

                return [];
            }

            public function getSettledPayments(int $memberId): array
            {
                $this->settledAskedFor = $memberId;

                return [];
            }
        };
        $origin = new class implements \Core\Module\MemberRegistrationOriginProvider {
            public ?int $askedFor = null;

            public function getRegistrationOrigin(int $memberId): ?\Core\Module\MemberRegistrationOriginView
            {
                $this->askedFor = $memberId;

                return null;
            }
        };

        $this->serviceWith($payments, $origin)->buildPageData(
            $this->memberService->getMemberProfile($this->memberYearId),
            $this->currentYearId
        );

        $this->assertSame($this->memberId, $payments->openAskedFor);
        $this->assertSame($this->memberId, $payments->settledAskedFor);
        $this->assertSame($this->memberId, $origin->askedFor);
        $this->assertNotSame($this->memberYearId, $this->memberId, 'the fixture must distinguish the two ids');
    }

    public function testWhatTheModulesAnswerReachesThePageUntouched(): void
    {
        $open = new \Core\Module\MemberPaymentView('Cotisation 2025-2026', 2500, 4500, 2000, '+++123+++', 'Unité', 'BE71', null);
        $settled = new \Core\Module\MemberSettledPaymentView(
            'Cotisation 2024-2025',
            4500,
            4500,
            \Core\Module\MemberSettledPaymentView::STATUS_PAID,
            new \DateTimeImmutable('2025-03-04')
        );

        $data = $this->serviceWith($this->paymentsAnswering([$open], [$settled]), null)->buildPageData(
            $this->memberService->getMemberProfile($this->memberYearId),
            $this->currentYearId
        );

        $this->assertSame([$open], $data['open_payments']);
        $this->assertSame([$settled], $data['settled_payments']);
        $this->assertFalse($data['settled_payments_capped']);
    }

    /**
     * A truncated list read as a complete one is the failure mode here,
     * so the page is told rather than left to infer it.
     */
    public function testAFullSettledListIsFlaggedAsCapped(): void
    {
        $settled = [];
        for ($i = 0; $i < \Core\Module\MemberPaymentProvider::SETTLED_LIMIT; $i++) {
            $settled[] = new \Core\Module\MemberSettledPaymentView(
                'Demande ' . $i,
                1000,
                1000,
                \Core\Module\MemberSettledPaymentView::STATUS_PAID,
                null
            );
        }

        $data = $this->serviceWith($this->paymentsAnswering([], $settled), null)->buildPageData(
            $this->memberService->getMemberProfile($this->memberYearId),
            $this->currentYearId
        );

        $this->assertTrue($data['settled_payments_capped']);
    }

    /**
     * @param list<\Core\Module\MemberPaymentView> $open
     * @param list<\Core\Module\MemberSettledPaymentView> $settled
     */
    private function paymentsAnswering(array $open, array $settled): \Core\Module\MemberPaymentProvider
    {
        return new class ($open, $settled) implements \Core\Module\MemberPaymentProvider {
            /**
             * @param list<\Core\Module\MemberPaymentView> $open
             * @param list<\Core\Module\MemberSettledPaymentView> $settled
             */
            public function __construct(private array $open, private array $settled)
            {
            }

            public function getOpenPayments(int $memberId): array
            {
                return $this->open;
            }

            public function getSettledPayments(int $memberId): array
            {
                return $this->settled;
            }
        };
    }
}
