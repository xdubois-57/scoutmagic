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
use Tests\RequestWithInput;
use Core\Import\FunctionRepository;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Modules\MassMail\Controller\MailingListController;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Service\ListBlockService;
use Modules\MassMail\Service\MailingListService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;
use Twig\Environment;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * What the mailing lists page and its JSON endpoints actually answer.
 *
 * `MailingListRbacTest` drives the same routes through the real
 * Router/RbacGuard and asks only who gets past the guard; this one calls
 * the actions and asks what they do — which list was created, what a
 * refusal says, and what is in the database afterwards. The page moved
 * menu and floor without changing any of that, and that is precisely the
 * claim a move has to be able to make.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailingListControllerTest extends TestCase
{
    private \PDO $pdo;
    private MailingListController $controller;
    private MailingListService $listService;
    private int $accountId;
    private int $sectionId;
    private int $functionId;
    private int $badgeId;
    private ScoutYearResolver $scoutYearResolver;
    private ListBlockService $blockService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([
            $encryption->encrypt('cu@test.com', 'user_accounts.email'),
            $encryption->blindIndex('cu@test.com', 'email'),
        ]);
        $this->accountId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO sections (desk_code, age_branch_id, name, is_active) VALUES ('LOU01', {$branchId}, 'Meute', 1)"
        );
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('INT', 'Intendant', 'intendant')");
        $this->functionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO badges (name, is_default) VALUES ('Infirmier', 1)");
        $this->badgeId = (int) $this->pdo->lastInsertId();

        $sectionService = new SectionService(
    new SectionRepository(Connection::withPdo($this->pdo)),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo))
);
        $this->listService = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            $sectionService,
            new FunctionRepository($this->pdo),
            new BadgeService(new BadgeRepository($this->pdo), new MemberBadgeRepository($this->pdo), $sectionService),
            new ListBlockRepository($this->pdo),
            new ContactRepository($this->pdo, $encryption)
        );
        $this->blockService = new ListBlockService(
            new ListBlockRepository($this->pdo),
            new ContactRepository($this->pdo, $encryption),
            new MailingListRepository($this->pdo),
            $this->createStub(\Core\Journal\JournalService::class)
        );
        $this->scoutYearResolver = new ScoutYearResolver(
            new ScoutYearService($this->pdo),
            new SettingService(new SettingRepository($this->pdo)),
            new MemberYearRepository($this->pdo)
        );
        $this->controller = new MailingListController(
            $this->createStub(Environment::class),
            $this->listService,
            $this->scoutYearResolver,
            $this->blockService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login($this->accountId, 'cu@test.com', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * The page only summarises a list: what it is made of, counted per
     * kind of block for the whole page at once, and nothing decrypted.
     */
    public function testIndexHandsTheTemplateWhatEachListIsMadeOf(): void
    {
        $list = $this->list('Les intendants');
        $this->blockService->createMemberGroup($list->id, [], [], [$this->badgeId], null);
        $empty = $this->list('Vide');

        $twig = $this->createStub(Environment::class);
        $captured = null;
        $twig->method('render')->willReturnCallback(
            function (string $name, array $context) use (&$captured): string {
                $captured = $context;
                return '';
            }
        );

        $response = (new MailingListController(
            $twig,
            $this->listService,
            $this->scoutYearResolver,
            $this->blockService
        ))
            ->index(new Request('GET', '/admin/listes-de-diffusion', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsArray($captured);
        $this->assertCount(2, $captured['custom_lists']);
        $this->assertSame(
            [$list->id => ['member_group' => 1, 'contact_group' => 0]],
            $captured['block_counts']
        );
        $this->assertArrayNotHasKey($empty->id, $captured['block_counts']);
        $this->assertNotSame([], $captured['default_lists']);
        $this->assertNotSame('', $captured['csrf_token']);
    }

    /** The page renders those counts as a sentence, a list without blocks included. */
    public function testThePageSaysWhatEachListIsMadeOf(): void
    {
        $list = $this->list('Staff élargi');
        $this->blockService->createMemberGroup($list->id, [$this->functionId], [], [], null);
        $this->blockService->createMemberGroup($list->id, [], [], [$this->badgeId], null);
        $this->blockService->createMemberGroup($this->list('Secouristes')->id, [], [], [$this->badgeId], null);
        $this->list('Vide');

        $html = (new MailingListController(
            \Tests\TestTwig::create(['mass_mail']),
            $this->listService,
            $this->scoutYearResolver,
            $this->blockService
        ))->index(new Request('GET', '/admin/listes-de-diffusion', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('2 groupes de membres', $html);
        $this->assertStringContainsString('1 groupe de membres', $html);
        $this->assertStringContainsString('Aucun groupe : cette liste ne désigne personne.', $html);
        $this->assertStringNotContainsString('cfg-list-modal', $html);
    }

    /**
     * A new list is a name and a description; criteria sent along are
     * not a list's any more and create no block.
     */
    public function testCreateListStoresANameAndADescriptionAndNoBlock(): void
    {
        $payload = $this->call('createList', [], [
            'name' => 'Les intendants',
            'description' => 'Tous les intendants de la meute.',
            'function_ids' => [$this->functionId],
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('Les intendants', $payload['list']['name']);
        $this->assertTrue($payload['list']['is_active']);

        $listId = (int) $payload['list']['id'];
        $this->assertSame(
            'Tous les intendants de la meute.',
            $this->listService->getCustomListById($listId)?->description
        );
        $this->assertSame([], $this->blockService->findForList($listId));
    }

    /**
     * The refusal a chef d'unité reads has to be the service's own French
     * sentence, not a generic « Erreur » — and nothing may be written.
     */
    public function testCreateListRefusesAnEmptyDescriptionAndSaysWhy(): void
    {
        $response = $this->controller->createList(
            $this->jsonRequest([
                'name' => 'Sans description',
                'description' => '',
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            []
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['success']);
        $this->assertSame('La description de la liste est obligatoire.', $payload['error']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_lists')->fetchColumn());
    }

    public function testToggleListDeactivatesAndReactivates(): void
    {
        $list = $this->list('Bascule');

        $this->assertTrue($this->call('toggleList', ['id' => (string) $list->id], ['active' => false])['success']);
        $this->assertFalse($this->listService->getCustomListById($list->id)?->isActive);

        $this->assertTrue($this->call('toggleList', ['id' => (string) $list->id], ['active' => true])['success']);
        $this->assertTrue($this->listService->getCustomListById($list->id)?->isActive);
    }

    public function testDeleteListRemovesAListNoEmailUses(): void
    {
        $list = $this->list('Jamais utilisée');

        $this->assertTrue($this->call('deleteList', ['id' => (string) $list->id], [])['success']);
        $this->assertNull($this->listService->getCustomListById($list->id));
    }

    /**
     * The « deactivate instead » rule, same precedent as a badge already
     * assigned: a list an email references is never actually deletable,
     * and the refusal says what to do instead.
     */
    public function testDeleteListIsRefusedWhenAnEmailReferencesIt(): void
    {
        $list = $this->list('Utilisée');
        $this->pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, list_id, status)
             VALUES ('Sujet', '<p>Corps</p>', {$this->sectionId}, 'custom', {$list->id}, 'draft')"
        );

        $response = $this->controller->deleteList(
            $this->jsonRequest(['_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $list->id]
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertStringContainsString('désactivez-la', $payload['error']);
        $this->assertNotNull($this->listService->getCustomListById($list->id));
    }

    /** Removing a list takes its blocks with it. */
    public function testDeleteListTakesItsBlocksWithIt(): void
    {
        $list = $this->list('Avec un groupe');
        $this->blockService->createMemberGroup($list->id, [$this->functionId], [], [], null);

        $this->assertTrue($this->call('deleteList', ['id' => (string) $list->id], [])['success']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_list_blocks')->fetchColumn());
    }

    /**
     * Every one of the endpoints that WRITES reads its JSON body, and a
     * body that is not JSON at all is a refusal before anything else
     * happens — not a PHP notice on `$data['name']`.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('writingActionProvider')]
    public function testEveryWriteRefusesABodyThatIsNotJson(string $action): void
    {
        $response = $this->controller->{$action}($this->rawRequest('pas du json'), ['id' => '1']);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writingActionProvider')]
    public function testEveryWriteRefusesAnInvalidCsrfToken(string $action): void
    {
        $response = $this->controller->{$action}($this->jsonRequest(['_csrf_token' => 'invalide']), ['id' => '1']);

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writingActionProvider(): array
    {
        return [
            'createList' => ['createList'],
            'toggleList' => ['toggleList'],
            'deleteList' => ['deleteList'],
        ];
    }

    private function list(string $name): \Modules\MassMail\Repository\MailingList
    {
        return $this->listService->createCustomList($name, 'Description.', $this->accountId);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function call(string $action, array $params, array $body): array
    {
        $response = $this->controller->{$action}(
            $this->jsonRequest($body + ['_csrf_token' => CsrfGuard::generateToken()]),
            $params
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return $this->rawRequest((string) json_encode($body));
    }

    /**
     * `Request::getRawBody()` reads `php://input`, which a test cannot
     * write — stubbed here, the same way every other JSON-endpoint test in
     * this module does it.
     */
    private function rawRequest(string $body): Request
    {
        $request = new RequestWithInput('POST', '/admin/listes-de-diffusion/lists', [], [], [], [], $body);

        return $request;
    }
}
