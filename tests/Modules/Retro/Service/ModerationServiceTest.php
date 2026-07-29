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
}
