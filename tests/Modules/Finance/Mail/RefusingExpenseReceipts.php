<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Mail;

/**
 * Finance refusing to file a receipt — the account is not open to this
 * actor, the account is unknown, the type is refused, the volume is full.
 *
 * `FinanceMessageConsumer::onLinked()` catches all of it on purpose: the
 * message really does belong on that account whatever happened to its
 * attachment, and dropping the association would be the worse trade. Two
 * tests read that catch from opposite sides — that the link still stands
 * ({@see FinanceMessageConsumerTest}), and that the failure is reported
 * rather than swallowed ({@see UnattendedReceiptFilingTest}, #175) — so
 * the double lives here rather than in either of them.
 *
 * The refusal is injectable because those two care about different ones:
 * the finance API documents `FinanceException` for an unknown account,
 * while a storage failure arrives as a plain `RuntimeException`, and the
 * consumer must be indifferent to which.
 */
class RefusingExpenseReceipts implements \Modules\Finance\Api\ExpenseReceiptInterface
{
    private \Throwable $refusal;

    public function __construct(?\Throwable $refusal = null)
    {
        $this->refusal = $refusal ?? new \RuntimeException('ce compte ne vous est pas ouvert');
    }

    /**
     * @param int[] $actorLinkedMemberIds
     * @return array<int, string>
     */
    public function receiptAccounts(string $actorRole, array $actorLinkedMemberIds): array
    {
        return [];
    }

    /**
     * @param int[] $actorLinkedMemberIds
     */
    public function storeReceipt(
        string $content,
        string $mimeType,
        string $originalFilename,
        int $accountId,
        ?float $suggestedAmount,
        ?string $suggestedDate,
        string $actorRole,
        array $actorLinkedMemberIds,
        ?int $uploadedBy
    ): int {
        throw $this->refusal;
    }

    public function storeUnattendedReceipt(
        string $content,
        string $mimeType,
        string $originalFilename,
        ?int $accountId
    ): int {
        throw $this->refusal;
    }
}
