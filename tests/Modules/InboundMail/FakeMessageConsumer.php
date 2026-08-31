<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;

/**
 * A consumer that answers what it was told to, and remembers what it was
 * asked.
 *
 * Shared rather than re-declared as an anonymous class in each test file:
 * the contract has seven methods, six of which most tests do not care
 * about, and repeating them three times means three places to forget when
 * the contract moves.
 */
class FakeMessageConsumer implements MessageConsumerInterface
{
    /** @var CandidateMessage[] */
    public array $offered = [];

    /** @var array<int, array{InboundMessage, MessageLink}> */
    public array $linked = [];

    /** @var array<int, array{InboundMessage, MessageLink}> */
    public array $unlinked = [];

    /** @var array<int, array{0: string, 1: array<int, int>, 2: string}> */
    public array $readQuestions = [];

    /**
     * @param (\Closure(CandidateMessage): AnalysisResult)|null $onAnalyze
     * @param (\Closure(InboundMessage): AnalysisResult)|null $onAnalyzeStored
     */
    public function __construct(
        private string $id = 'rental',
        private ?\Closure $onAnalyze = null,
        private ?\Closure $onAnalyzeStored = null,
        private bool $readAnswer = true,
        private bool $throwsOnRead = false,
        private bool $throwsOnLinked = false
    ) {
    }

    public function consumerId(): string
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return ucfirst($this->id);
    }

    public function analyze(CandidateMessage $message): AnalysisResult
    {
        $this->offered[] = $message;

        return $this->onAnalyze !== null ? ($this->onAnalyze)($message) : AnalysisResult::nothing();
    }

    public function analyzeStored(InboundMessage $message): AnalysisResult
    {
        return $this->onAnalyzeStored !== null
            ? ($this->onAnalyzeStored)($message)
            : AnalysisResult::nothing();
    }

    public function onLinked(InboundMessage $message, MessageLink $link): void
    {
        $this->linked[] = [$message, $link];

        if ($this->throwsOnLinked) {
            throw new \RuntimeException('bug');
        }
    }

    public function onUnlinked(InboundMessage $message, MessageLink $link): void
    {
        $this->unlinked[] = [$message, $link];
    }

    public function canRead(string $businessReference, array $linkedMemberIds, string $role): bool
    {
        $this->readQuestions[] = [$businessReference, $linkedMemberIds, $role];

        if ($this->throwsOnRead) {
            throw new \RuntimeException('bug');
        }

        return $this->readAnswer;
    }

    /**
     * @return string[]
     */
    public function describeEvidence(): array
    {
        return ['un signal de test'];
    }

    public function triageAudienceLabel(): string
    {
        return 'les testeurs';
    }

    public function triageAudienceCount(): int
    {
        return 3;
    }
}
