<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\MassMail\Api\MassMailQueryInterface;
use Modules\MassMail\Controller\MemberEmailController;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Core\Member\Repository\MemberProfileRepository;

/**
 * Modules\MassMail\Controller\MemberEmailController — the member page's
 * "view as sent" detail page for one received email (member page §4,
 * ARCHITECTURE.md §8.22). Lives in this module (not core's
 * MemberController) since the content is entirely mass_mail's own data —
 * only ever registered/reachable when mass_mail is enabled (module.json).
 * RBAC boundary (chief/admin or the member themselves — role_min:identified
 * alone is not the real boundary) plus graceful 404 when the recipient
 * doesn't resolve to this member.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberEmailControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberService $memberService;
    private int $memberYearId;
    private int $memberId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->memberService = new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $this->pdo->lastInsertId();
        $this->scoutYearId = $scoutYearId;

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $emailBlindIndex = $this->encryption->blindIndex('member@test.example', 'email');
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->memberId, $scoutYearId,
            $this->encryption->encrypt('John', 'member_years.first_name'), $this->encryption->encrypt('Doe', 'member_years.last_name'),
            $this->encryption->encrypt('member@test.example', 'member_years.email'), $emailBlindIndex,
        ]);
        $this->memberYearId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function buildController(MassMailQueryInterface $massMailQuery): MemberEmailController
    {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = TwigFactory::create($templateDir, true);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'identified');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('_editable_content_service', null);

        return new MemberEmailController($twig, $this->memberService, $massMailQuery);
    }

    public function testDeniedToAStrangerLoggedInAsIdentified(): void
    {
        AuthSession::login(1, 'stranger@test.example', 'identified');
        $massMailQuery = $this->createMock(MassMailQueryInterface::class);
        $massMailQuery->expects($this->never())->method('findEmailDetailForMember');

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/1", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'target_id' => '1']
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAllowedForTheMemberThemselves(): void
    {
        AuthSession::login(1, 'member@test.example', 'identified');
        $massMailQuery = $this->createMock(MassMailQueryInterface::class);
        $massMailQuery->expects($this->once())->method('findEmailDetailForMember')
            ->with($this->memberId, 5)
            ->willReturn(['subject' => 'Sujet', 'body_html' => '<p>Corps</p>', 'sent_at' => '2026-01-01 10:00:00', 'section_name' => 'Meute A', 'merge_purged' => false, 'not_sent' => false, 'other_targets' => []]);

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/5", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'target_id' => '5']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Sujet', $response->getBody());
    }

    public function testAllowedForAChiefViewingAnyMember(): void
    {
        AuthSession::login(1, 'chief@test.example', 'chief');
        $massMailQuery = $this->createStub(MassMailQueryInterface::class);
        $massMailQuery->method('findEmailDetailForMember')
            ->willReturn(['subject' => 'Sujet', 'body_html' => '<p>Corps</p>', 'sent_at' => '2026-01-01 10:00:00', 'section_name' => 'Meute A', 'merge_purged' => false, 'not_sent' => false, 'other_targets' => []]);

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/5", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'target_id' => '5']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Issue #287, the one case the substitution cannot answer: a
     * publipostage whose audience has passed its 18-month retention has
     * no values left to put back, so the body still carries its
     * `{{tokens}}`. The page says so — a reader handed a template with no
     * explanation reads it as a fault of the site rather than as the
     * retention rule doing exactly its job.
     */
    public function testAPurgedPublipostageSaysWhyItStillShowsItsVariables(): void
    {
        AuthSession::login(1, 'member@test.example', 'identified');
        $massMailQuery = $this->createStub(MassMailQueryInterface::class);
        $massMailQuery->method('findEmailDetailForMember')->willReturn([
            'subject' => 'Camp de {{Prenom}}',
            'body_html' => '<p>Cher {{Prenom}}</p>',
            'sent_at' => '2024-01-01 10:00:00',
            'section_name' => 'Meute A',
            'merge_purged' => true,
            'not_sent' => false,
            'other_targets' => [],
        ]);

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/5", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'target_id' => '5']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('durée de conservation', $response->getBody());
    }

    /**
     * And it stays quiet the rest of the time: an ordinary e-mail carries
     * no such notice, or the notice would read as « this is an old
     * e-mail » on every message the site ever sent.
     */
    public function testAnOrdinaryEmailCarriesNoRetentionNotice(): void
    {
        AuthSession::login(1, 'member@test.example', 'identified');
        $massMailQuery = $this->createStub(MassMailQueryInterface::class);
        $massMailQuery->method('findEmailDetailForMember')->willReturn([
            'subject' => 'Sujet',
            'body_html' => '<p>Corps</p>',
            'sent_at' => '2026-01-01 10:00:00',
            'section_name' => 'Meute A',
            'merge_purged' => false,
            'not_sent' => false,
            'other_targets' => [],
        ]);

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/5", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'target_id' => '5']
        );

        $this->assertStringNotContainsString('durée de conservation', $response->getBody());
    }

    /**
     * An entry nothing of which left (issue #999, screen 2): the content is
     * still shown, under a sentence that says it could not be sent, and the
     * page never says « Envoyé le ».
     */
    public function testAnEntryNotSentSaysSoAboveItsContent(): void
    {
        AuthSession::login(1, 'member@test.example', 'identified');
        $massMailQuery = $this->createStub(MassMailQueryInterface::class);
        $massMailQuery->method('findEmailDetailForMember')->willReturn([
            'subject' => 'Sujet',
            'body_html' => '<p>Corps</p>',
            'sent_at' => '2026-01-01 10:00:00',
            'section_name' => 'Meute A',
            'merge_purged' => false,
            'not_sent' => true,
            'other_targets' => [],
        ]);

        $body = $this->buildController($massMailQuery)->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/5", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'target_id' => '5']
        )->getBody();

        $this->assertStringContainsString("Cet e-mail n'a pas pu être envoyé aux adresses de cet animé.", $body);
        $this->assertStringContainsString('<p>Corps</p>', $body);
        $this->assertStringContainsString('Non envoyée', $body);
        $this->assertStringNotContainsString('Envoyé le', $body);
    }

    /**
     * « Concerne aussi » (issue #999, §2.5): a parent who may open Bob's page
     * (his Desk address is theirs) sees him named, with a link to HIS entry
     * through his own member_years row; Zelda, whom they may not see, is never
     * named, though the same communication was meant for her.
     */
    public function testConcerneAussiNamesOnlyTheMembersTheReaderCanAlreadySee(): void
    {
        $bob = $this->createMember('Bob', 'Dupont', 'member@test.example');
        $eve = $this->createMember('Zelda', 'Martin', 'zelda@test.example');

        AuthSession::login(1, 'member@test.example', 'identified');
        $massMailQuery = $this->createStub(MassMailQueryInterface::class);
        $massMailQuery->method('findEmailDetailForMember')->willReturn($this->detail(['other_targets' => [
            ['target_id' => 41, 'member_id' => $bob['member_id'], 'scout_year_id' => $this->scoutYearId],
            ['target_id' => 42, 'member_id' => $eve['member_id'], 'scout_year_id' => $this->scoutYearId],
        ]]));

        $body = $this->show($massMailQuery, 5)->getBody();

        $this->assertStringContainsString('Cette communication concerne aussi', $body);
        $this->assertStringContainsString('href="/members/' . $bob['member_year_id'] . '/emails/41"', $body);
        $this->assertStringContainsString('Bob', $body);
        $this->assertStringNotContainsString('Zelda', $body);
        $this->assertStringNotContainsString('/emails/42', $body);
    }

    /** Nobody accessible beside: no sentence at all. */
    public function testConcerneAussiIsAbsentWhenNoOtherTargetIsVisible(): void
    {
        $eve = $this->createMember('Zelda', 'Martin', 'zelda@test.example');

        AuthSession::login(1, 'member@test.example', 'identified');
        $massMailQuery = $this->createStub(MassMailQueryInterface::class);
        $massMailQuery->method('findEmailDetailForMember')->willReturn($this->detail(['other_targets' => [
            ['target_id' => 42, 'member_id' => $eve['member_id'], 'scout_year_id' => $this->scoutYearId],
        ]]));

        $this->assertStringNotContainsString('concerne aussi', $this->show($massMailQuery, 5)->getBody());
    }

    /**
     * A target with no member_years row for its year has no entry to link
     * to: a chief, who may open every page, reads the name without a link;
     * an identified reader, for whom there is no page to check, reads
     * nothing.
     */
    public function testATargetWithoutAMemberYearIsNamedWithoutALinkOnlyToWhomMaySeeEveryone(): void
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0)");
        $oldYear = (int) $this->pdo->lastInsertId();
        $carl = $this->createMember('Carl', 'Leroy', 'carl@test.example');
        $others = ['other_targets' => [
            ['target_id' => 43, 'member_id' => $carl['member_id'], 'scout_year_id' => $oldYear],
        ]];

        AuthSession::login(1, 'chief@test.example', 'chief');
        $massMailQuery = $this->createStub(MassMailQueryInterface::class);
        $massMailQuery->method('findEmailDetailForMember')->willReturn($this->detail($others));
        $body = $this->show($massMailQuery, 5)->getBody();
        $this->assertStringContainsString('Cette communication concerne aussi', $body);
        $this->assertStringContainsString('Carl', $body);
        $this->assertStringNotContainsString('/emails/43', $body);

        AuthSession::login(1, 'carl@test.example', 'identified');
        $this->assertStringNotContainsString('Carl', $this->show($massMailQuery, 5)->getBody());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function detail(array $overrides = []): array
    {
        return $overrides + [
            'subject' => 'Sujet',
            'body_html' => '<p>Corps</p>',
            'sent_at' => '2026-01-01 10:00:00',
            'section_name' => 'Meute A',
            'merge_purged' => false,
            'not_sent' => false,
            'other_targets' => [],
        ];
    }

    private function show(MassMailQueryInterface $massMailQuery, int $targetId): \Core\Http\Response
    {
        return $this->buildController($massMailQuery)->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/{$targetId}", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'target_id' => (string) $targetId]
        );
    }

    /**
     * @return array{member_id: int, member_year_id: int}
     */
    private function createMember(string $firstName, string $lastName, string $email): array
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['DESK_' . $firstName]);
        $memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);

        return ['member_id' => $memberId, 'member_year_id' => (int) $this->pdo->lastInsertId()];
    }

    public function testReturns404WhenRecipientDoesNotBelongToThisMember(): void
    {
        AuthSession::login(1, 'member@test.example', 'identified');
        $massMailQuery = $this->createStub(MassMailQueryInterface::class);
        $massMailQuery->method('findEmailDetailForMember')->willReturn(null);

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/999", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'target_id' => '999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturns404ForANonExistentMemberYear(): void
    {
        AuthSession::login(1, 'stranger@test.example', 'chief');
        $massMailQuery = $this->createStub(MassMailQueryInterface::class);

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', '/members/999999/emails/1', [], [], [], []),
            ['id' => '999999', 'target_id' => '1']
        );

        $this->assertSame(404, $response->getStatusCode());
    }
}
