<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Controller\AuthController;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Mail\MailService;
use Core\Badge\MemberBadgeRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\ScoutYear\AuthorizationYears;
use Core\ScoutYear\AuthorizationYearService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\SessionRevalidator;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Two animateurs, one day, opposite answers.
 *
 * **A** animates section S1 in the year that is ending and has no row at
 * all in the year being prepared: they are leaving. **B** animates section
 * S2 in the year being prepared and has no row in the year that is ending:
 * they are arriving. Both are staff, on the same afternoon, and a site
 * that resolves the role against one year answers exactly one of them.
 *
 * The circularity this pins is the one that used to make B impossible:
 * the staff year was only ever served to somebody who was already staff
 * **by the public year**, so the person the year was prepared for could
 * never reach it. And A, being staff by the public year, was dragged into
 * the staff year where they have no section and no animés.
 *
 * Both halves are checked here through the real classes — the year set,
 * the role resolution, the login gate and the revalidation — because the
 * defect never lived in any one of them.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScoutYearTransitionAccessTest extends TestCase
{
    private const A_EMAIL = 'a.leaving@test.com';
    private const B_EMAIL = 'b.arriving@test.com';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private SettingService $settingService;
    private ScoutYearService $scoutYearService;
    private RoleResolver $roleResolver;
    private UserAccountRepository $userRepo;
    private AuthService $authService;
    private AuthController $controller;

    /** The year that is ending, and the year being prepared. */
    private int $endingYear;
    private int $nextYear;

    /** @var array{to: string, subject: string, body: string}|null */
    private ?array $sentMail = null;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->scoutYearService = new ScoutYearService($this->pdo);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'Public year id',
            null, '^[0-9]+$', null, false
        );
        $this->settingService->register(
            ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'Staff year id',
            null, '^[0-9]+$', null, false
        );

        // « The year that is ending » is the one today's date names, so the
        // date-computed year of Core\ScoutYear\AuthorizationYearService
        // coincides with it and the three states below are exactly the
        // three states of the site — never an accident of the calendar.
        $this->endingYear = $this->createYear(0);
        $this->nextYear = $this->createYear(1);

        $memberYearRepo = new MemberYearRepository($this->pdo);
        $memberEmailRepo = new MemberEmailRepository($this->pdo, $this->encryption);
        $this->roleResolver = new RoleResolver($memberYearRepo, $this->encryption, $this->pdo, $memberEmailRepo);
        $this->userRepo = new UserAccountRepository($this->pdo, $this->encryption);

        $this->userRepo->create(self::A_EMAIL);
        $this->userRepo->create(self::B_EMAIL);
        $this->createSection('S1');
        $this->createSection('S2');
        $this->createStaffMember('A_LEAVING', self::A_EMAIL, $this->endingYear, 'S1', 'chief');
        $this->createStaffMember('B_ARRIVING', self::B_EMAIL, $this->nextYear, 'S2', 'chief');

        $this->buildAuthStack($memberYearRepo);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->sentMail = null;
    }

    // ---------------------------------------------------------------
    // The three states of the site
    // ---------------------------------------------------------------

    /**
     * Nothing has been prepared yet: A is the animateur the site knows,
     * and B is not yet anybody. B is refused at the door by the same
     * membership gate that has always been there — their year is not a
     * year the site takes decisions in yet.
     */
    public function testPublicYearOnly(): void
    {
        $this->setPublicYear($this->endingYear);

        $this->assertSame('chief', $this->resolvedRoleOf(self::A_EMAIL));
        $this->assertTrue($this->mayLogIn(self::A_EMAIL));

        $this->assertSame('identified', $this->resolvedRoleOf(self::B_EMAIL));
        $this->assertFalse($this->mayLogIn(self::B_EMAIL));
    }

    /**
     * The transition is under way: the staff year is open, the members
     * still see the year that is ending. **Both** animateurs are staff,
     * and this is the state the whole chantier exists for.
     */
    public function testStaffYearOpenGivesBothOfThemChiefAccess(): void
    {
        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $this->assertSame('chief', $this->resolvedRoleOf(self::A_EMAIL));
        $this->assertTrue($this->mayLogIn(self::A_EMAIL));

        $this->assertSame('chief', $this->resolvedRoleOf(self::B_EMAIL));
        $this->assertTrue($this->mayLogIn(self::B_EMAIL));
    }

    /**
     * The site has switched over. A has left, and the year they were a
     * chief in is behind the public year, so it grants nothing any more —
     * including during the weeks between an early switch and 1 September,
     * which is when the year that is ending is still the one the calendar
     * names.
     */
    public function testPublicYearSwitchedOverEndsAsAccessAndKeepsBs(): void
    {
        $this->setPublicYear($this->nextYear);

        $this->assertSame('identified', $this->resolvedRoleOf(self::A_EMAIL));
        $this->assertFalse($this->mayLogIn(self::A_EMAIL));

        $this->assertSame('chief', $this->resolvedRoleOf(self::B_EMAIL));
        $this->assertTrue($this->mayLogIn(self::B_EMAIL));
    }

    // ---------------------------------------------------------------
    // The threshold
    // ---------------------------------------------------------------

    /**
     * An animé enrolled for the year being prepared and for no other
     * signs in — refusing a family already enrolled would be its own
     * defect — and finds the space of an `identified` member. The year
     * being prepared elevates nobody below `intendant`.
     */
    public function testIdentifiedInTheStaffYearElevatesNobody(): void
    {
        $this->createStaffMember('C_ENROLLED', 'c.enrolled@test.com', $this->nextYear, 'S2', 'identified');
        $this->userRepo->create('c.enrolled@test.com');

        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $this->assertSame('identified', $this->resolvedRoleOf('c.enrolled@test.com'));
        $this->assertTrue($this->mayLogIn('c.enrolled@test.com'));
    }

    /**
     * `intendant` is the threshold because it is the one
     * ScoutYearResolver::getEffectiveYear() already applies to the staff
     * year: a newly appointed intendant prepares enrolments and fees
     * before the season starts, exactly like a newly appointed chief.
     */
    public function testIntendantInTheStaffYearDoesElevate(): void
    {
        $this->createStaffMember('D_STEWARD', 'd.steward@test.com', $this->nextYear, 'S2', 'intendant');
        $this->userRepo->create('d.steward@test.com');

        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $this->assertSame('intendant', $this->resolvedRoleOf('d.steward@test.com'));
    }

    /**
     * The public year's own answer is never thresholded: an `identified`
     * member of the public year is `identified`, and nothing about the
     * transition changes that.
     */
    public function testIdentifiedInThePublicYearIsUnaffectedByTheThreshold(): void
    {
        $this->createStaffMember('E_ANIME', 'e.anime@test.com', $this->endingYear, 'S1', 'identified');
        $this->userRepo->create('e.anime@test.com');

        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $this->assertSame('identified', $this->resolvedRoleOf('e.anime@test.com'));
        $this->assertTrue($this->mayLogIn('e.anime@test.com'));
    }

    /**
     * **The threshold changes no outcome today, and this test is what
     * says so out loud.** Below `intendant` the role ladder only has
     * `identified` and `public`, and a year contributes at least the
     * `identified` floor anyway — so every role the threshold currently
     * refuses is one that would have elevated nobody. It is written into
     * RoleResolver::resolveAcrossYears() because D4 says the rule is
     * « intendant », not « whatever the ladder happens to make
     * harmless », and it becomes load-bearing the instant a role is
     * inserted between the two.
     *
     * If this assertion ever fails, that insertion has happened: the
     * threshold is now observable, and it needs a case of its own here
     * proving that the new role does not elevate out of a non-public
     * year.
     */
    public function testTheThresholdIsPinnedToTheRoleLadderThatMakesItHarmlessToday(): void
    {
        $this->assertSame(
            1,
            Role::INTENDANT->level() - Role::IDENTIFIED->level(),
            'A role now sits between identified and intendant. The staff-year threshold in '
            . 'RoleResolver::resolveAcrossYears() has become observable — add the case that pins it.'
        );
    }

    // ---------------------------------------------------------------
    // The bound
    // ---------------------------------------------------------------

    /**
     * The forgotten instance: nobody ran the transition, the public year
     * is two seasons behind, and a chief of a year two apart must not
     * keep their access on the strength of a year nobody is on.
     */
    public function testAYearTwoApartNeverElevates(): void
    {
        $yearAfterNext = $this->createYear(2);
        $this->createStaffMember('F_FUTURE', 'f.future@test.com', $yearAfterNext, 'S2', 'chief');
        $this->userRepo->create('f.future@test.com');

        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($yearAfterNext);

        $this->assertSame('identified', $this->resolvedRoleOf('f.future@test.com'));
        $this->assertFalse($this->mayLogIn('f.future@test.com'));
    }

    // ---------------------------------------------------------------
    // The door and the next click must agree
    // ---------------------------------------------------------------

    /**
     * **The test the whole iteration is grouped for.** Signing in and
     * revalidating are two different classes reading two different code
     * paths; if one judges on the year set and the other on a single
     * year, B is signed in and thrown out on their very next request —
     * a defect that reads as a broken session rather than as a missing
     * year, and that no test of either class alone would see.
     */
    public function testBSurvivesTheRequestAfterSigningIn(): void
    {
        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $this->signIn(self::B_EMAIL);

        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame('chief', AuthSession::getRole());

        $revalidator = new SessionRevalidator($this->userRepo, $this->roleResolver);
        $this->assertTrue($revalidator->revalidate($this->authorizationYears()));

        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame('chief', AuthSession::getRole());
    }

    /** The same, for A: nobody is thrown out by the widening either. */
    public function testASurvivesTheRequestAfterSigningIn(): void
    {
        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $this->signIn(self::A_EMAIL);

        $revalidator = new SessionRevalidator($this->userRepo, $this->roleResolver);
        $this->assertTrue($revalidator->revalidate($this->authorizationYears()));
        $this->assertSame('chief', AuthSession::getRole());
    }

    /**
     * And the revalidation still closes the door it is there to close:
     * once the site has switched over, A's live session ends on their
     * next click rather than outliving the transition by 30 days.
     */
    public function testAsLiveSessionEndsWhenTheSiteSwitchesOver(): void
    {
        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);
        $this->signIn(self::A_EMAIL);

        $this->setPublicYear($this->nextYear);
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, '0');

        $revalidator = new SessionRevalidator($this->userRepo, $this->roleResolver);
        $this->assertFalse($revalidator->revalidate($this->authorizationYears()));
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    // ---------------------------------------------------------------
    // The year each of them is SERVED (IT-03)
    // ---------------------------------------------------------------

    /**
     * A stays on the year that is ending — the one where they have a
     * section and animés. Under the old rule their `chief` role, granted
     * by the public year, sent them into the year being prepared, where
     * they exist nowhere.
     */
    public function testAIsServedTheYearThatIsEnding(): void
    {
        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $effective = $this->effectiveYearFor(self::A_EMAIL);

        $this->assertSame($this->endingYear, $effective->id);
        $this->assertNull($effective->overrideType);
    }

    /** B is served the year they were recruited for. */
    public function testBIsServedTheYearBeingPrepared(): void
    {
        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $effective = $this->effectiveYearFor(self::B_EMAIL);

        $this->assertSame($this->nextYear, $effective->id);
        $this->assertSame('staff', $effective->overrideType);
    }

    /** A chief present in both years is served the staff year, as before. */
    public function testAChiefPresentInBothYearsIsServedTheStaffYear(): void
    {
        $this->createStaffMember('G_BOTH', 'g.both@test.com', $this->endingYear, 'S1', 'chief');
        $this->createStaffMember('G_BOTH_NEXT', 'g.both@test.com', $this->nextYear, 'S2', 'chief');
        $this->userRepo->create('g.both@test.com');

        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $effective = $this->effectiveYearFor('g.both@test.com');

        $this->assertSame($this->nextYear, $effective->id);
        $this->assertSame('staff', $effective->overrideType);
    }

    /** An ordinary member is served the public year, staff year or not. */
    public function testAnIdentifiedMemberIsServedThePublicYear(): void
    {
        $this->createStaffMember('H_ANIME', 'h.anime@test.com', $this->endingYear, 'S1', 'identified');
        $this->userRepo->create('h.anime@test.com');

        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $effective = $this->effectiveYearFor('h.anime@test.com');

        $this->assertSame($this->endingYear, $effective->id);
        $this->assertNull($effective->overrideType);
    }

    /**
     * **The point of serving one year per person rather than widening
     * every check downstream.** `SectionStaffAuthorizationService` is
     * untouched by this whole chantier — it still asks its question in
     * exactly one scout year — and it answers non-empty for both A and B,
     * because each of them is served the year where they really staff a
     * section. Every one of its consumers (the Départs page, the section
     * documents, the chief calendar) inherits that for free.
     */
    public function testStaffedSectionsAreNonEmptyForBothOfThemInTheirOwnYear(): void
    {
        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $connection = Connection::withPdo($this->pdo);
        $staffedSections = new SectionStaffAuthorizationService(
            $connection,
            $this->encryption,
            new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo)),
            new MemberEmailRepository($this->pdo, $this->encryption)
        );

        $forA = $staffedSections->getStaffedSections(
            self::A_EMAIL,
            $this->resolvedRoleOf(self::A_EMAIL),
            $this->effectiveYearFor(self::A_EMAIL)->id
        );
        $forB = $staffedSections->getStaffedSections(
            self::B_EMAIL,
            $this->resolvedRoleOf(self::B_EMAIL),
            $this->effectiveYearFor(self::B_EMAIL)->id
        );

        $this->assertSame(['S1'], array_column($forA, 'desk_code'));
        $this->assertSame(['S2'], array_column($forB, 'desk_code'));
    }

    /**
     * The preview decides what is displayed and contributes nothing to
     * the role — so it moves the year served and leaves the role alone.
     * An admin previewing a year they staff nothing in still gets there,
     * which is what makes previewing usable at all.
     */
    public function testThePreviewMovesTheYearServedWithoutTouchingTheRole(): void
    {
        $this->setPublicYear($this->endingYear);

        $role = Role::fromString($this->resolvedRoleOf(self::A_EMAIL));
        $effective = $this->resolverFor(self::A_EMAIL)->getEffectiveYear($this->nextYear, $role);

        $this->assertSame($this->nextYear, $effective->id);
        $this->assertSame('session', $effective->overrideType);
        $this->assertSame('chief', $this->resolvedRoleOf(self::A_EMAIL));
    }

    /**
     * **A caller that can derive a year from the record it judges asks in
     * THAT year, and the staff year changes nothing for it.**
     * `Core\Http\Controller\MemberController::accountStaffsMemberYear()`
     * and `MemberSearchController::canEditScoutYearOffset()` both read the
     * scout year off the `member_year` they are about to write, then ask
     * whoever animates that section that year. Widening either of them
     * would be a bug rather than an improvement: the row being written is
     * the thing that names the year.
     */
    public function testACallerDerivingItsYearFromTheRecordIsUnaffectedByTheStaffYear(): void
    {
        $this->createStaffMember('I_ANIME_S1', 'i.anime@test.com', $this->endingYear, 'S1', 'identified');
        $animeMemberYearId = (int) $this->pdo->query('SELECT MAX(id) FROM member_years')->fetchColumn();

        $connection = Connection::withPdo($this->pdo);
        $memberService = new \Core\Member\MemberService(
            new MemberYearRepository($this->pdo),
            $this->encryption,
            $connection,
            null,
            new MemberEmailRepository($this->pdo, $this->encryption)
        );
        $staffedSections = new SectionStaffAuthorizationService(
            $connection,
            $this->encryption,
            new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo)),
            new MemberEmailRepository($this->pdo, $this->encryption)
        );

        $answers = [];
        foreach ([false, true] as $staffYearOpen) {
            $this->setPublicYear($this->endingYear);
            $this->settingService->setInternal(
                ScoutYearResolver::SETTING_STAFF_YEAR,
                $staffYearOpen ? (string) $this->nextYear : '0'
            );

            // Exactly what the controller does: the year comes off the row.
            $derivedYear = $memberService->getScoutYearIdForMemberYear($animeMemberYearId);
            $this->assertSame($this->endingYear, $derivedYear);

            $answers[] = $staffedSections->staffsAnimeMemberYear(
                self::A_EMAIL,
                $this->resolvedRoleOf(self::A_EMAIL),
                (int) $derivedYear,
                $animeMemberYearId
            );
        }

        $this->assertSame([true, true], $answers);
    }

    // ---------------------------------------------------------------
    // Role boundaries (AGENTS.md § Tests — RBAC coverage)
    // ---------------------------------------------------------------

    /**
     * The role B is granted clears the floor of the Espace animateurs and
     * stops below the one of the Espace chefs d'U — the widening grants a
     * chief's access, never a chef d'unité's.
     */
    public function testBsGrantedRoleClearsChiefAndStopsBelowAdmin(): void
    {
        $this->setPublicYear($this->endingYear);
        $this->setStaffYear($this->nextYear);

        $role = Role::fromString($this->resolvedRoleOf(self::B_EMAIL));

        $this->assertTrue($role->hasAccess(Role::INTENDANT));
        $this->assertTrue($role->hasAccess(Role::CHIEF));
        $this->assertFalse($role->hasAccess(Role::ADMIN));
    }

    /**
     * A super-admin depends on no scout year at all and never has
     * (SECURITY.md § The roster-replacement barrier) — including when the
     * set is empty, which is a freshly installed site.
     */
    public function testSuperAdminIsUnaffectedByTheYearSet(): void
    {
        $this->userRepo->create('root@test.com', true);
        $empty = new AuthorizationYears(null, []);

        $this->assertSame('superadmin', $this->roleResolver->resolveAcrossYears('root@test.com', $empty));
        $this->assertTrue($this->roleResolver->isEmailAuthorizedToLoginAcrossYears('root@test.com', $empty));
    }

    // ---------------------------------------------------------------
    // Fixtures and plumbing
    // ---------------------------------------------------------------

    private function createYear(int $offset): int
    {
        [$label] = DatabaseTestHelper::scoutYear($offset);

        return $this->scoutYearService->ensureYear($label);
    }

    private function setPublicYear(int $id): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $id);
    }

    private function setStaffYear(int $id): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $id);
    }

    /**
     * A ScoutYearResolver wired the way public/index.php wires it, for one
     * named account — the composition root's own closure, minus the
     * session it reads the address out of.
     */
    private function resolverFor(string $email): ScoutYearResolver
    {
        $resolver = new ScoutYearResolver(
            new ScoutYearService($this->pdo),
            $this->settingService,
            new MemberYearRepository($this->pdo)
        );
        $resolver->setStaffYearEligibility(
            fn(int $staffYearId): bool => Role::fromString($this->roleResolver->resolve($email, $staffYearId))
                ->hasAccess(Role::INTENDANT)
        );

        return $resolver;
    }

    private function effectiveYearFor(string $email): \Core\ScoutYear\EffectiveScoutYear
    {
        return $this->resolverFor($email)->getEffectiveYear(
            null,
            Role::fromString($this->resolvedRoleOf($email))
        );
    }

    private function authorizationYearService(): AuthorizationYearService
    {
        return new AuthorizationYearService(new ScoutYearService($this->pdo), $this->settingService);
    }

    /** @return callable(): AuthorizationYears */
    private function authorizationYears(): callable
    {
        return fn(): AuthorizationYears => $this->authorizationYearService()->resolve();
    }

    private function resolvedRoleOf(string $email): string
    {
        return $this->roleResolver->resolveAcrossYears($email, $this->authorizationYearService()->resolve());
    }

    private function mayLogIn(string $email): bool
    {
        return $this->roleResolver->isEmailAuthorizedToLoginAcrossYears(
            $email,
            $this->authorizationYearService()->resolve()
        );
    }

    private function createSection(string $deskCode): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, 0)');
        $stmt->execute(['BR_' . $deskCode, $deskCode]);
        $branchId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (desk_code, age_branch_id, name, is_active) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$deskCode, $branchId, $deskCode]);
    }

    private function sectionId(string $deskCode): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM sections WHERE desk_code = ?');
        $stmt->execute([$deskCode]);

        return (int) $stmt->fetchColumn();
    }

    private function createStaffMember(
        string $deskId,
        string $email,
        int $scoutYearId,
        string $sectionDeskCode,
        string $role
    ): void {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       email_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt($deskId, 'member_years.first_name'),
            $this->encryption->encrypt('Test', 'member_years.last_name'),
            $this->encryption->encrypt(strtolower($email), 'member_years.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $functionCode = 'FN_' . strtoupper($role);
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$functionCode, $functionCode, $role]);
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$functionCode]);
        $functionId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $this->sectionId($sectionDeskCode)]);
    }

    /**
     * Drives the real controller: POST /login/magic-link, read the URL out
     * of the rendered mail, GET /auth/verify with what it carried. The
     * session that comes back is the one a member would get.
     */
    private function signIn(string $email): void
    {
        $this->sentMail = null;
        CsrfGuard::generateToken();

        $response = $this->controller->requestMagicLink(
            new Request('POST', '/login/magic-link', [], [
                'email' => $email,
                '_csrf_token' => CsrfGuard::generateToken(),
                'rgpd_consent' => '1',
            ], [], []),
            []
        );

        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success'] ?? false, 'magic link request refused: ' . ($body['error'] ?? ''));

        $this->assertNotNull($this->sentMail, 'no magic link mail was sent');
        // `&amp;` when the link is read out of the HTML half.
        preg_match('/token=([a-f0-9]+)&(?:amp;)?id=(\d+)/', (string) $this->sentMail['body'], $matches);
        $this->assertNotEmpty($matches, 'the mail carried no usable verification link');

        $this->controller->verifyMagicLink(
            new Request('GET', '/auth/verify', ['token' => $matches[1], 'id' => $matches[2]], [], [], []),
            []
        );
    }

    private function buildAuthStack(MemberYearRepository $memberYearRepo): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willReturnCallback(
            function (string $to, string $subject, string $bodyHtml, string $bodyText): void {
                $this->sentMail = [
                    'to' => $to,
                    'subject' => $subject,
                    'body' => $bodyText !== '' ? $bodyText : $bodyHtml,
                ];
            }
        );

        $twig = $this->buildTwig();

        $this->authService = new AuthService(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, $twig),
            'https://example.test',
            'Test Unit'
        );

        $this->controller = new AuthController(
            $twig,
            $this->authService,
            $this->roleResolver,
            new ScoutYearResolver($this->scoutYearService, $this->settingService, $memberYearRepo),
            null,
            $this->authorizationYearService()
        );
    }

    private function buildTwig(): Environment
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/core/View/templates'),
            ['cache' => false, 'autoescape' => 'html']
        );

        $twig->addFunction(new \Twig\TwigFunction('asset', static fn(string $path): string => $path));
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn(): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn(): string => 'test-csrf-token'));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn(): ?array => null));
        $twig->addFunction(new \Twig\TwigFunction('editable', fn(): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('person_avatar', function (string $name, array $options = []): string {
            return \Core\View\PersonAvatar::render($name, null, (int) ($options['size'] ?? 40));
        }, ['is_safe' => ['html']]));

        return $twig;
    }
}
