<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Import\FunctionRepository;
use Core\Import\ImportJournalRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberEmailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Core\File\FileRepository;
use Modules\MassMail\Repository\AudienceRepository;
use Core\Http\Request;
use Core\Http\Router;
use Core\Module\ModuleManifest;
use Modules\MassMail\Controller\MassMailController;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\MassMailAccessService;
use Modules\MassMail\Api\MassMailException;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\MergeDraftService;
use Modules\MassMail\Service\MergeRenderer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;

/**
 * Turning somebody else's rows into a mail-merge draft.
 *
 * What matters here is not that a draft appears — MassMailService is
 * already tested for that — but the four decisions this service makes on
 * the way: it deduplicates by address, it never sets member_id, it stores
 * an audience indistinguishable from an imported one (so the existing
 * retention and composer keep working), and it lets mass_mail's own
 * authorization refuse rather than deciding anything itself.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MergeDraftServiceTest extends TestCase
{
    private \PDO $pdo;
    private MergeDraftService $service;
    private AudienceRepository $audienceRepository;
    private EmailRepository $emailRepository;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $memberService = new MemberService(new MemberYearRepository($this->pdo), $encryption, $connection);
        $scoutYearService = new ScoutYearService($this->pdo);

        $this->audienceRepository = new AudienceRepository($this->pdo, $encryption);
        $this->emailRepository = new EmailRepository($this->pdo);

        $massMailService = new MassMailService(
            $this->emailRepository,
            new RecipientRepository($this->pdo, $encryption),
            new EmailAttachmentRepository($this->pdo),
            new FileRepository($this->pdo),
            new MailingListService(
                new MailingListRepository($this->pdo),
                new MemberResolutionRepository($this->pdo, $encryption),
                $sectionService,
                new FunctionRepository($this->pdo)
            ),
            $memberService,
            new MemberEmailService(
                new MemberEmailRepository($this->pdo, $encryption),
                $this->createMock(MailService::class),
                EmailTemplateRendererFactory::overTestDatabase($this->pdo, $this->createMock(\Twig\Environment::class)),
                new JournalService(new JournalRepository($this->pdo)),
                $sectionService,
                $memberService,
                $scoutYearService,
                'https://example.test',
                'Test Unité'
            ),
            $sectionService,
            $this->createMock(MailService::class),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new HtmlSanitizer(),
            $scoutYearService,
            new ImportJournalRepository($this->pdo),
            sys_get_temp_dir(),
            $this->audienceRepository,
            new MemberResolutionRepository($this->pdo, $encryption),
            new SuppressedAddressRepository($this->pdo),
            new MergeRenderer()
        );

        $this->service = new MergeDraftService(
            $massMailService,
            $this->audienceRepository,
            new MassMailAccessService($memberService, $sectionService),
            $memberService,
            $sectionService,
            $scoutYearService
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    public function testItReturnsTheComposerUrlOfARealMailMergeDraft(): void
    {
        $url = $this->createDraft([
            ['email' => 'a@test.be', 'values' => ['Contact' => 'a@test.be', 'Taille' => 'M']],
        ]);

        $this->assertMatchesRegularExpression('#^/mass-mail/\d+$#', $url);

        $email = $this->emailRepository->findById((int) substr($url, strrpos($url, '/') + 1));
        $this->assertNotNull($email);
        $this->assertSame(Email::LIST_TYPE_MAIL_MERGE, $email->listType);
        $this->assertNotNull($email->audienceId);
        // A draft is a draft: nothing is sent, and the body is left empty
        // for the person to write.
        $this->assertSame('', $email->bodyHtml);
    }

    /**
     * The contract says « the draft's edit URL », and for a long time it
     * lied: `/mass-mail/{id}` was a JSON entry point, so every caller —
     * news's « Écrire aux répondants » and finance's campaign reminder
     * alike — redirected a chief to a raw payload.
     *
     * Asserted against mass_mail's REAL manifest rather than against a
     * written-down path: what makes the URL a page is that the module
     * declares a GET route for it that renders one (a breadcrumb is what
     * `Tests\Core\View\UxConventionsTest` uses as the marker, for the
     * same reason). Checked here, once, on the single implementation both
     * callers go through — neither of them can be repaired without the
     * other.
     */
    public function testTheUrlItReturnsIsAPageAndNotAJsonEndpoint(): void
    {
        $url = $this->createDraft([
            ['email' => 'a@test.be', 'values' => ['Contact' => 'a@test.be', 'Taille' => 'M']],
        ]);

        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/mass_mail/module.json');
        $router = new Router();
        foreach ($manifest->routes as $route) {
            $router->addRoute($route['method'], $route['path'], $route['controller'], $route['action'], $route['role_min'], $route['breadcrumb'] ?? null);
        }

        $resolved = $router->resolve(new Request('GET', $url, [], [], [], []));

        $this->assertNotNull($resolved, "the URL handed to both callers resolves to no route at all: {$url}");
        $this->assertSame('show', $resolved->action);
        $this->assertSame(MassMailController::class, $resolved->controllerClass);
        $this->assertNotNull($resolved->breadcrumb, 'a JSON endpoint declares no breadcrumb; a page does');
    }

    public function testTheAudienceCarriesTheColumnsAndTheAnswers(): void
    {
        $url = $this->createDraft([
            ['email' => 'a@test.be', 'values' => ['Contact' => 'a@test.be', 'Taille' => 'M']],
            ['email' => 'b@test.be', 'values' => ['Contact' => 'b@test.be', 'Taille' => 'L']],
        ]);

        $audience = $this->audienceRepository->findById($this->audienceIdOf($url));
        $this->assertNotNull($audience);
        $this->assertSame(['Contact', 'Taille'], $audience->columns);
        $this->assertSame(2, $audience->rowCount);

        $rows = $this->audienceRepository->findRowsByAudience($audience->id);
        $this->assertSame('M', $rows[0]->data['Taille']);
        $this->assertSame('L', $rows[1]->data['Taille']);
    }

    public function testTheAddressIsTheAnswersOwnAndNeverAMember(): void
    {
        $url = $this->createDraft([
            ['email' => 'a@test.be', 'values' => ['Contact' => 'a@test.be']],
        ]);

        $rows = $this->audienceRepository->findRowsByAudience($this->audienceIdOf($url));

        // member_id stays null on purpose: resolving it would send to every
        // address that member ever registered, and the person answered with
        // one precise address.
        $this->assertNull($rows[0]->memberId);
        $this->assertSame('a@test.be', $rows[0]->email);
    }

    public function testTwoAnswersFromOneAddressAreOneRecipient(): void
    {
        $url = $this->createDraft([
            ['email' => 'a@test.be', 'values' => ['Contact' => 'a@test.be', 'Taille' => 'M']],
            ['email' => ' A@Test.be ', 'values' => ['Contact' => 'A@Test.be', 'Taille' => 'L']],
            ['email' => 'b@test.be', 'values' => ['Contact' => 'b@test.be', 'Taille' => 'S']],
        ]);

        $rows = $this->audienceRepository->findRowsByAudience($this->audienceIdOf($url));

        $this->assertCount(2, $rows);
        // Case and surrounding space do not make a second person, and the
        // FIRST answer is the one whose values are kept.
        $this->assertSame('M', $rows[0]->data['Taille']);
        $this->assertSame(2, $this->audienceRepository->findById($this->audienceIdOf($url))?->rowCount);
    }

    public function testAnEmptyAddressIsDroppedRatherThanMailed(): void
    {
        $url = $this->createDraft([
            ['email' => '', 'values' => ['Contact' => '']],
            ['email' => 'b@test.be', 'values' => ['Contact' => 'b@test.be']],
        ]);

        $this->assertCount(1, $this->audienceRepository->findRowsByAudience($this->audienceIdOf($url)));
    }

    public function testNobodyToWriteToIsRefusedRatherThanLeavingAnEmptyDraft(): void
    {
        // An empty audience would reach the composer and fail there, after
        // having already written rows nothing will ever purge on time.
        $this->expectException(MassMailException::class);
        $this->createDraft([['email' => '   ', 'values' => []]]);
    }

    public function testAChiefWhoStaffsNoSectionIsRefusedBySendingRules(): void
    {
        // The actor resolves to no member, so mass_mail's own
        // assertSenderSectionAllowed() refuses: this service asserts
        // nothing itself, which is the point — one place decides.
        $this->expectException(MassMailException::class);
        $this->service->createMergeDraft(
            'Réponses',
            'Sujet',
            ['Contact'],
            [['email' => 'a@test.be', 'values' => ['Contact' => 'a@test.be']]],
            'chief',
            'stranger@test.be',
            null
        );
    }

    public function testNoDraftSurvivesARefusal(): void
    {
        try {
            $this->service->createMergeDraft(
                'Réponses',
                'Sujet',
                ['Contact'],
                [['email' => 'a@test.be', 'values' => ['Contact' => 'a@test.be']]],
                'chief',
                'stranger@test.be',
                null
            );
            $this->fail('expected the send rules to refuse');
        } catch (MassMailException) {
            // The audience row is written before createDraft() runs, so a
            // refusal must not leave a draft behind claiming to reference
            // one. An orphan audience is expected and is what
            // Task\PurgeMergeAudiencesHandler collects after 7 days.
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_emails')->fetchColumn());
        }
    }

    public function testAnInstallationWithNoSectionAtAllIsRefusedClearly(): void
    {
        // Before the first Desk import there is no section to send from,
        // and createDraft() would otherwise be handed a section id of 0
        // and refuse with a message about somebody else's section.
        $this->pdo->exec('DELETE FROM sections');

        $this->expectException(MassMailException::class);
        $this->expectExceptionMessage("Aucune section n'est configurée");
        $this->createDraft([['email' => 'a@test.be', 'values' => ['Contact' => 'a@test.be']]]);
    }

    // --- fixtures ---

    /**
     * @param list<array{email: string, values: array<string, string>}> $rows
     */
    private function createDraft(array $rows): string
    {
        return $this->service->createMergeDraft(
            'Réponses — Camp 2026',
            'Réponses — Camp 2026',
            ['Contact', 'Taille'],
            $rows,
            // admin: unrestricted sender, so these cases exercise the
            // audience and not the authorization.
            'admin',
            'chef@test.be',
            null
        );
    }

    private function audienceIdOf(string $url): int
    {
        $email = $this->emailRepository->findById((int) substr($url, strrpos($url, '/') + 1));
        \assert($email !== null && $email->audienceId !== null);

        return $email->audienceId;
    }
}
