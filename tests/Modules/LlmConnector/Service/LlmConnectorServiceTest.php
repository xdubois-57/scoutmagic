<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Service;

use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\LlmConnectorService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\LlmConnector\StubHttpServer;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class LlmConnectorServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ProviderRepository $providerRepo;
    private ProviderModelRepository $modelRepo;
    private JournalService $journalService;
    private LlmConnectorService $service;
    private ?StubHttpServer $server = null;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->createLlmTables();

        $this->encryption = new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        );

        $this->providerRepo = new ProviderRepository($this->pdo, $this->encryption);
        $this->modelRepo = new ProviderModelRepository($this->pdo);
        $this->journalService = $this->createMock(JournalService::class);

        $this->service = new LlmConnectorService(
            $this->providerRepo,
            $this->modelRepo,
            $this->journalService,
            // The stub server is plain http on 127.0.0.1; the address check
            // itself is covered by the tests below (#1269).
            endpointIsStillSafe: static fn (string $url): bool => true
        );
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testIsAvailableReturnsFalseWhenNoProvider(): void
    {
        $this->assertFalse($this->service->isAvailable());
    }

    public function testIsAvailableReturnsFalseWhenNoModelAssigned(): void
    {
        $this->providerRepo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
        $this->assertFalse($this->service->isAvailable());
    }

    public function testIsAvailableReturnsTrueWhenCheapModelAssigned(): void
    {
        $providerId = $this->providerRepo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
        $this->modelRepo->upsert($providerId, 'claude-haiku', 'Claude Haiku');

        $models = $this->modelRepo->findByProvider($providerId);
        $this->modelRepo->assignTier($models[0]['id'], LlmTier::CHEAP);

        $this->assertTrue($this->service->isAvailable());
    }

    public function testIsAvailableReturnsTrueWhenCapableModelAssigned(): void
    {
        $providerId = $this->providerRepo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
        $this->modelRepo->upsert($providerId, 'claude-sonnet', 'Claude Sonnet');

        $models = $this->modelRepo->findByProvider($providerId);
        $this->modelRepo->assignTier($models[0]['id'], LlmTier::CAPABLE);

        $this->assertTrue($this->service->isAvailable());
    }

    public function testIsAvailableReturnsTrueWhenOnlyOcrModelAssigned(): void
    {
        $providerId = $this->providerRepo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
        $this->modelRepo->upsert($providerId, 'claude-haiku', 'Claude Haiku');

        $models = $this->modelRepo->findByProvider($providerId);
        $this->modelRepo->assignTier($models[0]['id'], LlmTier::OCR);

        $this->assertTrue($this->service->isAvailable());
    }

    public function testCompleteThrowsNoProviderWhenNoneConfigured(): void
    {
        $request = new LlmRequest(LlmTier::CHEAP, 'Hello');

        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::NO_PROVIDER);

        $this->service->complete($request);
    }

    public function testCompleteThrowsNoModelWhenTierUnassigned(): void
    {
        $this->providerRepo->create('Anthropic', 'anthropic', 'https://api.anthropic.com', 'sk-test', true);
        $request = new LlmRequest(LlmTier::CHEAP, 'Hello');

        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::NO_MODEL);

        $this->service->complete($request);
    }

    public function testCompleteThrowsApiErrorForUnknownDriver(): void
    {
        $providerId = $this->providerRepo->create('Unknown', 'unknown_driver', 'https://example.com', 'key', true);
        $this->modelRepo->upsert($providerId, 'model-1', 'Model 1');

        $models = $this->modelRepo->findByProvider($providerId);
        $this->modelRepo->assignTier($models[0]['id'], LlmTier::CHEAP);

        $request = new LlmRequest(LlmTier::CHEAP, 'Hello');

        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::API_ERROR);

        $this->service->complete($request);
    }

    /**
     * A reasoning model handed a token cap sized for the ANSWER spends it
     * all thinking and returns nothing, with finish_reason "length" — an
     * HTTP 200 that produced no text. The journal said "LLM request
     * completed", which was true and useless: the one installation this
     * happened on had no way to tell that from "the model had nothing to
     * say", and the feature on the other end reported the wrong cause for
     * days.
     *
     * The entry now carries the two numbers that separate them. Neither is
     * content: this journal never holds prompt or response text.
     */
    public function testACompletedButTruncatedRequestSaysSoInTheJournal(): void
    {
        $this->server = StubHttpServer::start([
            ['body' => (string) json_encode([
                'choices' => [[
                    'message' => ['content' => '', 'reasoning_content' => 'Voyons ce terrain…'],
                    'finish_reason' => 'length',
                ]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 400],
            ])],
        ]);

        $providerId = $this->providerRepo->create('Scaleway', 'scaleway', $this->server->baseUrl(), 'key', true);
        $this->modelRepo->upsert($providerId, 'glm-5.2', 'GLM 5.2');
        $models = $this->modelRepo->findByProvider($providerId);
        $this->modelRepo->assignTier($models[0]['id'], LlmTier::CHEAP);

        $this->journalService->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'llm_request_completed',
                'info',
                'LLM request completed',
                $this->callback(static fn(array $context): bool => $context['truncated'] === true
                    && $context['content_length'] === 0
                    && $context['output_tokens'] === 400
                    && $context['max_tokens'] === 400),
                null
            );

        $response = $this->service->complete(new LlmRequest(LlmTier::CHEAP, 'Lieu : Mozet', maxTokens: 400));

        // And the caller can tell the two apart without reading the journal.
        $this->assertTrue($response->truncated);
        $this->assertSame('', $response->content);
        // The model's reasoning is never handed back as if it were the answer.
        $this->assertStringNotContainsString('Voyons ce terrain', $response->content);
    }

    public function testIsAvailableIgnoresInactiveProviders(): void
    {
        $providerId = $this->providerRepo->create('Inactive', 'anthropic', 'https://api.anthropic.com', 'sk-test', false);
        $this->modelRepo->upsert($providerId, 'claude-haiku', 'Claude Haiku');

        $models = $this->modelRepo->findByProvider($providerId);
        $this->modelRepo->assignTier($models[0]['id'], LlmTier::CHEAP);

        $this->assertFalse($this->service->isAvailable());
    }

    /**
     * **The stored address is checked again before every call** (#1269,
     * SECURITY.md §17). It was vetted when the superadmin saved it, but the
     * API key travels to it on every request, and a host can resolve
     * somewhere else since — or the row can have arrived without the form.
     *
     * @return array<string, array{string}>
     */
    public static function addressesTheKeyMustNotTravelTo(): array
    {
        return [
            'plain http' => ['http://api.anthropic.com'],
            'the loopback interface' => ['https://127.0.0.1'],
            'a private range' => ['https://10.0.0.5'],
            'the cloud metadata address' => ['https://169.254.169.254'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addressesTheKeyMustNotTravelTo')]
    public function testAStoredAddressThatNoLongerLeadsToAPublicHostIsRefused(string $endpoint): void
    {
        $providerId = $this->providerRepo->create('Anthropic', 'anthropic', $endpoint, 'sk-test', true);
        $this->modelRepo->upsert($providerId, 'claude-haiku', 'Claude Haiku');
        $this->modelRepo->assignTier($this->modelRepo->findByProvider($providerId)[0]['id'], LlmTier::CHEAP);

        // The real check, not the permissive one setUp() hands the stub tests.
        $service = new LlmConnectorService($this->providerRepo, $this->modelRepo, $this->journalService);

        $this->journalService->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'llm_request_failed',
                'info',
                'LLM request failed',
                $this->callback(static fn (array $context): bool => $context['error_code'] === LlmException::API_ERROR),
                null
            );

        try {
            $service->complete(new LlmRequest(LlmTier::CHEAP, 'Bonjour'));
            $this->fail('Expected an LlmException.');
        } catch (LlmException $e) {
            $this->assertSame(LlmException::API_ERROR, $e->getCode());
            $this->assertStringContainsString('adresse publique', $e->getMessage());
            // The sentence names no address: whoever triggered the call
            // reads it, and the address is the superadmin's to look at.
            $this->assertStringNotContainsString($endpoint, $e->getMessage());
        }
    }

    /**
     * **Refused before the driver exists, so before a single header leaves
     * the server.** A server stands at the address; it must hear nothing.
     */
    public function testARefusedAddressIsNeverContacted(): void
    {
        $this->server = StubHttpServer::start([['body' => '{"choices":[{"message":{"content":"Lu"},"finish_reason":"stop"}]}']]);
        $providerId = $this->providerRepo->create('Scaleway', 'scaleway', $this->server->baseUrl(), 'key', true);
        $this->modelRepo->upsert($providerId, 'glm-5.2', 'GLM 5.2');
        $this->modelRepo->assignTier($this->modelRepo->findByProvider($providerId)[0]['id'], LlmTier::CHEAP);

        $refusing = new LlmConnectorService(
            $this->providerRepo,
            $this->modelRepo,
            $this->journalService,
            endpointIsStillSafe: static fn (string $url): bool => false
        );

        try {
            $refusing->complete(new LlmRequest(LlmTier::CHEAP, 'Bonjour'));
            $this->fail('Expected an LlmException.');
        } catch (LlmException $e) {
            $this->assertStringContainsString('adresse publique', $e->getMessage());
        }

        $this->assertSame([], $this->server->requests());
    }

    /**
     * And a public provider whose name the resolver cannot answer for right
     * now is not refused: the call that follows cannot reach anything
     * either, and the message would accuse an address that may be fine.
     */
    public function testAnAddressTheResolverCannotAnswerForIsNotRefusedByTheCheck(): void
    {
        // Only where this installation resolves as the client does: without
        // getaddrinfo the silence proves nothing, and the check refuses.
        $this->assertSame(
            (function_exists('socket_addrinfo_lookup') && function_exists('socket_addrinfo_explain')),
            \Core\Security\SsrfUrlValidator::isStoredHttpsTargetStillSafe('https://fournisseur-inexistant.example.org')
        );
    }

    private function extractJson(string $content): string
    {
        $method = new \ReflectionMethod(LlmConnectorService::class, 'extractJson');
        $method->setAccessible(true);

        return $method->invoke($this->service, $content);
    }

    public function testExtractJsonReturnsBareJsonUnchanged(): void
    {
        $this->assertSame('{"amount": 12.5}', $this->extractJson('{"amount": 12.5}'));
    }

    public function testExtractJsonStripsMarkdownCodeFence(): void
    {
        $content = "```json\n{\"amount\": 12.5}\n```";

        $this->assertSame('{"amount": 12.5}', $this->extractJson($content));
    }

    public function testExtractJsonStripsCodeFenceWithoutLanguageTag(): void
    {
        $content = "```\n{\"amount\": 12.5}\n```";

        $this->assertSame('{"amount": 12.5}', $this->extractJson($content));
    }

    public function testExtractJsonFindsObjectAmongSurroundingText(): void
    {
        $content = "Voici le résultat :\n{\"amount\": 12.5}\nMerci.";

        $this->assertSame('{"amount": 12.5}', $this->extractJson($content));
    }

    public function testExtractJsonReturnsOriginalContentWhenNoObjectFound(): void
    {
        $this->assertSame('not json at all', $this->extractJson('not json at all'));
    }

    private function createLlmTables(): void
    {
        $this->pdo->exec('CREATE TABLE llm_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            driver TEXT NOT NULL,
            api_endpoint TEXT NOT NULL,
            api_key BLOB NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $this->pdo->exec('CREATE TABLE llm_provider_models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL,
            model_id TEXT NOT NULL,
            display_name TEXT NOT NULL,
            is_tier_cheap INTEGER NOT NULL DEFAULT 0,
            is_tier_capable INTEGER NOT NULL DEFAULT 0,
            is_tier_ocr INTEGER NOT NULL DEFAULT 0,
            last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(provider_id, model_id),
            FOREIGN KEY (provider_id) REFERENCES llm_providers(id) ON DELETE CASCADE
        )');
    }
}
