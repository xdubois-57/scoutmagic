<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;

/**
 * Resolves "the first receipt linked to each movement" in bulk (one pair
 * of queries for a whole page of movements, never one pair per row) —
 * the shared plumbing behind every Service\MovementPresenter call site:
 * Controller\MovementController's movements table and search() (the
 * receipts page's "Associer à un mouvement" dialog), Controller\
 * DashboardController's "Derniers mouvements", and Controller\
 * ReceiptController's "Mouvements liés" dialog.
 */
class FirstReceiptResolver
{
    public function __construct(
        private TransactionAttachmentRepository $transactionAttachmentRepository,
        private AttachmentRepository $attachmentRepository
    ) {
    }

    /**
     * @param int[] $movementIds
     * @return array<int, Attachment> movement id => its first receipt
     */
    public function resolve(array $movementIds): array
    {
        $firstAttachmentIdsByMovementId = $this->transactionAttachmentRepository->findFirstAttachmentIdsByTransactionIds($movementIds);
        if ($firstAttachmentIdsByMovementId === []) {
            return [];
        }

        $attachmentsById = [];
        foreach ($this->attachmentRepository->findByIds(array_values($firstAttachmentIdsByMovementId)) as $attachment) {
            $attachmentsById[$attachment->id] = $attachment;
        }

        $result = [];
        foreach ($firstAttachmentIdsByMovementId as $movementId => $attachmentId) {
            if (isset($attachmentsById[$attachmentId])) {
                $result[$movementId] = $attachmentsById[$attachmentId];
            }
        }
        return $result;
    }
}
