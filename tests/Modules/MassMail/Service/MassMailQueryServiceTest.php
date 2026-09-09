<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Security\EncryptionService;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\Recipient;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Service\MassMailQueryService;
use Modules\MassMail\Service\MergeRenderer;
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
#[\PHPUnit\Framework\Attributes\Group('database')]
class MassMailQueryServiceTest extends TestCase
{
    public function testReturnsOnlySentEmailsForTheGivenMember(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $recipientRepository = new RecipientRepository($pdo, $encryption);
        $queryService = new MassMailQueryService(
            $recipientRepository,
            new AudienceRepository($pdo, $encryption),
            new MergeRenderer()
        );

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

        $detail = $queryService->findEmailDetailForMember($memberId, $result[0]['id']);
        $this->assertNotNull($detail);
        $this->assertSame('Sujet envoyé', $detail['subject']);
        $this->assertSame('<p>x</p>', $detail['body_html']);

        $this->assertNull($queryService->findEmailDetailForMember($memberId + 999, $result[0]['id']));
    }

    /**
     * Issue #287. A publipostage is stored once as a template and
     * substituted per recipient in local variables just before the send,
     * so everything that read the e-mail back afterwards showed
     * `{{Prenom}}` to somebody whose mailbox holds their own first name.
     * The list and the detail page both re-render from the recipient's
     * OWN audience row.
     */
    public function testAPublipostageIsReadBackWithThisMembersOwnValues(): void
    {
        $fixture = $this->mergeFixture();

        $result = $fixture['service']->getRecentEmailsForMember($fixture['member_id'], 10);
        $this->assertCount(1, $result);
        $this->assertSame('Camp de Kaa', $result[0]['subject']);

        $detail = $fixture['service']->findEmailDetailForMember($fixture['member_id'], $result[0]['id']);
        $this->assertNotNull($detail);
        $this->assertSame('Camp de Kaa', $detail['subject']);
        $this->assertSame('<p>Cher Kaa, tu paieras 145 €.</p>', $detail['body_html']);
        $this->assertFalse($detail['merge_purged']);
    }

    /**
     * The other member of the same mailing gets THEIR row, not the first
     * one — which is the whole failure mode a single stored copy would
     * reintroduce.
     */
    public function testTwoRecipientsOfOneMailingReadTwoDifferentEmails(): void
    {
        $fixture = $this->mergeFixture();

        $first = $fixture['service']->getRecentEmailsForMember($fixture['member_id'], 10);
        $second = $fixture['service']->getRecentEmailsForMember($fixture['other_member_id'], 10);

        $this->assertSame('Camp de Kaa', $first[0]['subject']);
        $this->assertSame('Camp de Emma', $second[0]['subject']);

        $secondDetail = $fixture['service']->findEmailDetailForMember(
            $fixture['other_member_id'],
            $second[0]['id']
        );
        $this->assertNotNull($secondDetail);
        $this->assertSame('<p>Cher Emma, tu paieras 80 €.</p>', $secondDetail['body_html']);
    }

    /**
     * Retention (Task\PurgeMergeAudiencesHandler) erases a merge audience
     * 18 months after the last send, and reading through the audience row
     * is what makes the personalisation disappear with it rather than
     * survive in a frozen copy. What is left cannot be rendered — so the
     * caller is TOLD, instead of being handed a template that reads as a
     * bug.
     */
    public function testAPurgedAudienceSaysSoRatherThanPassingOffTheTemplate(): void
    {
        $fixture = $this->mergeFixture();
        $recipientId = $fixture['service']->getRecentEmailsForMember($fixture['member_id'], 10)[0]['id'];

        $fixture['pdo']->exec('DELETE FROM mass_mail_audience_rows');

        $detail = $fixture['service']->findEmailDetailForMember($fixture['member_id'], $recipientId);
        $this->assertNotNull($detail);
        $this->assertTrue($detail['merge_purged']);
        $this->assertStringContainsString('{{Prenom}}', $detail['body_html']);
    }

    /**
     * An ordinary list has nothing to substitute, so it has nothing to
     * have lost either — `merge_purged` must not turn into "this is an
     * old e-mail".
     */
    public function testAnOrdinaryListIsNeverReportedAsPurged(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $recipientRepository = new RecipientRepository($pdo, $encryption);
        $service = new MassMailQueryService(
            $recipientRepository,
            new AudienceRepository($pdo, $encryption),
            new MergeRenderer()
        );

        [$sectionId, $scoutYearId] = $this->seedSectionAndYear($pdo);
        $pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES ('Réunion', '<p>Bonjour.</p>', {$sectionId}, 'default_active_members', 'sent')"
        );
        $emailId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_ORD')");
        $memberId = (int) $pdo->lastInsertId();
        $recipientId = $recipientRepository->create(
            $emailId,
            $memberId,
            $scoutYearId,
            'a@test.be',
            Recipient::STATUS_SENT,
            null
        );

        $detail = $service->findEmailDetailForMember($memberId, $recipientId);
        $this->assertNotNull($detail);
        $this->assertFalse($detail['merge_purged']);
        $this->assertSame('<p>Bonjour.</p>', $detail['body_html']);
    }

    /**
     * One publipostage, two members, one row each.
     *
     * @return array{pdo: \PDO, service: MassMailQueryService, member_id: int, other_member_id: int}
     */
    private function mergeFixture(): array
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $recipientRepository = new RecipientRepository($pdo, $encryption);
        $audienceRepository = new AudienceRepository($pdo, $encryption);
        $service = new MassMailQueryService($recipientRepository, $audienceRepository, new MergeRenderer());

        [$sectionId, $scoutYearId] = $this->seedSectionAndYear($pdo);

        $audienceId = $audienceRepository->createAudience('camp.xlsx', 'Camp', ['Prenom', 'Montant'], 2, null);
        $pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_KAA')");
        $memberId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_EMMA')");
        $otherMemberId = (int) $pdo->lastInsertId();

        $kaaRow = $audienceRepository->createRow($audienceId, 2, $memberId, null,
            ['Prenom' => 'Kaa', 'Montant' => '145']);
        $emmaRow = $audienceRepository->createRow($audienceId, 3, $otherMemberId, null,
            ['Prenom' => 'Emma', 'Montant' => '80']);

        $pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, audience_id, status)
             VALUES ('Camp de {{Prenom}}', '<p>Cher {{Prenom}}, tu paieras {{Montant}} €.</p>',
                     {$sectionId}, 'mail_merge', {$audienceId}, 'sent')"
        );
        $emailId = (int) $pdo->lastInsertId();

        $recipientRepository->create($emailId, $memberId, $scoutYearId, 'kaa@test.be', Recipient::STATUS_SENT,
            null, null, $kaaRow);
        $recipientRepository->create($emailId, $otherMemberId, $scoutYearId, 'emma@test.be', Recipient::STATUS_SENT,
            null, null, $emmaRow);

        return [
            'pdo' => $pdo,
            'service' => $service,
            'member_id' => $memberId,
            'other_member_id' => $otherMemberId,
        ];
    }

    /**
     * @return array{0: int, 1: int} [section id, scout year id]
     */
    private function seedSectionAndYear(\PDO $pdo): array
    {
        $pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");

        return [(int) $pdo->lastInsertId(), $scoutYearId];
    }
}
