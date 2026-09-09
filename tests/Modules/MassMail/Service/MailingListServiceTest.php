<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Import\FunctionRepository;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Service\MailingListException;
use Modules\MassMail\Service\MailingListService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailingListServiceTest extends TestCase
{
    private \PDO $pdo;
    private MailingListService $service;
    private int $scoutYearId;
    private int $sectionActiveId;
    private int $functionId;
    private int $badgeId;
    private ScoutYearResolver $scoutYearResolver;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $this->scoutYearResolver = new ScoutYearResolver(
            new ScoutYearService($this->pdo),
            new SettingService(new SettingRepository($this->pdo)),
            new MemberYearRepository($this->pdo)
        );
        $this->service = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            $sectionService,
            new FunctionRepository($this->pdo),
            new BadgeService(
                new BadgeRepository($this->pdo),
                new MemberBadgeRepository($this->pdo),
                $sectionService
            ),
            null,
            null,
            null,
            $this->scoutYearResolver
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name, is_active) VALUES ('LOU01', {$branchId}, 'Meute A', 1)");
        $this->sectionActiveId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name, is_active) VALUES ('LOU02', {$branchId}, 'Meute B', 0)");

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'identified')");
        $this->functionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO badges (name, is_default) VALUES ('Infirmier', 1)");
        $this->badgeId = (int) $this->pdo->lastInsertId();

        // The « Anciens » list resolves its own reference year through the
        // resolver, so pin it rather than letting the date fallback pick
        // one and make these tests depend on the day they run.
        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR,
            (string) $this->scoutYearId,
            'number',
            'Année scoute courante',
            'Description.'
        );
    }

    /**
     * Non-regression, and it is worth stating why this test exists at all.
     *
     * The « Nouvelle liste » function picker was suspected of grouping or
     * renaming the Desk vocabulary. It does neither: DeskCsvParser reads
     * FONCTION, MappingResolver::resolveFunction() creates the row with
     * `label = desk_code` verbatim, this method hands that label to the
     * template, and the template prints it. There is no mapping anywhere on
     * that path, and this pins the absence — a function whose Desk label is
     * « Équipier d'unité » is offered as « Équipier d'unité », apostrophe,
     * accent and all.
     */
    public function testTheFunctionPickerOffersTheRawDeskLabel(): void
    {
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('Équipier d''unité', 'Équipier d''unité', 'admin')");

        $labels = array_column($this->service->getAllFunctions(), 'label');

        $this->assertContains("Équipier d'unité", $labels);
    }

    public function testDefaultListsOnlyIncludeActiveSectionsPlusTheTwoUnitWideLists(): void
    {
        $lists = $this->service->getDefaultLists();

        $labels = array_column($lists, 'label');
        $this->assertContains('Section - Meute A', $labels);
        $this->assertNotContains('Section - Meute B', $labels);
        $this->assertContains(MailingListService::ACTIVE_MEMBERS_LABEL, $labels);
        $this->assertContains(MailingListService::CHIEFS_LABEL, $labels);
    }

    /**
     * One axis is enough now — « les intendants, toutes sections
     * confondues » is a real list, and demanding one of each forbade it.
     * What is still refused is all three axes empty at once: that
     * resolves to nobody, by design (D5).
     */
    public function testCreateCustomListAcceptsASingleAxis(): void
    {
        $list = $this->service->createCustomList('Les chefs', 'Toutes sections', [$this->functionId], [], [], null);

        $this->assertSame([$this->functionId], $this->service->getCustomListFunctionIds($list->id));
        $this->assertSame([], $this->service->getCustomListSectionIds($list->id));
    }

    public function testCreateCustomListRefusesCriteriaOnNoAxisAtAll(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->createCustomList('Liste vide', 'Description', [], [], [], null);
    }

    public function testCreateCustomListRequiresANonEmptyName(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->createCustomList('  ', 'Description', [$this->functionId], [$this->sectionActiveId], [], null);
    }

    public function testCreateCustomListRequiresANonEmptyDescription(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->createCustomList('Ma liste', '  ', [$this->functionId], [$this->sectionActiveId], [], null);
    }

    public function testCreateAndResolveCustomListRoundTrips(): void
    {
        $list = $this->service->createCustomList('Ma liste', 'Description de la liste', [$this->functionId], [$this->sectionActiveId], [$this->badgeId], null);

        $this->assertSame('Description de la liste', $list->description);
        $this->assertSame([$this->functionId], $this->service->getCustomListFunctionIds($list->id));
        $this->assertSame([$this->sectionActiveId], $this->service->getCustomListSectionIds($list->id));
        $this->assertSame([$this->badgeId], $this->service->getCustomListBadgeIds($list->id));

        $resolved = $this->service->resolveMembers('custom', $list->id, null, $this->scoutYearId);
        $this->assertSame([], $resolved); // no members assigned yet, but resolves without error
    }

    public function testUpdateCustomListReplacesTheBadgeAxisToo(): void
    {
        $list = $this->service->createCustomList('Ma liste', 'Description', [$this->functionId], [], [$this->badgeId], null);

        $this->service->updateCustomList($list->id, 'Ma liste', 'Description', [$this->functionId], [], []);

        $this->assertSame([], $this->service->getCustomListBadgeIds($list->id));
    }

    public function testUpdateCustomListRequiresANonEmptyDescription(): void
    {
        $list = $this->service->createCustomList('Ma liste', 'Description', [$this->functionId], [$this->sectionActiveId], [], null);

        $this->expectException(MailingListException::class);
        $this->service->updateCustomList($list->id, 'Ma liste', '', [$this->functionId], [$this->sectionActiveId], []);
    }

    /**
     * A deactivated badge is no longer assignable anywhere, so offering it
     * as a criterion could only ever build a list resolving to nobody.
     */
    public function testOnlyActiveBadgesAreOfferedAsCriteria(): void
    {
        $this->pdo->exec("INSERT INTO badges (name, is_active) VALUES ('Retiré', 0)");

        $offered = array_column($this->service->getAllBadges(), 'name');

        $this->assertContains('Infirmier', $offered);
        $this->assertNotContains('Retiré', $offered);
    }

    /**
     * The pickers are the only place a list's criteria round-trip
     * through, so an id with no item to be selected in is an id that the
     * next save silently drops — and under this iteration's own rule (an
     * empty axis constrains nothing) a list losing its last badge that
     * way would quietly widen instead of narrowing.
     */
    public function testADeactivatedBadgeStillNamedByAListIsStillOfferedGreyed(): void
    {
        $this->service->createCustomList('Ma liste', 'Description', [], [], [$this->badgeId], null);
        $this->pdo->exec("UPDATE badges SET is_active = 0 WHERE id = {$this->badgeId}");

        $offered = $this->service->getAllBadges();

        $this->assertContains($this->badgeId, array_column($offered, 'id'));
        $this->assertContains('Infirmier (désactivé)', array_column($offered, 'name'));
    }

    public function testASectionStillNamedByAListIsOfferedEvenOnceItIsGone(): void
    {
        $this->service->createCustomList('Ma liste', 'Description', [], [$this->sectionActiveId], [], null);
        $this->pdo->exec("UPDATE sections SET is_active = 0 WHERE id = {$this->sectionActiveId}");

        $offered = $this->service->getAllSections();

        $this->assertSame(
            ['Meute A (retirée)'],
            array_column($offered, 'name'),
            'Meute B is inactive and named by nothing, so it stays out.'
        );
        $this->assertSame([$this->sectionActiveId], array_column($offered, 'id'));
    }

    public function testNoBadgeIsOfferedWhenTheBadgeServiceIsAbsent(): void
    {
        $service = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            new SectionService(Connection::withPdo($this->pdo), new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), new MemberBadgeRepository($this->pdo)),
            new FunctionRepository($this->pdo)
        );

        $this->assertSame([], $service->getAllBadges());
    }

    // --- The summary the page prints (describeCriteria) ------------------

    /**
     * The page that lists the lists has no pickers above its sentence, so
     * the sentence NAMES the criteria rather than counting them — see the
     * method's own docblock for why it is deliberately not the same
     * sentence as the dialog's.
     */
    public function testTheSummaryNamesEachAxisAndJoinsThemWithEt(): void
    {
        $this->assertSame(
            'Membres qui exercent la fonction Animateur ET sont dans la section Meute A '
                . 'ET portent le badge Infirmier.',
            $this->service->describeCriteria([$this->functionId], [$this->sectionActiveId], [$this->badgeId])
        );
    }

    public function testAnAxisThatConstrainsNothingDisappearsFromTheSummary(): void
    {
        $this->assertSame(
            'Membres qui exercent la fonction Animateur.',
            $this->service->describeCriteria([$this->functionId], [], [])
        );
    }

    /**
     * Singular and plural are two sentences rather than one carrying an
     * « (s) »: « une des fonctions Animateur » is what a template
     * produces and nobody writes.
     */
    public function testTwoCriteriaOnOneAxisSwitchTheClauseToItsPlural(): void
    {
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('INT', 'Intendant', 'identified')");
        $second = (int) $this->pdo->lastInsertId();

        $this->assertSame(
            'Membres qui exercent une des fonctions Animateur, Intendant.',
            $this->service->describeCriteria([$this->functionId, $second], [], [])
        );
    }

    public function testNoCriterionAtAllSaysSoRatherThanNamingNothing(): void
    {
        $this->assertSame(
            'Aucun membre du site dans cette liste.',
            $this->service->describeCriteria([], [], [])
        );
    }

    /**
     * A deactivated badge a list still names keeps the suffix the picker
     * gives it — this sentence is the one place a chief finds out why the
     * list resolves to fewer members than its other axes suggest.
     */
    public function testADeactivatedBadgeIsNamedWithItsSuffix(): void
    {
        $this->service->createCustomList('Ma liste', 'Description', [], [], [$this->badgeId], null);
        $this->pdo->exec("UPDATE badges SET is_active = 0 WHERE id = {$this->badgeId}");

        $this->assertSame(
            'Membres qui portent le badge Infirmier (désactivé).',
            $this->service->describeCriteria([], [], [$this->badgeId])
        );
    }

    public function testCountMembersForCriteriaCountsWhatASendWouldResolve(): void
    {
        $this->assertSame(0, $this->service->countMembersForCriteria([], [], [], $this->scoutYearId));
        $this->assertSame(
            0,
            $this->service->countMembersForCriteria([$this->functionId], [], [], $this->scoutYearId)
        );
    }

    /**
     * Two siblings on one family address are two members and ONE
     * recipient — `resolveMembersForYears()` collapses them before a send
     * freezes anything, so a counter that did not would promise a number
     * the send contradicts, on exactly the units where a shared address
     * is the norm.
     */
    public function testTheLiveCountCollapsesTwoMembersSharingOneAddress(): void
    {
        $this->createMemberWithFunction('famille@test.be');
        $this->createMemberWithFunction('famille@test.be');
        $this->createMemberWithFunction('seule@test.be');

        $count = $this->service->countMembersForCriteria([$this->functionId], [], [], $this->scoutYearId);

        $this->assertSame(2, $count);
        $this->assertCount(
            $count,
            $this->service->resolveMembersForYears('custom', $this->listOverTheFunction()->id, null, [$this->scoutYearId]),
            'The counter and the send answer the same question.'
        );
    }

    /**
     * A member the list designates but who has no address at all is still
     * one of them — the same rule the recipient estimate follows.
     */
    public function testTheLiveCountKeepsAMemberWithNoAddress(): void
    {
        $this->createMemberWithFunction('une@test.be');
        $this->createMemberWithFunction(null);

        $this->assertSame(2, $this->service->countMembersForCriteria([$this->functionId], [], [], $this->scoutYearId));
    }

    private function listOverTheFunction(): \Modules\MassMail\Repository\MailingList
    {
        return $this->service->createCustomList('Ma liste', 'Description', [$this->functionId], [], [], null);
    }

    private function createMemberWithFunction(?string $email): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       email_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $encryption->encrypt('John', 'member_years.first_name'),
            $encryption->encrypt('Doe', 'member_years.last_name'),
            $email !== null ? $encryption->encrypt($email, 'member_years.email') : null,
            $email !== null ? $encryption->blindIndex($email, 'email') : null,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $this->functionId, $this->sectionActiveId]);
    }

    /**
     * The module's answer to « may this badge be deleted? » — the badge
     * axis's foreign key cascades, and under this iteration's semantics a
     * cascade widens the list instead of emptying it.
     */
    public function testTheBadgeUsageProviderNamesEveryBadgeAListStillCrosses(): void
    {
        $provider = new \Modules\MassMail\Service\MailingListBadgeUsageService(new MailingListRepository($this->pdo));

        $this->assertSame([], $provider->badgeIdsInUse());

        $this->service->createCustomList('Ma liste', 'Description', [], [], [$this->badgeId], null);

        $this->assertSame([$this->badgeId], $provider->badgeIdsInUse());
        $this->assertNotSame('', $provider->describeBadgeUsage());
    }

    public function testDeleteCustomListBlockedWhenReferencedByAnEmail(): void
    {
        $list = $this->service->createCustomList('Ma liste', 'Description', [$this->functionId], [$this->sectionActiveId], [], null);

        $this->pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, list_id, status)
             VALUES ('Sujet', '<p>Corps</p>', {$this->sectionActiveId}, 'custom', {$list->id}, 'draft')"
        );

        $this->expectException(MailingListException::class);
        $this->service->deleteCustomList($list->id);
    }

    public function testDeleteCustomListSucceedsWhenNotReferenced(): void
    {
        $list = $this->service->createCustomList('Ma liste', 'Description', [$this->functionId], [$this->sectionActiveId], [], null);

        $this->service->deleteCustomList($list->id);

        $this->assertNull($this->service->getCustomListById($list->id));
    }

    public function testDefaultListsEachHaveANonEmptyDescription(): void
    {
        foreach ($this->service->getDefaultLists() as $list) {
            $this->assertNotSame('', trim($list['description']));
        }
    }

    public function testExternalListAbsentWhenNoProviderIsWired(): void
    {
        $types = array_column($this->service->getDefaultLists(), 'list_type');

        $this->assertNotContains('external', $types);
    }

    public function testExternalListAppearsWhenProviderIsWired(): void
    {
        $provider = $this->createMock(\Modules\Registration\Api\ExternalMailingListProvider::class);
        $provider->method('describeMailingList')->willReturn([
            'label' => 'Inscriptions 2026-2027', 'description' => 'Demandes encodées pour 2026-2027.',
        ]);
        $service = new MailingListService(
            new MailingListRepository($this->pdo), new MemberResolutionRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            new SectionService(Connection::withPdo($this->pdo), new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), new MemberBadgeRepository($this->pdo)),
            new FunctionRepository($this->pdo), null, null, $provider
        );

        $lists = $service->getDefaultLists();
        $external = array_values(array_filter($lists, fn($l) => $l['list_type'] === 'external'));

        $this->assertCount(1, $external);
        $this->assertSame('Inscriptions 2026-2027', $external[0]['label']);
        $this->assertNull($external[0]['list_section_id']);
    }

    public function testResolveMembersForYearsTagsExternalMembersWithTheProvidersOwnTargetYear(): void
    {
        $provider = $this->createMock(\Modules\Registration\Api\ExternalMailingListProvider::class);
        $provider->method('resolveMailingListMembers')->willReturn([
            ['member_id' => 42, 'email' => 'parent@example.com'],
        ]);
        $provider->method('targetScoutYearId')->willReturn(999);
        $service = new MailingListService(
            new MailingListRepository($this->pdo), new MemberResolutionRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            new SectionService(Connection::withPdo($this->pdo), new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), new MemberBadgeRepository($this->pdo)),
            new FunctionRepository($this->pdo), null, null, $provider
        );

        // The compose dialog's own year checkboxes (current year here) must
        // NOT override the provider's own fixed target year (999).
        $members = $service->resolveMembersForYears('external', null, null, [$this->scoutYearId]);

        $this->assertCount(1, $members);
        $this->assertSame(42, $members[0]['member_id']);
        $this->assertSame(999, $members[0]['scout_year_id']);
    }

    public function testResolveMembersForYearsThrowsWhenExternalProviderIsMissing(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->resolveMembersForYears('external', null, null, [$this->scoutYearId]);
    }

    // --- « Anciens » (default_former_members) ---------------------------

    private function serviceWithSettings(?SettingService $settingService): MailingListService
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            new SectionService(Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo)),
            new FunctionRepository($this->pdo),
            null,
            null,
            null,
            null,
            $this->scoutYearResolver,
            null,
            $settingService
        );
    }

    private function registerFormerMemberSettings(string $minYears, string $maxYears): SettingService
    {
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(
            MailingListService::SETTING_MIN_SCOUT_YEARS,
            $minYears,
            'number',
            'Années scoutes minimum',
            'Description.',
            'mass_mail'
        );
        $settingService->register(
            MailingListService::SETTING_MAX_YEARS_SINCE_DEPARTURE,
            $maxYears,
            'number',
            'Années depuis le départ',
            'Description.',
            'mass_mail'
        );

        return $settingService;
    }

    private function createScoutYear(string $label, string $start, string $end): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$label, $start, $end]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createMemberYear(int $scoutYearId, string $email, ?int $memberId = null): int
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        if ($memberId === null) {
            $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid('', true) . "')");
            $memberId = (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       email_encrypted, email_blind_index, is_active, unit_mail_consent)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $encryption->encrypt('John', 'member_years.first_name'),
            $encryption->encrypt('Doe', 'member_years.last_name'),
            $encryption->encrypt($email, 'member_years.email'),
            $encryption->blindIndex($email, 'email'),
        ]);

        return $memberId;
    }

    public function testTheFormerMembersListIsOfferedAmongTheDefaults(): void
    {
        $lists = $this->service->getDefaultLists();
        $former = array_values(array_filter(
            $lists,
            fn(array $l): bool => $l['list_type'] === Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS
        ));

        $this->assertCount(1, $former);
        $this->assertSame(MailingListService::FORMER_MEMBERS_LABEL, $former[0]['label']);
        $this->assertNull($former[0]['list_section_id']);
    }

    /**
     * The description is computed rather than fixed because it is the only
     * place a chief sees the two invisible settings and the date nobody
     * chose — the oldest year the unit ever imported.
     */
    public function testTheComputedDescriptionReflectsTheOldestImportedYear(): void
    {
        $this->assertStringContainsString(
            "Anciens membres de l'unité",
            $this->service->formerMembersDescription(),
            'With nothing imported at all there is no year to name.'
        );

        $older = $this->createScoutYear('2022-2023', '2022-09-01', '2023-08-31');
        $this->createMemberYear($this->scoutYearId, 'a@test.be');
        $this->createMemberYear($older, 'b@test.be');

        $description = $this->service->formerMembersDescription();

        $this->assertStringContainsString('Anciens connus depuis 2022-2023', $description);
        $this->assertStringContainsString('au moins 2 années scoutes', $description);
        $this->assertStringContainsString('partis depuis moins de 10 ans', $description);
    }

    public function testTheDescriptionSaysSoWhenTheUpperBoundIsDisabled(): void
    {
        $service = $this->serviceWithSettings($this->registerFormerMemberSettings('1', '0'));

        $description = $service->formerMembersDescription();

        $this->assertStringContainsString('au moins une année scoute', $description);
        $this->assertStringContainsString("sans limite d'ancienneté", $description);
    }

    public function testTheThresholdsFallBackToTheirDefaultsWithoutASettingService(): void
    {
        $service = $this->serviceWithSettings(null);

        $this->assertSame(2, $service->formerMembersMinScoutYears());
        $this->assertSame(10, $service->formerMembersMaxYearsSinceDeparture());
    }

    public function testTheThresholdsAreReadFromTheSettings(): void
    {
        $service = $this->serviceWithSettings($this->registerFormerMemberSettings('3', '5'));

        $this->assertSame(3, $service->formerMembersMinScoutYears());
        $this->assertSame(5, $service->formerMembersMaxYearsSinceDeparture());
    }

    /**
     * A minimum of zero scout years is not a looser list, it is a
     * nonsensical one — everybody who ever had a row, including the rows
     * an import created and then corrected. It falls back to the default.
     */
    public function testAMinimumOfZeroScoutYearsFallsBackToTheDefault(): void
    {
        $service = $this->serviceWithSettings($this->registerFormerMemberSettings('0', '10'));

        $this->assertSame(2, $service->formerMembersMinScoutYears());
    }

    /**
     * Unlike every other list, this one is not re-scoped year by year:
     * each former member keeps the year they were resolved from, which is
     * the only year their profile exists for.
     */
    public function testResolveMembersForYearsKeepsEachFormerMembersOwnYear(): void
    {
        $yearMinusOne = $this->createScoutYear('2024-2025', '2024-09-01', '2025-08-31');
        $yearMinusTwo = $this->createScoutYear('2023-2024', '2023-09-01', '2024-08-31');

        $recent = $this->createMemberYear($yearMinusTwo, 'recent@test.be');
        $this->createMemberYear($yearMinusOne, 'recent@test.be', $recent);

        $older = $this->createMemberYear($yearMinusTwo, 'older@test.be');
        $this->createMemberYear($this->createScoutYear('2022-2023', '2022-09-01', '2023-08-31'), 'older@test.be', $older);

        $members = $this->service->resolveMembersForYears(
            Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS,
            null,
            null,
            [$this->scoutYearId]
        );

        $byMember = [];
        foreach ($members as $member) {
            $byMember[$member['member_id']] = $member['scout_year_id'];
        }

        $this->assertSame($yearMinusOne, $byMember[$recent] ?? null);
        $this->assertSame($yearMinusTwo, $byMember[$older] ?? null);
    }

    /**
     * Two former members sharing one address — a family address kept for
     * two children who both left — receive one mail, not two.
     */
    public function testTwoFormerMembersSharingAnAddressAreDeduplicated(): void
    {
        $yearMinusOne = $this->createScoutYear('2024-2025', '2024-09-01', '2025-08-31');
        $yearMinusTwo = $this->createScoutYear('2023-2024', '2023-09-01', '2024-08-31');

        foreach (['first', 'second'] as $child) {
            $memberId = $this->createMemberYear($yearMinusTwo, 'famille@test.be');
            $this->createMemberYear($yearMinusOne, 'famille@test.be', $memberId);
            unset($child);
        }

        $members = $this->service->resolveMembersForYears(
            Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS,
            null,
            null,
            [$this->scoutYearId]
        );

        $this->assertCount(1, $members);
        $this->assertSame('famille@test.be', $members[0]['email']);
    }

    /**
     * The compose page hides the year checkboxes for this list and says
     * so in words — but it hides them with a CSS class, so a box ticked
     * before the list type was switched is still in the form and still
     * submitted. Reading it would resolve « everybody absent from NEXT
     * year », which is most of the unit.
     */
    public function testASubmittedScoutYearIsIgnoredForTheFormerMembersList(): void
    {
        $nextYear = $this->createScoutYear('2026-2027', '2026-09-01', '2027-08-31');
        $lastYear = $this->createScoutYear('2024-2025', '2024-09-01', '2025-08-31');
        $twoYearsAgo = $this->createScoutYear('2023-2024', '2023-09-01', '2024-08-31');

        // Active right now: a former member of nothing.
        $current = $this->createMemberYear($twoYearsAgo, 'encore@test.be');
        $this->createMemberYear($this->scoutYearId, 'encore@test.be', $current);

        // Genuinely gone.
        $gone = $this->createMemberYear($twoYearsAgo, 'parti@test.be');
        $this->createMemberYear($lastYear, 'parti@test.be', $gone);

        $members = $this->service->resolveMembersForYears(
            Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS,
            null,
            null,
            // The stale tick left behind by another list type.
            [$nextYear]
        );

        $this->assertSame([$gone], array_column($members, 'member_id'));
    }

    public function testTheFormerMembersListIsEmptyWithoutAResolver(): void
    {
        $this->assertSame(
            [],
            $this->serviceWithSettingsAndNoResolver()
                ->resolveMembersForYears(Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS, null, null, [])
        );
    }

    private function serviceWithSettingsAndNoResolver(): MailingListService
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            new SectionService(Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo)),
            new FunctionRepository($this->pdo)
        );
    }
}
