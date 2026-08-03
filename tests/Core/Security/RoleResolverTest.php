<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Import\MemberYearRepository;
use Core\Member\MemberEmailRepository;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class RoleResolverTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RoleResolver $resolver;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        );

        $memberYearRepo = new MemberYearRepository($this->pdo);
        $this->resolver = new RoleResolver($memberYearRepo, $this->encryption, $this->pdo);

        // Create scout year
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    private function createUserAccount(string $email, bool $isSuperAdmin = false): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, ?)'
        );
        $blindIndex = $this->encryption->blindIndex(strtolower($email));
        $stmt->execute([$this->encryption->encrypt(strtolower($email)), $blindIndex, $isSuperAdmin ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createMemberWithFunction(string $email, string $functionCode, string $role, bool $confirmed): void
    {
        $normalizedEmail = strtolower($email);
        $blindIndex = $this->encryption->blindIndex($normalizedEmail);

        // Create member
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('desk_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        // Create function
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$functionCode, $functionCode, $role, $confirmed ? 1 : 0]);
        $fnStmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $fnStmt->execute([$functionCode]);
        $functionId = (int) $fnStmt->fetchColumn();

        // Create member_year
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt('Test'),
            $this->encryption->encrypt('User'),
            $this->encryption->encrypt($normalizedEmail),
            $blindIndex,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        // Create member_function
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, is_main_function) VALUES (?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId]);
    }

    public function testSuperAdminReturnsSuperadminRole(): void
    {
        $this->createUserAccount('admin@test.com', true);
        $role = $this->resolver->resolve('admin@test.com', $this->scoutYearId);
        $this->assertSame('superadmin', $role);
    }

    public function testMemberWithConfirmedChiefFunctionReturnsChief(): void
    {
        $this->createUserAccount('chief@test.com');
        $this->createMemberWithFunction('chief@test.com', 'Animateur', 'chief', true);

        $role = $this->resolver->resolve('chief@test.com', $this->scoutYearId);
        $this->assertSame('chief', $role);
    }

    public function testMemberWithUnconfirmedFunctionReturnsIdentified(): void
    {
        $this->createUserAccount('newbie@test.com');
        $this->createMemberWithFunction('newbie@test.com', 'Unknown Function', 'identified', false);

        $role = $this->resolver->resolve('newbie@test.com', $this->scoutYearId);
        $this->assertSame('identified', $role);
    }

    public function testMemberWithMultipleFunctionsReturnsHighestRole(): void
    {
        $this->createUserAccount('multi@test.com');
        $this->createMemberWithFunction('multi@test.com', 'Animé', 'identified', true);

        // Add a second function with higher role on same member_year... 
        // We need to add another member_function to the existing member_year
        $blindIndex = $this->encryption->blindIndex('multi@test.com');
        $stmt = $this->pdo->prepare('SELECT my.id FROM member_years my WHERE my.email_blind_index = ?');
        $stmt->execute([$blindIndex]);
        $memberYearId = (int) $stmt->fetchColumn();

        // Create chief function
        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role, confirmed) VALUES ('Animateur', 'Animateur', 'chief', 1)");
        $fnStmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $fnStmt->execute(['Animateur']);
        $fnId = (int) $fnStmt->fetchColumn();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, is_main_function) VALUES (?, ?, 0)');
        $stmt->execute([$memberYearId, $fnId]);

        $role = $this->resolver->resolve('multi@test.com', $this->scoutYearId);
        $this->assertSame('chief', $role);
    }

    public function testUnknownEmailReturnsIdentified(): void
    {
        $this->createUserAccount('nobody@test.com');
        $role = $this->resolver->resolve('nobody@test.com', $this->scoutYearId);
        $this->assertSame('identified', $role);
    }

    public function testGetLinkedMemberYearsReturnsCorrectIds(): void
    {
        $this->createUserAccount('linked@test.com');
        $this->createMemberWithFunction('linked@test.com', 'Animé', 'identified', true);

        $ids = $this->resolver->getLinkedMemberYears('linked@test.com', $this->scoutYearId);
        $this->assertCount(1, $ids);
        $this->assertIsInt($ids[0]);
    }

    public function testGetLinkedMemberYearsReturnsEmptyForUnknown(): void
    {
        $ids = $this->resolver->getLinkedMemberYears('notfound@test.com', $this->scoutYearId);
        $this->assertEmpty($ids);
    }

    // --- isEmailAuthorizedToLogin() (module addendum: login must fail with no matching member) ---

    public function testSuperAdminIsAlwaysAuthorizedEvenWithNoMatchingMember(): void
    {
        $this->createUserAccount('admin-no-member@test.com', true);

        $this->assertTrue($this->resolver->isEmailAuthorizedToLogin('admin-no-member@test.com', $this->scoutYearId));
    }

    public function testMemberWithCurrentYearRowIsAuthorized(): void
    {
        $this->createUserAccount('member@test.com');
        $this->createMemberWithFunction('member@test.com', 'Animé', 'identified', true);

        $this->assertTrue($this->resolver->isEmailAuthorizedToLogin('member@test.com', $this->scoutYearId));
    }

    public function testAccountWithNoMatchingMemberIsNotAuthorized(): void
    {
        $this->createUserAccount('orphan@test.com', false);

        $this->assertFalse($this->resolver->isEmailAuthorizedToLogin('orphan@test.com', $this->scoutYearId));
    }

    public function testUnknownEmailIsNotAuthorized(): void
    {
        $this->assertFalse($this->resolver->isEmailAuthorizedToLogin('never-seen@test.com', $this->scoutYearId));
    }

    // --- Multi-email support: login via a valid secondary address (module addendum) ---

    private function memberIdForEmail(string $email): int
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM member_years WHERE email_blind_index = ?');
        $stmt->execute([$this->encryption->blindIndex(strtolower($email))]);
        return (int) $stmt->fetchColumn();
    }

    private function addMemberEmail(int $memberId, string $email, string $status): void
    {
        $normalized = strtolower($email);
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->encryption->encrypt($normalized),
            $this->encryption->blindIndex($normalized),
            'manual',
            $status,
        ]);
    }

    private function resolverWithSecondaryEmailSupport(): RoleResolver
    {
        return new RoleResolver(
            new MemberYearRepository($this->pdo),
            $this->encryption,
            $this->pdo,
            new MemberEmailRepository($this->pdo, $this->encryption)
        );
    }

    public function testResolveMatchesAValidSecondaryEmail(): void
    {
        $this->createUserAccount('chief-desk@test.com');
        $this->createMemberWithFunction('chief-desk@test.com', 'Animateur', 'chief', true);
        $memberId = $this->memberIdForEmail('chief-desk@test.com');
        $this->addMemberEmail($memberId, 'chief-secondary@test.com', 'valid');

        $resolver = $this->resolverWithSecondaryEmailSupport();

        $this->assertSame('chief', $resolver->resolve('chief-secondary@test.com', $this->scoutYearId));
    }

    public function testResolveIgnoresAPendingSecondaryEmail(): void
    {
        $this->createUserAccount('pending-desk@test.com');
        $this->createMemberWithFunction('pending-desk@test.com', 'Animateur', 'chief', true);
        $memberId = $this->memberIdForEmail('pending-desk@test.com');
        $this->addMemberEmail($memberId, 'pending-secondary@test.com', 'pending');

        $resolver = $this->resolverWithSecondaryEmailSupport();

        $this->assertSame('identified', $resolver->resolve('pending-secondary@test.com', $this->scoutYearId));
    }

    public function testResolveIgnoresAnInactiveSecondaryEmail(): void
    {
        $this->createUserAccount('inactive-desk@test.com');
        $this->createMemberWithFunction('inactive-desk@test.com', 'Animateur', 'chief', true);
        $memberId = $this->memberIdForEmail('inactive-desk@test.com');
        $this->addMemberEmail($memberId, 'inactive-secondary@test.com', 'inactive');

        $resolver = $this->resolverWithSecondaryEmailSupport();

        $this->assertSame('identified', $resolver->resolve('inactive-secondary@test.com', $this->scoutYearId));
    }

    public function testGetLinkedMemberYearsIncludesTheMemberViaAValidSecondaryEmail(): void
    {
        $this->createUserAccount('linked-desk@test.com');
        $this->createMemberWithFunction('linked-desk@test.com', 'Animé', 'identified', true);
        $memberId = $this->memberIdForEmail('linked-desk@test.com');
        $this->addMemberEmail($memberId, 'linked-secondary@test.com', 'valid');

        $resolver = $this->resolverWithSecondaryEmailSupport();

        $ids = $resolver->getLinkedMemberYears('linked-secondary@test.com', $this->scoutYearId);
        $this->assertCount(1, $ids);
    }

    public function testIsEmailAuthorizedToLoginViaAValidSecondaryEmail(): void
    {
        $this->createUserAccount('authorized-desk@test.com');
        $this->createMemberWithFunction('authorized-desk@test.com', 'Animé', 'identified', true);
        $memberId = $this->memberIdForEmail('authorized-desk@test.com');
        $this->addMemberEmail($memberId, 'authorized-secondary@test.com', 'valid');

        $resolver = $this->resolverWithSecondaryEmailSupport();

        $this->assertTrue($resolver->isEmailAuthorizedToLogin('authorized-secondary@test.com', $this->scoutYearId));
    }

    /**
     * Module addendum: unsubscribing an address revokes login too, even
     * for the Desk-imported address itself — the one exception to "the
     * Desk address is always usable, no exceptions" (see schema/core.sql).
     */
    public function testResolveDeniesLoginForAnUnsubscribedDeskAddress(): void
    {
        $this->createUserAccount('unsubscribed-desk@test.com');
        $this->createMemberWithFunction('unsubscribed-desk@test.com', 'Animateur', 'chief', true);
        $memberId = $this->memberIdForEmail('unsubscribed-desk@test.com');
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->encryption->encrypt('unsubscribed-desk@test.com'),
            $this->encryption->blindIndex('unsubscribed-desk@test.com'),
            'desk',
            'inactive',
        ]);

        $resolver = $this->resolverWithSecondaryEmailSupport();

        $this->assertSame('identified', $resolver->resolve('unsubscribed-desk@test.com', $this->scoutYearId));
        $this->assertFalse($resolver->isEmailAuthorizedToLogin('unsubscribed-desk@test.com', $this->scoutYearId));
        $this->assertSame([], $resolver->getLinkedMemberYears('unsubscribed-desk@test.com', $this->scoutYearId));
    }

    public function testIsEmailNotAuthorizedViaAPendingSecondaryEmail(): void
    {
        $this->createUserAccount('unauthorized-desk@test.com');
        $this->createMemberWithFunction('unauthorized-desk@test.com', 'Animé', 'identified', true);
        $memberId = $this->memberIdForEmail('unauthorized-desk@test.com');
        $this->addMemberEmail($memberId, 'unauthorized-secondary@test.com', 'pending');

        $resolver = $this->resolverWithSecondaryEmailSupport();

        $this->assertFalse($resolver->isEmailAuthorizedToLogin('unauthorized-secondary@test.com', $this->scoutYearId));
    }
}
