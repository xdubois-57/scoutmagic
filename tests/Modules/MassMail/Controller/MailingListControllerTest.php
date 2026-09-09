<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\FunctionRepository;
use Core\Member\SectionService;
use Core\Security\AuthSession;
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
 * What the mailing lists page's four JSON endpoints actually answer.
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

        $this->listService = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            new SectionService(Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo)),
            new FunctionRepository($this->pdo)
        );

        $this->controller = new MailingListController($this->createMock(Environment::class), $this->listService);

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
     * The page hands the template every list's criteria, keyed by list id
     * — that map is what pre-fills the edit dialog, and it was silently
     * empty for a while because the reduce that builds it never ran on a
     * unit with no list at all.
     */
    public function testIndexHandsTheTemplateEachListsCriteria(): void
    {
        $list = $this->listService->createCustomList(
            'Les intendants',
            'Description.',
            [$this->functionId],
            [$this->sectionId],
            $this->accountId
        );

        $twig = $this->createMock(Environment::class);
        $captured = null;
        $twig->method('render')->willReturnCallback(
            function (string $name, array $context) use (&$captured): string {
                $captured = $context;
                return '';
            }
        );

        $response = (new MailingListController($twig, $this->listService))
            ->index(new Request('GET', '/admin/listes-de-diffusion', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsArray($captured);
        $this->assertCount(1, $captured['custom_lists']);
        $this->assertSame(
            ['function_ids' => [$this->functionId], 'section_ids' => [$this->sectionId]],
            $captured['custom_list_criteria'][$list->id]
        );
        $this->assertNotSame([], $captured['default_lists']);
        $this->assertNotSame('', $captured['csrf_token']);
    }

    public function testCreateListStoresTheListAndItsCriteriaAndAnswersWithIt(): void
    {
        $payload = $this->call('createList', [], [
            'name' => 'Les intendants',
            'description' => 'Tous les intendants de la meute.',
            'function_ids' => [$this->functionId],
            'section_ids' => [$this->sectionId],
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('Les intendants', $payload['list']['name']);
        $this->assertTrue($payload['list']['is_active']);

        $listId = (int) $payload['list']['id'];
        $this->assertSame([$this->functionId], $this->listService->getCustomListFunctionIds($listId));
        $this->assertSame([$this->sectionId], $this->listService->getCustomListSectionIds($listId));
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
                'function_ids' => [$this->functionId],
                'section_ids' => [$this->sectionId],
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

    public function testUpdateListReplacesTheNameAndTheCriteria(): void
    {
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'identified')");
        $otherFunctionId = (int) $this->pdo->lastInsertId();

        $list = $this->listService->createCustomList(
            'Avant',
            'Description initiale.',
            [$this->functionId],
            [$this->sectionId],
            $this->accountId
        );

        $payload = $this->call('updateList', ['id' => (string) $list->id], [
            'name' => 'Après',
            'description' => 'Description révisée.',
            'function_ids' => [$otherFunctionId],
            'section_ids' => [$this->sectionId],
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('Après', $payload['list']['name']);
        $this->assertSame([$otherFunctionId], $this->listService->getCustomListFunctionIds($list->id));
    }

    public function testUpdateListOnAnUnknownIdIsRefusedRatherThanCreatingOne(): void
    {
        $response = $this->controller->updateList(
            $this->jsonRequest([
                'name' => 'Fantôme',
                'description' => 'Une liste qui n\'existe pas.',
                'function_ids' => [$this->functionId],
                'section_ids' => [$this->sectionId],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => '9999']
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_lists')->fetchColumn());
    }

    public function testToggleListDeactivatesAndReactivates(): void
    {
        $list = $this->listService->createCustomList(
            'Bascule',
            'Description.',
            [$this->functionId],
            [$this->sectionId],
            $this->accountId
        );

        $this->assertTrue($this->call('toggleList', ['id' => (string) $list->id], ['active' => false])['success']);
        $this->assertFalse($this->listService->getCustomListById($list->id)?->isActive);

        $this->assertTrue($this->call('toggleList', ['id' => (string) $list->id], ['active' => true])['success']);
        $this->assertTrue($this->listService->getCustomListById($list->id)?->isActive);
    }

    public function testDeleteListRemovesAListNoEmailUses(): void
    {
        $list = $this->listService->createCustomList(
            'Jamais utilisée',
            'Description.',
            [$this->functionId],
            [$this->sectionId],
            $this->accountId
        );

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
        $list = $this->listService->createCustomList(
            'Utilisée',
            'Description.',
            [$this->functionId],
            [$this->sectionId],
            $this->accountId
        );
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

    /**
     * Every one of the four endpoints reads its JSON body, and a body that
     * is not JSON at all is a refusal before anything else happens — not a
     * PHP notice on `$data['name']`.
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
            'updateList' => ['updateList'],
            'toggleList' => ['toggleList'],
            'deleteList' => ['deleteList'],
        ];
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
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/admin/listes-de-diffusion/lists', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn($body);

        return $request;
    }
}
