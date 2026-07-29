<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Service;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\Retro\Repository\Comment;
use Modules\Retro\Service\RetroException;
use Modules\Retro\Service\SummaryService;
use PHPUnit\Framework\TestCase;

class SummaryServiceTest extends TestCase
{
    private function comment(string $column, string $body): Comment
    {
        return new Comment(1, 1, $column, $body, 0, false, '2026-07-01 00:00:00');
    }

    public function testIsAvailableIsFalseWithoutAConnector(): void
    {
        $service = new SummaryService();

        $this->assertFalse($service->isAvailable());
    }

    public function testGenerateThrowsWhenUnavailable(): void
    {
        $service = new SummaryService();

        $this->expectException(RetroException::class);
        $service->generate([$this->comment('good', 'Text')]);
    }

    public function testGenerateReturnsEmptyStringWithNoComments(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->expects($this->never())->method('complete');
        $service = new SummaryService($llmConnector);

        $this->assertSame('', $service->generate([]));
    }

    public function testGenerateReturnsBulletsFromStructuredResponse(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse('', ['bullets' => ['Ambiance top', 'Repas à améliorer']], 5, 5));
        $service = new SummaryService($llmConnector);

        $summary = $service->generate([$this->comment('good', 'Super ambiance')]);

        $this->assertSame("- Ambiance top\n- Repas à améliorer", $summary);
    }

    public function testGenerateWrapsAnLlmFailureAsARetroException(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willThrowException(new LlmException('Provider down.'));
        $service = new SummaryService($llmConnector);

        $this->expectException(RetroException::class);
        $service->generate([$this->comment('good', 'Text')]);
    }
}
