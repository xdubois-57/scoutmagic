<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Import\FunctionRepository;
use Core\Import\MemberYearRepository;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class RoleChangeEffectTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RoleResolver $resolver;
    private FunctionRepository $functionRepo;
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
        $this->functionRepo = new FunctionRepository($this->pdo);

        // Create scout year
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    public function testChangingFunctionRoleAffectsNextRoleResolution(): void
    {
        $email = 'member@test.com';
        $normalizedEmail = strtolower($email);
        $blindIndex = $this->encryption->blindIndex($normalizedEmail);

        // Create user account
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, 0)'
        );
        $stmt->execute([$this->encryption->encrypt($normalizedEmail), $blindIndex]);

        // Create function with role=identified
        $functionId = $this->functionRepo->create('Scout', 'Scout', 'identified', true);

        // Create member
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('desk_test_001')");
        $memberId = (int) $this->pdo->lastInsertId();

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

        // Verify initial role is identified
        $role = $this->resolver->resolve($email, $this->scoutYearId);
        $this->assertSame('identified', $role);

        // Change function role to chief
        $this->functionRepo->updateRole($functionId, 'chief', true);

        // Resolve role again (simulating next login) — should now be chief
        $role = $this->resolver->resolve($email, $this->scoutYearId);
        $this->assertSame('chief', $role);
    }

    public function testChangingFunctionToAdminAffectsResolution(): void
    {
        $email = 'leader@test.com';
        $normalizedEmail = strtolower($email);
        $blindIndex = $this->encryption->blindIndex($normalizedEmail);

        // Create user account (NOT super admin)
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, 0)'
        );
        $stmt->execute([$this->encryption->encrypt($normalizedEmail), $blindIndex]);

        // Create function with role=intendant
        $functionId = $this->functionRepo->create('Intendant', 'Intendant', 'intendant', true);

        // Create member + member_year + member_function
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('desk_test_002')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt('Lead'),
            $this->encryption->encrypt('User'),
            $this->encryption->encrypt($normalizedEmail),
            $blindIndex,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, is_main_function) VALUES (?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId]);

        // Verify initial role
        $this->assertSame('intendant', $this->resolver->resolve($email, $this->scoutYearId));

        // Upgrade to admin
        $this->functionRepo->updateRole($functionId, 'admin', true);

        // Role should now be admin
        $this->assertSame('admin', $this->resolver->resolve($email, $this->scoutYearId));
    }

    public function testDowngradingFunctionRoleAffectsResolution(): void
    {
        $email = 'downgrade@test.com';
        $normalizedEmail = strtolower($email);
        $blindIndex = $this->encryption->blindIndex($normalizedEmail);

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, 0)'
        );
        $stmt->execute([$this->encryption->encrypt($normalizedEmail), $blindIndex]);

        $functionId = $this->functionRepo->create('Animateur', 'Animateur', 'chief', true);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('desk_test_003')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt('Down'),
            $this->encryption->encrypt('Grade'),
            $this->encryption->encrypt($normalizedEmail),
            $blindIndex,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, is_main_function) VALUES (?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId]);

        // Verify initial role is chief
        $this->assertSame('chief', $this->resolver->resolve($email, $this->scoutYearId));

        // Downgrade to identified
        $this->functionRepo->updateRole($functionId, 'identified', true);

        // Role should now be identified
        $this->assertSame('identified', $this->resolver->resolve($email, $this->scoutYearId));
    }
}
