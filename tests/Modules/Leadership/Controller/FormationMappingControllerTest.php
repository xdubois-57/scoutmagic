<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Controller;

use Core\Database\Connection;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Leadership\Controller\FormationMappingController;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Leadership\LeadershipTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[Group('database')]
class FormationMappingControllerTest extends TestCase
{
    private \PDO $pdo;
    private FormationLevelMappingRepository $repository;
    private FormationMappingController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        LeadershipTestHelper::createTables($this->pdo);

        $this->repository = new FormationLevelMappingRepository(Connection::withPdo($this->pdo));
        $this->controller = new FormationMappingController(
            new Environment(new ArrayLoader([])),
            $this->repository,
            new JournalService(new JournalRepository($this->pdo))
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    /**
     * @param array<string, string> $body
     */
    private function post(array $body, bool $validCsrf = true): Request
    {
        $token = CsrfGuard::generateToken();
        $body['_csrf_token'] = $validCsrf ? $token : 'invalide';

        return new Request('POST', '/admin/leadership/training/mapping', [], $body, [], []);
    }

    public function testSavesAMapping(): void
    {
        $response = $this->controller->save($this->post(['raw_value' => 'Zorglub', 'step' => 't2']), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([['raw_value' => 'Zorglub', 'step' => 't2']], $this->repository->findAllRows());
    }

    public function testAnEmptyStepRemovesTheMapping(): void
    {
        $this->controller->save($this->post(['raw_value' => 'Zorglub', 'step' => 't2']), []);
        $this->controller->save($this->post(['raw_value' => 'Zorglub', 'step' => '']), []);

        $this->assertSame([], $this->repository->findAllRows());
    }

    /**
     * 'unknown' is a real FormationStep but not an assignable one: it is
     * what the site says when nobody has decided, never a decision. A
     * hand-crafted POST must not be able to store it.
     */
    public function testUnknownCannotBeStoredAsADecision(): void
    {
        $this->controller->save($this->post(['raw_value' => 'Zorglub', 'step' => 'unknown']), []);

        $this->assertSame([], $this->repository->findAllRows());
    }

    public function testAnInvalidStepIsRefused(): void
    {
        $this->controller->save($this->post(['raw_value' => 'Zorglub', 'step' => 'pas-une-etape']), []);

        $this->assertSame([], $this->repository->findAllRows());
    }

    public function testAnEmptyRawValueIsRefused(): void
    {
        $this->controller->save($this->post(['raw_value' => '   ', 'step' => 't1']), []);

        $this->assertSame([], $this->repository->findAllRows());
    }

    public function testABadCsrfTokenWritesNothing(): void
    {
        $response = $this->controller->save(
            $this->post(['raw_value' => 'Zorglub', 'step' => 't2'], validCsrf: false),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->repository->findAllRows());
    }

    /**
     * The journal records that a mapping changed and nothing else. The
     * value and the step are deliberately absent: the page they were
     * changed on lists exactly who holds that value, which makes the pair
     * a good deal more identifying than either half (SECURITY.md §11).
     */
    public function testTheJournalEntryCarriesNoContent(): void
    {
        $this->controller->save($this->post(['raw_value' => 'Zorglub', 'step' => 't2']), []);

        $rows = $this->pdo->query('SELECT event_type, context FROM event_log')->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('leadership_formation_mapping_saved', $rows[0]['event_type']);
        $this->assertNull($rows[0]['context']);
    }

    public function testRemovingAMappingIsJournalledUnderItsOwnType(): void
    {
        $this->controller->save($this->post(['raw_value' => 'Zorglub', 'step' => '']), []);

        $rows = $this->pdo->query('SELECT event_type FROM event_log')->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame('leadership_formation_mapping_removed', $rows[0]['event_type']);
    }
}
