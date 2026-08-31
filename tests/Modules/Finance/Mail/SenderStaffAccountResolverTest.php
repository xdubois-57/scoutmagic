<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Mail;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\Security\EncryptionService;
use Modules\Finance\Mail\SenderStaffAccountResolver;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * « Cette adresse anime-t-elle un seul staff, et ce staff a-t-il un seul
 * compte ? »
 *
 * Two "exactly one" in a row, and every test below is really about the
 * refusals. Resolving the happy case is easy; what keeps a receipt off the
 * wrong section's books is that everything ambiguous answers null.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SenderStaffAccountResolverTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AccountRepository $accounts;
    private SenderStaffAccountResolver $resolver;
    private int $scoutYearId = 1;
    private int $louveteauxId = 1;
    private int $eclaireursId = 2;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accounts = new AccountRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (1, '2026-2027', '2026-09-01', '2027-08-31', 1)");
        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 20), (2, 'ECL', 'Éclaireurs', 30)");
        $this->pdo->exec("INSERT INTO sections (id, age_branch_id, desk_code, name) VALUES (1, 1, 'LOU01', 'Louveteaux'), (2, 2, 'ECL01', 'Éclaireurs')");
        // 'chief' is what makes a member_functions row a STAFF row: an
        // animé carries the same section_id, with an 'identified' function.
        $this->pdo->exec("INSERT INTO functions (id, desk_code, label, role) VALUES (1, 'ANIM', 'Animateur', 'chief'), (2, 'ANIME', 'Animé', 'identified')");

        $connection = Connection::withPdo($this->pdo);
        $this->resolver = new SenderStaffAccountResolver(
            new SectionStaffAuthorizationService(
                $connection,
                $this->encryption,
                new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo)),
                // Never omitted: without it an animateur writing from a
                // confirmed secondary address staffs no section at all, and
                // their receipt lands in the sorting pile for no reason.
                new MemberEmailRepository($this->pdo, $this->encryption)
            ),
            $this->accounts,
            $this->scoutYearId
        );
    }

    public function testAnAnimateurOfOneStaffResolvesToThatStaffsAccount(): void
    {
        $this->animateur('anna@example.be', $this->louveteauxId);
        $accountId = $this->activeAccountFor($this->louveteauxId);

        $resolved = $this->resolver->resolve('anna@example.be');

        $this->assertNotNull($resolved);
        $this->assertSame($accountId, $resolved->id);
    }

    public function testTheAddressIsMatchedCaseAndSpaceInsensitively(): void
    {
        $this->animateur('anna@example.be', $this->louveteauxId);
        $this->activeAccountFor($this->louveteauxId);

        $this->assertNotNull($this->resolver->resolve('  Anna@Example.BE '));
    }

    public function testAConfirmedSecondaryAddressReachesTheSameMember(): void
    {
        // An animateur writing from their personal address is still that
        // animateur — the same resolution the whole site uses.
        $memberId = $this->animateur('anna@desk.be', $this->louveteauxId);
        $accountId = $this->activeAccountFor($this->louveteauxId);
        $this->secondaryEmail($memberId, 'anna.perso@example.be', 'valid');

        $resolved = $this->resolver->resolve('anna.perso@example.be');

        $this->assertNotNull($resolved);
        $this->assertSame($accountId, $resolved->id);
    }

    public function testAnUnconfirmedSecondaryAddressGrantsNothing(): void
    {
        // 'pending' means nobody has proved they read that mailbox. Same
        // rule as Core\Security\RoleResolver.
        $memberId = $this->animateur('anna@desk.be', $this->louveteauxId);
        $this->activeAccountFor($this->louveteauxId);
        $this->secondaryEmail($memberId, 'pas-encore@example.be', 'pending');

        $this->assertNull($this->resolver->resolve('pas-encore@example.be'));
    }

    // ── Tout ce qui est ambigu répond null ───────────────────────────────

    public function testAnAnimateurOfTwoStaffsResolvesToNothing(): void
    {
        $this->animateur('anna@example.be', $this->louveteauxId);
        $this->animateur('anna@example.be', $this->eclaireursId);
        $this->activeAccountFor($this->louveteauxId);
        $this->activeAccountFor($this->eclaireursId);

        $this->assertNull($this->resolver->resolve('anna@example.be'));
    }

    public function testAStaffWithTwoActiveAccountsResolvesToNothing(): void
    {
        $this->animateur('anna@example.be', $this->louveteauxId);
        $this->activeAccountFor($this->louveteauxId, 'Caisse');
        $this->activeAccountFor($this->louveteauxId, 'Compte courant');

        $this->assertNull($this->resolver->resolve('anna@example.be'));
    }

    public function testAStaffWhoseOnlyAccountIsStillADraftResolvesToNothing(): void
    {
        // Every picker in the module excludes a draft, so filing onto one
        // would put a receipt somewhere no screen offers to look. This is
        // the state of a section account nobody has activated yet —
        // FinanceService::ensureDefaultAccountsForSections() creates them
        // that way.
        $this->animateur('anna@example.be', $this->louveteauxId);
        $this->accounts->create('Louveteaux', Account::TYPE_BANK, $this->louveteauxId, null, null, 'intendant');

        $this->assertNull($this->resolver->resolve('anna@example.be'));
    }

    public function testAStaffWithNoAccountAtAllResolvesToNothing(): void
    {
        $this->animateur('anna@example.be', $this->louveteauxId);

        $this->assertNull($this->resolver->resolve('anna@example.be'));
    }

    public function testAnUnknownAddressResolvesToNothing(): void
    {
        $this->activeAccountFor($this->louveteauxId);

        $this->assertNull($this->resolver->resolve('etranger@example.be'));
    }

    public function testAnAnimeIsNotAnAnimateurOfTheirOwnSection(): void
    {
        // The trap SectionService::getSectionStaff() guards against: an
        // animé's member_functions row carries the same section_id as the
        // staff's. Without the role filter every child would resolve to
        // their section's account.
        $this->animateur('enfant@example.be', $this->louveteauxId, functionId: 2);
        $this->activeAccountFor($this->louveteauxId);

        $this->assertNull($this->resolver->resolve('enfant@example.be'));
    }

    public function testAnEmptyOrMalformedAddressResolvesToNothing(): void
    {
        $this->assertNull($this->resolver->resolve(''));
        $this->assertNull($this->resolver->resolve('   '));
        $this->assertNull($this->resolver->resolve('pas-une-adresse'));
    }

    public function testAMemberOfAPreviousYearResolvesToNothing(): void
    {
        // The question is who staffs a section NOW. Last year's animateur
        // is not this year's.
        $this->animateur('anna@example.be', $this->louveteauxId, scoutYearId: 2);
        $this->activeAccountFor($this->louveteauxId);

        $this->assertNull($this->resolver->resolve('anna@example.be'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function animateur(
        string $email,
        int $sectionId,
        int $functionId = 1,
        ?int $scoutYearId = null
    ): int {
        static $nextMemberId = 0;

        $memberId = ++$nextMemberId;
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId ?? $this->scoutYearId,
            'x',
            'y',
            $this->encryption->blindIndex(strtolower($email), 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)');
        $stmt->execute([$memberYearId, $functionId, $sectionId]);

        return $memberId;
    }

    private function secondaryEmail(int $memberId, string $email, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->encryption->encrypt($email),
            $this->encryption->blindIndex(strtolower($email), 'email'),
            $status,
        ]);
    }

    private function activeAccountFor(int $sectionId, string $name = 'Compte de section'): int
    {
        $id = $this->accounts->create($name, Account::TYPE_BANK, $sectionId, null, null, 'intendant');
        $this->accounts->updateStatus($id, Account::STATUS_ACTIVE);

        return $id;
    }
}
