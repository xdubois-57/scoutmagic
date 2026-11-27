<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Config\ScoutYearService;
use Core\Member\Movement\MemberMovementClassifierService;
use Core\Member\Movement\MemberMovementRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionRosterEntry;
use Core\Member\SectionRosterRepository;
use Core\Member\MemberProfile;
use Core\Member\SectionRosterService;
use Core\Module\HookRegistry;
use Core\Module\SectionResponsableProvider;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

#[Group('database')]
class SectionRosterServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SectionRosterService $service;
    private int $scoutYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $memberEmailRepository = new MemberEmailRepository($this->pdo, $this->encryption);
        $scoutYearService = new ScoutYearService($this->pdo);
        $movementClassifier = new MemberMovementClassifierService(new MemberMovementRepository($this->pdo), $scoutYearService);
        // The repository decrypts now (#551), so the Service takes no
        // EncryptionService: it cannot read an encrypted column at all.
        $this->service = new SectionRosterService(
            new SectionRosterRepository($this->pdo, $this->encryption),
            $memberEmailRepository,
            $movementClassifier,
            new \Core\Member\Repository\MemberSectionTotemRepository($this->pdo, $this->encryption)
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->sectionId = $this->createSection('LOU01', $branchId, 'Ma section');
    }

    public function testMembersAreClassifiedIntoTheThreeBuckets(): void
    {
        $this->createMember('Alice', 'chief');
        $this->createMember('Bob', 'intendant');
        $this->createMember('Chloé', 'identified');

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertCount(1, $roster[$this->sectionId]['animateurs']);
        $this->assertSame('Alice', $roster[$this->sectionId]['animateurs'][0]->firstName);
        $this->assertCount(1, $roster[$this->sectionId]['intendants']);
        $this->assertSame('Bob', $roster[$this->sectionId]['intendants'][0]->firstName);
        $this->assertCount(1, $roster[$this->sectionId]['animes']);
        $this->assertSame('Chloé', $roster[$this->sectionId]['animes'][0]->firstName);
    }

    /**
     * Issue #722: each row carries the totem of ITS section, normalized,
     * and nothing from another section.
     */
    public function testARowCarriesTheSectionTotemOfItsOwnSection(): void
    {
        $akela = $this->createMember('Alice', 'chief');
        $bob = $this->createMember('Bob', 'chief');
        $totems = new \Core\Member\Repository\MemberSectionTotemRepository($this->pdo, $this->encryption);
        $totems->set($akela, $this->sectionId, 'akela');
        $otherSection = $this->createSection('LOU02', (int) $this->pdo->query('SELECT id FROM age_branches LIMIT 1')->fetchColumn());
        $totems->set($bob, $otherSection, 'Hathi');

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertSame('Akela', $this->findRow($roster, $akela)->sectionTotem);
        $this->assertNull($this->findRow($roster, $bob)->sectionTotem);
    }

    // ── The responsable first (issue #1336) ─────────────────────────────

    public function testTheResponsableOpensTheAnimateursAndTheOthersStayAlphabetical(): void
    {
        $alice = $this->createMember('Alice', 'chief');
        $bob = $this->createMember('Bob', 'chief');
        $zoe = $this->createMember('Zoé', 'chief');
        $calls = new \ArrayObject();

        $roster = $this->serviceAnswering([$this->sectionId => $zoe], $calls)
            ->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertSame([$zoe, $alice, $bob], $this->memberYearIds($roster[$this->sectionId]['animateurs']));
        $this->assertSame([[[$this->sectionId], $this->scoutYearId]], $calls->getArrayCopy());
    }

    public function testWithoutAResponsableOrWithoutTheHookTheOrderStaysAlphabetical(): void
    {
        $alice = $this->createMember('Alice', 'chief');
        $zoe = $this->createMember('Zoé', 'chief');

        $noResponsable = $this->serviceAnswering([$this->sectionId => null])->buildRoster([$this->sectionId], $this->scoutYearId);
        $noHook = $this->serviceWith(new HookRegistry())->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertSame([$alice, $zoe], $this->memberYearIds($noResponsable[$this->sectionId]['animateurs']));
        $this->assertSame([$alice, $zoe], $this->memberYearIds($noHook[$this->sectionId]['animateurs']));
    }

    /**
     * The provider says who leads the section; it does not decide who is in
     * its list. A responsable who is not one of its animateurs — here its
     * intendant — is neither added nor moved, and nothing is duplicated.
     */
    public function testAResponsableOutsideTheAnimateursIsNeverAddedOrMoved(): void
    {
        $alice = $this->createMember('Alice', 'chief');
        $zoe = $this->createMember('Zoé', 'chief');
        $bob = $this->createMember('Bob', 'intendant');
        $chloe = $this->createMember('Chloé', 'identified');
        $david = $this->createMember('David', 'identified');

        $roster = $this->serviceAnswering([$this->sectionId => $bob])->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertSame([$alice, $zoe], $this->memberYearIds($roster[$this->sectionId]['animateurs']));
        $this->assertSame([$bob], $this->memberYearIds($roster[$this->sectionId]['intendants']));
        $this->assertSame([$chloe, $david], $this->memberYearIds($roster[$this->sectionId]['animes']));
    }

    /** Intendants and animés keep their alphabetical order whoever leads the section. */
    public function testOnlyTheAnimateursAreReordered(): void
    {
        $this->createMember('Alice', 'chief');
        $zoe = $this->createMember('Zoé', 'chief');
        $chloe = $this->createMember('Chloé', 'identified');
        $yann = $this->createMember('Yann', 'identified');

        $roster = $this->serviceAnswering([$this->sectionId => $zoe])->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertSame($zoe, $roster[$this->sectionId]['animateurs'][0]->memberYearId);
        $this->assertSame([$chloe, $yann], $this->memberYearIds($roster[$this->sectionId]['animes']));
    }

    /**
     * « Toutes » : every section opens on ITS responsable, independently of
     * the others, and the provider is asked once for all of them — never
     * once per section.
     */
    public function testEverySectionShownOpensOnItsOwnResponsableFromOneCall(): void
    {
        $branchId = (int) $this->pdo->query('SELECT id FROM age_branches LIMIT 1')->fetchColumn();
        $other = $this->createSection('LOU02', $branchId, 'Autre section');
        $alice = $this->createMember('Alice', 'chief');
        $zoe = $this->createMember('Zoé', 'chief');
        $bea = $this->createMember('Béa', 'chief', $other);
        $yves = $this->createMember('Yves', 'chief', $other);
        $calls = new \ArrayObject();

        $roster = $this->serviceAnswering([$this->sectionId => $zoe, $other => $bea], $calls)
            ->buildRoster([$this->sectionId, $other], $this->scoutYearId);

        $this->assertSame([$zoe, $alice], $this->memberYearIds($roster[$this->sectionId]['animateurs']));
        $this->assertSame([$bea, $yves], $this->memberYearIds($roster[$other]['animateurs']));
        $this->assertSame([[[$this->sectionId, $other], $this->scoutYearId]], $calls->getArrayCopy());
    }

    /**
     * The year asked is the year shown — a preview of another year asks
     * for THAT year's responsable, never the current one.
     */
    public function testTheResponsableIsAskedForTheYearShown(): void
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)");
        $nextYear = (int) $this->pdo->lastInsertId();
        $current = $this->scoutYearId;
        $this->scoutYearId = $nextYear;
        $this->createMember('Alice', 'chief');
        $zoe = $this->createMember('Zoé', 'chief');
        $this->scoutYearId = $current;
        $calls = new \ArrayObject();

        $roster = $this->serviceAnswering([$this->sectionId => $zoe], $calls)->buildRoster([$this->sectionId], $nextYear);

        $this->assertSame($zoe, $roster[$this->sectionId]['animateurs'][0]->memberYearId);
        $this->assertSame([[[$this->sectionId], $nextYear]], $calls->getArrayCopy());
    }

    /** A list of one, or none, has nothing to reorder: the provider is not asked at all. */
    public function testNothingToReorderAsksNothing(): void
    {
        $alice = $this->createMember('Alice', 'chief');
        $calls = new \ArrayObject();

        $roster = $this->serviceAnswering([$this->sectionId => $alice], $calls)->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertSame([$alice], $this->memberYearIds($roster[$this->sectionId]['animateurs']));
        $this->assertSame([], $calls->getArrayCopy());
    }

    public function testEmptyBucketsAreReturnedAsEmptyArraysNotMissing(): void
    {
        $this->createMember('Alice', 'identified');

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertSame([], $roster[$this->sectionId]['animateurs']);
        $this->assertSame([], $roster[$this->sectionId]['intendants']);
        $this->assertCount(1, $roster[$this->sectionId]['animes']);
    }

    public function testMainFunctionDecidesTheBucketWhenAMemberHasSeveralFunctionsInTheSameSection(): void
    {
        $memberYearId = $this->createMember('Alice', 'identified');
        // A secondary, non-main "chief" function must not move Alice into
        // the animateurs bucket — only the main function decides.
        $this->attachFunction($memberYearId, 'chief', false);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);

        $this->assertCount(1, $roster[$this->sectionId]['animes']);
        $this->assertSame([], $roster[$this->sectionId]['animateurs']);
    }

    public function testMemberWithNoEmailHasAnEmptyEmailList(): void
    {
        $memberYearId = $this->createMemberWithContacts('Alice', null, null, null);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);

        $row = $this->findRow($roster, $memberYearId);
        $this->assertSame([], $row->emails);
    }

    public function testMemberWithOneEmailHasExactlyOne(): void
    {
        $memberYearId = $this->createMemberWithContacts('Alice', 'alice@example.com', null, null);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);

        $row = $this->findRow($roster, $memberYearId);
        $this->assertSame(['alice@example.com'], $row->emails);
    }

    public function testMemberWithSeveralEmailsHasAllOfThem(): void
    {
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, 'alice@example.com', null, null);
        $this->attachFunctionTo($memberYearId, 'identified');

        // A confirmed secondary address.
        $stmt = $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, 'manual', 'valid')"
        );
        $stmt->execute([$memberId, $this->encryption->encrypt('alice.secondary@example.com', 'member_emails.email'), $this->encryption->blindIndex('alice.secondary@example.com', 'email')]);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);
        $row = $this->findRow($roster, $memberYearId);

        $this->assertCount(2, $row->emails);
        $this->assertContains('alice@example.com', $row->emails);
        $this->assertContains('alice.secondary@example.com', $row->emails);
    }

    public function testPendingAndInactiveSecondaryEmailsAreNotIncluded(): void
    {
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, 'alice@example.com', null, null);
        $this->attachFunctionTo($memberYearId, 'identified');

        $stmt = $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, 'manual', 'pending')"
        );
        $stmt->execute([$memberId, $this->encryption->encrypt('pending@example.com', 'member_emails.email'), $this->encryption->blindIndex('pending@example.com', 'email')]);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);
        $row = $this->findRow($roster, $memberYearId);

        $this->assertSame(['alice@example.com'], $row->emails);
    }

    public function testMemberWithNoPhoneHasAnEmptyPhoneList(): void
    {
        $memberYearId = $this->createMemberWithContacts('Alice', null, null, null);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);
        $row = $this->findRow($roster, $memberYearId);

        $this->assertSame([], $row->phones);
    }

    public function testMemberWithOnePhoneHasExactlyOne(): void
    {
        $memberYearId = $this->createMemberWithContacts('Alice', null, '0470123456', null);

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);
        $row = $this->findRow($roster, $memberYearId);

        $this->assertCount(1, $row->phones);
        $this->assertSame('Téléphone', $row->phones[0]['label']);
    }

    public function testMemberWithBothPhoneAndMobileHasBoth(): void
    {
        $memberYearId = $this->createMemberWithContacts('Alice', null, '024651234', '0470123456');

        $roster = $this->service->buildRoster([$this->sectionId], $this->scoutYearId);
        $row = $this->findRow($roster, $memberYearId);

        $this->assertCount(2, $row->phones);
        $labels = array_column($row->phones, 'label');
        $this->assertContains('Téléphone', $labels);
        $this->assertContains('GSM', $labels);
    }

    /**
     * @param array<int, array{animateurs: \Core\Member\MemberRosterRow[], intendants: \Core\Member\MemberRosterRow[], animes: \Core\Member\MemberRosterRow[]}> $roster
     */
    private function findRow(array $roster, int $memberYearId): \Core\Member\MemberRosterRow
    {
        foreach ($roster[$this->sectionId] as $bucket) {
            foreach ($bucket as $row) {
                if ($row->memberYearId === $memberYearId) {
                    return $row;
                }
            }
        }
        $this->fail('Row not found for member_year_id ' . $memberYearId);
    }

    private function createSection(string $deskCode, int $branchId, ?string $name = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createMember(string $firstName, string $functionRole, ?int $sectionId = null): int
    {
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, 'x@example.com', '0470000000', null, $firstName);
        $this->attachFunction($memberYearId, $functionRole, true, $sectionId);
        return $memberYearId;
    }

    private function serviceWith(HookRegistry $hooks): SectionRosterService
    {
        return new SectionRosterService(
            new SectionRosterRepository($this->pdo, $this->encryption),
            new MemberEmailRepository($this->pdo, $this->encryption),
            new MemberMovementClassifierService(new MemberMovementRepository($this->pdo), new ScoutYearService($this->pdo)),
            new \Core\Member\Repository\MemberSectionTotemRepository($this->pdo, $this->encryption),
            $hooks
        );
    }

    /**
     * A service whose SectionResponsableProvider answers $responsableBySection
     * (section id => member year id of the responsable, or null), recording
     * every getResponsables() call in $calls as [section ids, year].
     *
     * @param array<int, ?int> $responsableBySection
     */
    private function serviceAnswering(array $responsableBySection, ?\ArrayObject $calls = null): SectionRosterService
    {
        $profiles = [];
        foreach ($responsableBySection as $sectionId => $memberYearId) {
            $profiles[$sectionId] = $memberYearId === null ? null : $this->profileOf($memberYearId);
        }
        $hooks = new HookRegistry();
        $hooks->register(SectionResponsableProvider::class, new class ($profiles, $calls) implements SectionResponsableProvider {
            /** @param array<int, ?MemberProfile> $profiles */
            public function __construct(private readonly array $profiles, private readonly ?\ArrayObject $calls)
            {
            }

            public function getResponsable(int $sectionId, int $scoutYearId): ?MemberProfile
            {
                throw new \LogicException('the roster asks for every section at once');
            }

            public function getResponsables(array $sectionIds, int $scoutYearId): array
            {
                $this->calls?->append([array_values($sectionIds), $scoutYearId]);
                $answer = [];
                foreach ($sectionIds as $sectionId) {
                    $answer[$sectionId] = $this->profiles[$sectionId] ?? null;
                }

                return $answer;
            }
        });

        return $this->serviceWith($hooks);
    }

    private function profileOf(int $memberYearId): MemberProfile
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM member_years WHERE id = ?');
        $stmt->execute([$memberYearId]);

        return new MemberProfile(
            memberYearId: $memberYearId,
            memberId: (int) $stmt->fetchColumn(),
            deskId: 'x',
            firstName: 'x',
            lastName: 'x',
            totem: null,
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: null,
            email: null,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [],
            scoutYearLabel: ''
        );
    }

    /**
     * @param \Core\Member\MemberRosterRow[] $rows
     * @return list<int>
     */
    private function memberYearIds(array $rows): array
    {
        return array_map(static fn(\Core\Member\MemberRosterRow $row): int => $row->memberYearId, $rows);
    }


    private function createMemberWithContacts(string $firstName, ?string $email, ?string $phone, ?string $mobile): int
    {
        $memberId = $this->insertMemberBase();
        $memberYearId = $this->insertMemberYear($memberId, $email, $phone, $mobile, $firstName);
        $this->attachFunctionTo($memberYearId, 'identified');
        return $memberYearId;
    }

    private function insertMemberBase(): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D" . uniqid() . "')");
        return (int) $this->pdo->lastInsertId();
    }

    private function insertMemberYear(int $memberId, ?string $email, ?string $phone, ?string $mobile, string $firstName = 'Alice'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, phone_encrypted, mobile_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $email !== null ? $this->encryption->encrypt($email, 'member_years.email') : null,
            $email !== null ? $this->encryption->blindIndex(strtolower($email), 'email') : null,
            $phone !== null ? $this->encryption->encrypt($phone, 'member_years.phone') : null,
            $mobile !== null ? $this->encryption->encrypt($mobile, 'member_years.mobile') : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function attachFunctionTo(int $memberYearId, string $functionRole): void
    {
        $this->attachFunction($memberYearId, $functionRole, true);
    }

    private function attachFunction(int $memberYearId, string $functionRole, bool $isMain, ?int $sectionId = null): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$functionRole]);
        $functionId = $stmt->fetchColumn();
        if ($functionId === false) {
            $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)')->execute([$functionRole, ucfirst($functionRole), $functionRole]);
            $functionId = (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId ?? $this->sectionId, $isMain ? 1 : 0]);
    }
}
