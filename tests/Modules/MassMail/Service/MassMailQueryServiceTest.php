<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Security\EncryptionService;
use Modules\MassMail\Repository\Recipient;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Service\MassMailQueryService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * Modules\MassMail\Api\MassMailQueryInterface's concrete implementation —
 * the only entry point core's MemberController uses (ARCHITECTURE.md
 * §7.5). Graceful degradation when the module is disabled is verified at
 * Core\Http\Controller\MemberController level (see
 * tests/Core/Http/Controller/MemberControllerMassMailTest.php); this test
 * covers the implementation itself.
 *
 * @group database
 */
class MassMailQueryServiceTest extends TestCase
{
    public function testReturnsOnlySentEmailsForTheGivenMember(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $recipientRepository = new RecipientRepository($pdo, $encryption);
        $queryService = new MassMailQueryService($recipientRepository);

        $pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $sectionId = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES ('Sujet envoyé', '<p>x</p>', {$sectionId}, 'default_active_members', 'sent')"
        );
        $emailId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_1')");
        $memberId = (int) $pdo->lastInsertId();

        $recipientRepository->create($emailId, $memberId, $scoutYearId, 'a@test.be', Recipient::STATUS_SENT, null);

        $result = $queryService->getRecentEmailsForMember($memberId, 10);

        $this->assertCount(1, $result);
        $this->assertSame('Sujet envoyé', $result[0]['subject']);
        $this->assertSame('Meute A', $result[0]['section_name']);
    }
}
