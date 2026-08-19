<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Provider;

use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Provider\AnthropicProvider;
use Modules\LlmConnector\Provider\LlmProviderInterface;
use Modules\LlmConnector\Provider\MistralProvider;
use Modules\LlmConnector\Provider\ScalewayProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Modules\LlmConnector\StubHttpServer;

/**
 * Behaviour every driver must share, exercised against a real endpoint —
 * chiefly that a failed call surfaces as an exception rather than as an
 * empty-but-successful completion, which is what silently disabled retro
 * moderation and blanked the news SEO field.
 */
class ProviderHttpBehaviourTest extends TestCase
{
    private ?StubHttpServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    /**
     * @return array<string, array{0: class-string<LlmProviderInterface>}>
     */
    public static function driverProvider(): array
    {
        return [
            'anthropic' => [AnthropicProvider::class],
            'mistral' => [MistralProvider::class],
            'scaleway' => [ScalewayProvider::class],
        ];
    }

    /**
     * @param class-string<LlmProviderInterface> $driverClass
     */
    #[DataProvider('driverProvider')]
    public function testCompleteThrowsRatherThanReturningAnEmptyCompletion(string $driverClass): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 401, 'body' => '{"message":"Unauthorized","request_id":"abc123"}'],
        ]);

        $driver = new $driverClass($this->server->baseUrl(), 'bad-key');

        $this->expectException(LlmException::class);
        $driver->complete('some-model', 'Bonjour', ['max_tokens' => 100]);
    }

    /**
     * @param class-string<LlmProviderInterface> $driverClass
     */
    #[DataProvider('driverProvider')]
    public function testListModelsThrowsOnAnAuthenticationFailure(string $driverClass): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 403, 'body' => '{"message":"forbidden"}'],
        ]);

        $driver = new $driverClass($this->server->baseUrl(), 'bad-key');

        $this->expectException(LlmException::class);
        $driver->listModels();
    }

    /**
     * @param class-string<LlmProviderInterface> $driverClass
     */
    #[DataProvider('driverProvider')]
    public function testCompleteReportsAnUnencodablePromptAsAnLlmException(string $driverClass): void
    {
        $this->server = StubHttpServer::start([['status' => 200, 'body' => '{}']]);

        $driver = new $driverClass($this->server->baseUrl(), 'k');

        // A transaction label carried over verbatim from a Latin-1 bank export.
        $this->expectException(LlmException::class);
        $driver->complete('some-model', "Libellé : Caf\xE9 du coin", []);
    }

    /**
     * @param class-string<LlmProviderInterface> $driverClass
     */
    #[DataProvider('driverProvider')]
    public function testListModelsSkipsAModelWithNoIdentifier(string $driverClass): void
    {
        $this->server = StubHttpServer::start([[
            'status' => 200,
            'body' => json_encode(['data' => [
                ['id' => 'good-model', 'display_name' => 'Good', 'name' => 'Good'],
                ['display_name' => 'No id at all', 'name' => 'No id at all'],
                ['id' => '', 'display_name' => 'Empty id', 'name' => 'Empty id'],
            ]], JSON_THROW_ON_ERROR),
        ]]);

        $driver = new $driverClass($this->server->baseUrl(), 'k');
        $models = $driver->listModels();

        self::assertSame(['good-model'], array_column($models, 'id'));
    }

    public function testAnthropicFollowsModelPagination(): void
    {
        $this->server = StubHttpServer::start([
            [
                'status' => 200,
                'body' => json_encode([
                    'data' => [['id' => 'claude-a', 'display_name' => 'A']],
                    'has_more' => true,
                    'last_id' => 'claude-a',
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'status' => 200,
                'body' => json_encode([
                    'data' => [['id' => 'claude-b', 'display_name' => 'B']],
                    'has_more' => false,
                    'last_id' => 'claude-b',
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $models = (new AnthropicProvider($this->server->baseUrl(), 'k'))->listModels();

        self::assertSame(['claude-a', 'claude-b'], array_column($models, 'id'));

        $requests = $this->server->requests();
        self::assertCount(2, $requests);
        self::assertStringNotContainsString('after_id', $requests[0]['uri']);
        self::assertStringContainsString('after_id=claude-a', $requests[1]['uri']);
    }

    public function testAnthropicStopsPaginatingWhenHasMoreIsAbsent(): void
    {
        $this->server = StubHttpServer::start([[
            'status' => 200,
            'body' => json_encode(['data' => [['id' => 'claude-a', 'display_name' => 'A']]], JSON_THROW_ON_ERROR),
        ]]);

        $models = (new AnthropicProvider($this->server->baseUrl(), 'k'))->listModels();

        self::assertSame(['claude-a'], array_column($models, 'id'));
        self::assertCount(1, $this->server->requests());
    }

    /**
     * A has_more that never turns false must not spin forever.
     */
    public function testAnthropicPaginationIsBounded(): void
    {
        $this->server = StubHttpServer::start([[
            'status' => 200,
            'body' => json_encode([
                'data' => [['id' => 'claude-loop', 'display_name' => 'Loop']],
                'has_more' => true,
                'last_id' => 'claude-loop',
            ], JSON_THROW_ON_ERROR),
        ]]);

        (new AnthropicProvider($this->server->baseUrl(), 'k'))->listModels();

        self::assertLessThanOrEqual(20, count($this->server->requests()));
    }

    /**
     * @param class-string<LlmProviderInterface> $driverClass
     */
    #[DataProvider('driverProvider')]
    public function testCompleteStillReturnsContentOnASuccessfulCall(string $driverClass): void
    {
        $bodies = [
            AnthropicProvider::class => ['content' => [['type' => 'text', 'text' => 'Salut']], 'usage' => ['input_tokens' => 7, 'output_tokens' => 2]],
            MistralProvider::class => ['choices' => [['message' => ['content' => 'Salut'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 2]],
            ScalewayProvider::class => ['choices' => [['message' => ['content' => 'Salut'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 2]],
        ];

        $this->server = StubHttpServer::start([[
            'status' => 200,
            'body' => json_encode($bodies[$driverClass], JSON_THROW_ON_ERROR),
        ]]);

        $response = (new $driverClass($this->server->baseUrl(), 'k'))->complete('m', 'Bonjour', ['max_tokens' => 50]);

        self::assertSame('Salut', $response->content);
        self::assertSame(7, $response->inputTokens);
        self::assertSame(2, $response->outputTokens);
        self::assertFalse($response->truncated);
    }
}
