<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\FunctionRepository;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Modules\MassMail\Controller\MailingListController;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Service\MailingListService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;
use Twig\Environment;

/**
 * The live counter on the criteria form: `POST /admin/listes-de-diffusion/
 * preview-count`.
 *
 * It exists because the AND between the three axes is invisible in the
 * controls — three pickers look like three independent filters — and the
 * one crossing that always yields nothing is the one a unit reaches for
 * first: a badge is assignable to the Staff d'U and to the chef/chef
 * d'unité functions, so crossing it with a section of animés is zero every
 * time. The counter is what says so before the list is saved and used.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailingListPreviewCountTest extends TestCase
{
    private \PDO $pdo;
    private MailingListController $controller;
    private EncryptionService $encryption;
    private int $scoutYearId;
    private int $sectionAnimesId;
    private int $sectionStaffId;
    private int $functionChiefId;
    private int $badgeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)
             VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)"
        );
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        // The effective year is what a send would resolve against, so the
        // count has to be taken there too — pinned here rather than left
        // to the calendar, which would otherwise auto-create whichever
        // year the test happened to run in.
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            ScoutYearResolver::SETTING_PUBLIC_YEAR,
            (string) $this->scoutYearId,
            'number',
            'Année scoute courante',
            'Année scoute publique.',
        ]);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO sections (desk_code, age_branch_id, name, is_active) VALUES ('LOU01', {$branchId}, 'Meute', 1)"
        );
        $this->sectionAnimesId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO sections (desk_code, age_branch_id, name, is_active) VALUES ('STAFF', {$branchId}, 'Staff', 1)"
        );
        $this->sectionStaffId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('CHEF', 'Chef', 'chief')");
        $this->functionChiefId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO badges (name, is_default) VALUES ('Infirmier', 1)");
        $this->badgeId = (int) $this->pdo->lastInsertId();

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService(
            $connection,
            $this->encryption,
            new MemberBadgeRepository($this->pdo)
        );

        $this->controller = new MailingListController(
            $this->createMock(Environment::class),
            new MailingListService(
                new MailingListRepository($this->pdo),
                new MemberResolutionRepository($this->pdo, $this->encryption),
                $sectionService,
                new FunctionRepository($this->pdo),
                new BadgeService(
                    new BadgeRepository($this->pdo),
                    new MemberBadgeRepository($this->pdo),
                    $sectionService
                )
            ),
            new ScoutYearResolver(
                new ScoutYearService($this->pdo),
                new SettingService(new SettingRepository($this->pdo)),
                new MemberYearRepository($this->pdo)
            )
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function testItCountsWhatTheCriteriaResolveToAndNamesTheYear(): void
    {
        $this->createChief('chief@test.be', $this->sectionStaffId, withBadge: true);
        $this->createChief('other@test.be', $this->sectionStaffId, withBadge: false);

        $payload = $this->preview(['function_ids' => [$this->functionChiefId]]);

        $this->assertTrue($payload['success']);
        $this->assertSame(2, $payload['count']);
        $this->assertSame('2025-2026', $payload['scout_year_label']);
    }

    /**
     * The trap the counter exists for: a badge crossed with a section of
     * animés is zero, every time, and nothing on the form says so.
     */
    public function testCrossingABadgeWithASectionThatCannotHoldOneCountsZero(): void
    {
        $this->createChief('chief@test.be', $this->sectionStaffId, withBadge: true);

        $payload = $this->preview([
            'badge_ids' => [$this->badgeId],
            'section_ids' => [$this->sectionAnimesId],
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame(0, $payload['count']);
    }

    public function testNoCriterionOnAnyAxisCountsNobodyRatherThanEverybody(): void
    {
        $this->createChief('chief@test.be', $this->sectionStaffId, withBadge: true);

        $this->assertSame(0, $this->preview([])['count']);
    }

    public function testAnInvalidCsrfTokenIsRefusedBeforeAnythingIsCounted(): void
    {
        $response = $this->controller->previewCount(
            $this->jsonRequest(['function_ids' => [$this->functionChiefId], '_csrf_token' => 'invalid']),
            []
        );

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['success']);
        $this->assertArrayNotHasKey('count', $payload);
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $response = $this->controller->previewCount($this->rawRequest('pas du json'), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function preview(array $criteria): array
    {
        $response = $this->controller->previewCount(
            $this->jsonRequest($criteria + ['_csrf_token' => CsrfGuard::generateToken()]),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        return $payload;
    }

    private function createChief(string $email, int $sectionId, bool $withBadge): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years
                (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted,
                 email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt('Jean', 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $this->functionChiefId, $sectionId]);

        if ($withBadge) {
            $stmt = $this->pdo->prepare('INSERT INTO member_badges (member_year_id, badge_id) VALUES (?, ?)');
            $stmt->execute([$memberYearId, $this->badgeId]);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return $this->rawRequest((string) json_encode($body));
    }

    /**
     * Request::getRawBody() reads `php://input`, which a test cannot
     * write — the body is therefore stubbed, the same way every other
     * JSON-endpoint test in this module does it.
     */
    private function rawRequest(string $body): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/admin/listes-de-diffusion/preview-count', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn($body);

        return $request;
    }
}
