<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Service;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\Retro\Service\ModerationService;
use Modules\Retro\Service\RetroException;
use PHPUnit\Framework\TestCase;

class ModerationServiceTest extends TestCase
{
    public function testIsAvailableIsFalseWithoutAConnector(): void
    {
        $service = new ModerationService();

        $this->assertFalse($service->isAvailable());
    }

    public function testModerateReturnsNullWhenUnavailable(): void
    {
        $service = new ModerationService();

        $this->assertNull($service->moderate('anything', 140));
    }

    public function testShortenThrowsWhenUnavailable(): void
    {
        $service = new ModerationService();

        $this->expectException(RetroException::class);
        $service->shorten('some text', 100);
    }

    public function testModerateReturnsNotFlaggedWhenClean(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse('', ['flagged' => false, 'reason' => null, 'suggestion' => null], 5, 5));
        $service = new ModerationService($llmConnector);

        $result = $service->moderate('Belle journée !', 140);

        $this->assertFalse($result['flagged']);
        $this->assertNull($result['reason']);
        $this->assertNull($result['suggestion']);
    }

    public function testModerateReturnsReasonAndSuggestionWhenFlagged(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse(
            '', ['flagged' => true, 'reason' => 'Insulte détectée.', 'suggestion' => 'Une reformulation plus polie.'], 5, 5
        ));
        $service = new ModerationService($llmConnector);

        $result = $service->moderate('texte injurieux', 140);

        $this->assertTrue($result['flagged']);
        $this->assertSame('Insulte détectée.', $result['reason']);
        $this->assertSame('Une reformulation plus polie.', $result['suggestion']);
    }

    public function testModerateHardTruncatesTheSuggestionToMaxLength(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse(
            '', ['flagged' => true, 'reason' => 'Insulte détectée.', 'suggestion' => str_repeat('a', 50)], 5, 5
        ));
        $service = new ModerationService($llmConnector);

        $result = $service->moderate('texte injurieux', 10);

        $this->assertSame(10, mb_strlen($result['suggestion']));
    }

    public function testModerateTreatsAnLlmFailureAsNull(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willThrowException(new LlmException('Provider down.'));
        $service = new ModerationService($llmConnector);

        $this->assertNull($service->moderate('anything', 140));
    }

    public function testShortenReturnsRewrittenText(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse('Version courte.', null, 5, 5));
        $service = new ModerationService($llmConnector);

        $this->assertSame('Version courte.', $service->shorten('Une très longue phrase à raccourcir.', 20));
    }

    public function testShortenWrapsAnLlmFailureAsARetroException(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willThrowException(new LlmException('Provider down.'));
        $service = new ModerationService($llmConnector);

        $this->expectException(RetroException::class);
        $service->shorten('text', 20);
    }

    public function testTheLlmFailuresTextNeverReachesTheRetroExceptionsOwnMessage(): void
    {
        $technical = new LlmException('HTTP 401 — {"error":{"message":"invalid x-api-key"}}');
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willThrowException($technical);
        $service = new ModerationService($llmConnector);

        try {
            $service->shorten('text', 20);
            self::fail('Expected a RetroException.');
        } catch (RetroException $e) {
            self::assertStringNotContainsString('x-api-key', $e->getMessage());
            self::assertStringNotContainsString('HTTP 401', $e->getMessage());
            self::assertStringContainsString('raccourcissement', $e->getMessage());
            self::assertSame($technical, $e->getPrevious());
        }
    }

    /**
     * Structured output is prompt-instructed, not API-enforced, so a model
     * can answer with the string "false" — truthy in PHP, which used to flag
     * a perfectly civil comment and block it in "enforced" mode.
     */
    public function testModerateTreatsAStringFalseAsNotFlagged(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse(
            content: '{"flagged":"false","reason":null,"suggestion":null}',
            parsed: ['flagged' => 'false', 'reason' => null, 'suggestion' => null],
            inputTokens: 5,
            outputTokens: 5
        ));

        $result = (new ModerationService($llmConnector))->moderate('Un commentaire tout à fait courtois.', 140);

        $this->assertNotNull($result);
        $this->assertFalse($result['flagged']);
    }

    public function testModerateTreatsAStringTrueAsNotFlaggedEither(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse(
            content: '{"flagged":"true","reason":"Attaque","suggestion":"Reformule"}',
            parsed: ['flagged' => 'true', 'reason' => 'Attaque', 'suggestion' => 'Reformule'],
            inputTokens: 5,
            outputTokens: 5
        ));

        // Only a real boolean counts. Falling back to "not flagged" is the
        // safe direction: moderation is a courtesy check, and a chief can
        // still hide a comment afterwards.
        $result = (new ModerationService($llmConnector))->moderate('Un commentaire.', 140);

        $this->assertNotNull($result);
        $this->assertFalse($result['flagged']);
    }

    public function testModerateStillFlagsOnARealBooleanTrue(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse(
            content: '{"flagged":true,"reason":"Attaque personnelle","suggestion":"Reformule"}',
            parsed: ['flagged' => true, 'reason' => 'Attaque personnelle', 'suggestion' => 'Reformule'],
            inputTokens: 5,
            outputTokens: 5
        ));

        $result = (new ModerationService($llmConnector))->moderate('Un commentaire.', 140);

        $this->assertNotNull($result);
        $this->assertTrue($result['flagged']);
        $this->assertSame('Attaque personnelle', $result['reason']);
    }
}
