<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear;

use Core\Import\MemberYearRepository;
use Core\ScoutYear\StaffYearEligibility;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class StaffYearEligibilityTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private int $endingYear;
    private int $nextYear;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->endingYear = $this->createYear(0);
        $this->nextYear = $this->createYear(1);
    }

    private function createYear(int $offset): int
    {
        [$label, $start, $end] = DatabaseTestHelper::scoutYear($offset);
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)');
        $stmt->execute([$label, $start, $end]);

        return (int) $this->pdo->lastInsertId();
    }

    private function eligibility(): StaffYearEligibility
    {
        return new StaffYearEligibility(
            new RoleResolver(new MemberYearRepository($this->pdo), $this->encryption, $this->pdo)
        );
    }

    private function giveFunction(string $email, int $scoutYearId, string $role): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute(['F_' . strtoupper($role), 'F_' . strtoupper($role), $role]);
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute(['F_' . strtoupper($role)]);
        $functionId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       email_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt('Prénom', 'member_years.first_name'),
            $this->encryption->encrypt('Nom', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, is_main_function) VALUES (?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId]);
    }

    public function testAChiefOfTheTargetYearIsEligible(): void
    {
        $this->giveFunction('arriving@test.com', $this->nextYear, 'chief');

        $this->assertTrue($this->eligibility()->isEligible('arriving@test.com', $this->nextYear));
    }

    /**
     * The animateur who is leaving: a chief of the year that is ending,
     * nobody at all in the one being prepared. Asked in the target year,
     * the answer is no — which is what keeps them on the year where they
     * still have a section.
     */
    public function testAChiefOfTheOtherYearIsNotEligible(): void
    {
        $this->giveFunction('leaving@test.com', $this->endingYear, 'chief');

        $this->assertFalse($this->eligibility()->isEligible('leaving@test.com', $this->nextYear));
    }

    public function testAnIdentifiedMemberOfTheTargetYearIsNotEligible(): void
    {
        $this->giveFunction('anime@test.com', $this->nextYear, 'identified');

        $this->assertFalse($this->eligibility()->isEligible('anime@test.com', $this->nextYear));
    }

    public function testIntendantIsTheThreshold(): void
    {
        $this->giveFunction('steward@test.com', $this->nextYear, 'intendant');

        $this->assertTrue($this->eligibility()->isEligible('steward@test.com', $this->nextYear));
    }

    public function testAnAnonymousVisitorIsNeverEligible(): void
    {
        $this->assertFalse($this->eligibility()->isEligible(null, $this->nextYear));
        $this->assertFalse($this->eligibility()->isEligible('', $this->nextYear));
    }

    /**
     * **The bug this class was extracted for.** A request resolves the
     * effective year once for the front controller — where a login is
     * still anonymous — and again inside the controller, after the
     * session exists. A cache keyed on the year alone answers the second
     * question with the first one's answer, and the animateur recruited
     * for the year being prepared signs in to a site that records their
     * members against the year they have none in.
     */
    public function testTheAnswerFollowsTheAddressWhenAnIdentityAppearsMidRequest(): void
    {
        $this->giveFunction('arriving@test.com', $this->nextYear, 'chief');
        $eligibility = $this->eligibility();

        // The front controller, before routing: nobody is signed in yet.
        $this->assertFalse($eligibility->isEligible(null, $this->nextYear));

        // The same instance, after AuthSession::login() has run.
        $this->assertTrue($eligibility->isEligible('arriving@test.com', $this->nextYear));
    }

    /**
     * A page resolves the effective year a dozen times and each answer
     * costs a role resolution, so the same question is asked of the
     * database once.
     */
    public function testTheSameQuestionCostsOneResolution(): void
    {
        $this->giveFunction('arriving@test.com', $this->nextYear, 'chief');

        $counted = new class (new MemberYearRepository($this->pdo), $this->encryption, $this->pdo) extends RoleResolver {
            public int $calls = 0;

            public function resolve(string $email, int $currentScoutYearId): string
            {
                $this->calls++;

                return parent::resolve($email, $currentScoutYearId);
            }
        };
        $eligibility = new StaffYearEligibility($counted);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($eligibility->isEligible('arriving@test.com', $this->nextYear));
        }

        $this->assertSame(1, $counted->calls);
    }
}
