<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Audit\AuditAccessResolver;
use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Http\Controller\AuditController;
use Core\Http\Request;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

class AuditControllerTest extends TestCase
{
    private AuditService $auditService;
    private AuditAccessResolver $resolver;
    private AuditController $controller;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->auditService = new AuditService(new AuditRepository($pdo, $encryption));
        $this->resolver = new AuditAccessResolver();
        $this->controller = new AuditController(
            $this->createStub(Environment::class),
            $this->auditService,
            $this->resolver
        );
    }

    public function testAnUnregisteredEntityTypeIsRefused(): void
    {
        $this->auditService->record('camp_camp', 7, 'price', null, '100 €', AuditSource::Human);

        $response = $this->controller->page($this->request(), ['entity_type' => 'camp_camp', 'entity_id' => '7']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('100', $response->getBody());
    }

    public function testACheckerRefusingThisEntityIsRefused(): void
    {
        $this->resolver->register('camp_camp', fn(int $id): bool => $id === 42);
        $this->auditService->record('camp_camp', 7, 'price', null, '100 €', AuditSource::Human);

        $response = $this->controller->page($this->request(), ['entity_type' => 'camp_camp', 'entity_id' => '7']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRefusalLooksTheSameForAnUnknownEntityAsForADeniedOne(): void
    {
        $this->resolver->register('camp_camp', fn(int $id): bool => $id === 42);

        $denied = $this->controller->page($this->request(), ['entity_type' => 'camp_camp', 'entity_id' => '7']);
        $unknown = $this->controller->page($this->request(), ['entity_type' => 'camp_camp', 'entity_id' => '999999']);

        // A distinct 404 would let a caller map which ids exist by
        // watching which ones answer differently.
        $this->assertSame($denied->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($denied->getBody(), $unknown->getBody());
    }

    public function testAnAllowedEntityReturnsItsPageAsJson(): void
    {
        $this->resolver->register('camp_camp', fn(int $id): bool => true);
        $this->auditService->record('camp_camp', 7, 'price', '2 450 €', '2 650 €', AuditSource::Human);

        $response = $this->controller->page($this->request(), ['entity_type' => 'camp_camp', 'entity_id' => '7']);
        $payload = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $payload['total']);
        $this->assertFalse($payload['has_more']);
        $this->assertSame('price', $payload['entries'][0]['field_key']);
        $this->assertSame('2 450 €', $payload['entries'][0]['from_value']);
        $this->assertSame('2 650 €', $payload['entries'][0]['to_value']);
        $this->assertTrue($payload['entries'][0]['is_automatic']);
    }

    public function testThePageParameterSelectsTheRequestedPage(): void
    {
        $this->resolver->register('camp_camp', fn(int $id): bool => true);
        for ($i = 1; $i <= AuditService::DEFAULT_PER_PAGE + 2; $i++) {
            $this->auditService->record('camp_camp', 7, 'field' . $i, null, (string) $i, AuditSource::Human);
        }

        $first = json_decode($this->controller->page($this->request(), ['entity_type' => 'camp_camp', 'entity_id' => '7'])->getBody(), true);
        $second = json_decode($this->controller->page($this->request(['page' => '2']), ['entity_type' => 'camp_camp', 'entity_id' => '7'])->getBody(), true);

        $this->assertTrue($first['has_more']);
        $this->assertCount(AuditService::DEFAULT_PER_PAGE, $first['entries']);
        $this->assertSame(2, $second['page']);
        $this->assertCount(2, $second['entries']);
        $this->assertFalse($second['has_more']);
    }

    public function testAMissingOrInvalidIdIsRejectedBeforeAnyLookup(): void
    {
        $this->resolver->register('camp_camp', function (int $id): bool {
            $this->fail('the checker must not be reached for an invalid request');
        });

        $this->assertSame(400, $this->controller->page($this->request(), ['entity_type' => 'camp_camp', 'entity_id' => '0'])->getStatusCode());
        $this->assertSame(400, $this->controller->page($this->request(), ['entity_type' => '', 'entity_id' => '7'])->getStatusCode());
        $this->assertSame(400, $this->controller->page($this->request(), [])->getStatusCode());
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query = []): Request
    {
        return new Request('GET', '/api/audit/camp_camp/7', $query, [], [], []);
    }
}
