<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Scheduler\SchedulerService;
use Modules\LlmConnector\Api\LlmConnectorInterface;

/**
 * Schedules AI-assisted amount/date/merchant extraction for a
 * newly-uploaded receipt (Task\ExtractReceiptDataHandler does the actual
 * work, asynchronously). Degrades to a no-op whenever llm_connector is
 * disabled or unavailable — a receipt is always usable with manual entry
 * regardless (ARCHITECTURE.md §7.5: core/module code holds an optional
 * dependency on another module's public API, never the reverse).
 */
class ReceiptExtractionService
{
    private const TASK_KEY = 'extract_receipt_data';

    public function __construct(
        private SchedulerService $schedulerService,
        private ?LlmConnectorInterface $llmConnector = null
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->llmConnector !== null && $this->llmConnector->isAvailable();
    }

    /**
     * No-op when isAvailable() is false — the upload flow never depends
     * on this succeeding (module spec: "aucune suggestion n'est générée
     * — le flux continue normalement avec saisie manuelle").
     */
    public function scheduleExtraction(int $attachmentId): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $this->schedulerService->scheduleAfter(
            'finance',
            self::TASK_KEY,
            0,
            ['attachment_id' => $attachmentId],
            'attachment-' . $attachmentId
        );
    }
}
