<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Journal\JournalService;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * Automatic receipt-to-movement association. Two passes:
 *
 * 1. Rule-based (always tried first, no AI involved, no attempt limit):
 *    amount is the strongest signal ("usually exact") and is a hard
 *    gate — a receipt with no known amount, or whose amount matches no
 *    movement, is never auto-assigned by this pass. When exactly one
 *    unassociated movement matches the amount within the date window,
 *    that's a confident match. When several do (e.g. two purchases of
 *    the same round amount), the receipt's approximate date and
 *    merchant name only ever disambiguate between them — never override
 *    the amount — and only when one candidate is clearly the best;
 *    otherwise the receipt stays pending rather than guess.
 * 2. AI-assisted (at most once ever per receipt, tracked via
 *    Attachment::matchingAiAttemptedAt): only tried when the rule-based
 *    pass found nothing AND at least one candidate movement is dated on
 *    or after the receipt's own reference date (there is something for
 *    the AI to plausibly match against — no point asking about a
 *    receipt whose movement clearly hasn't been imported yet). Shares
 *    the movements within a 3-week window on each side of that
 *    reference date and asks the LLM to pick one, or none. Degrades
 *    silently (attempt is still marked "used") when llm_connector is
 *    disabled or unavailable — matching is a convenience, never a
 *    requirement, per the same principle as Service\
 *    ReceiptExtractionService (ARCHITECTURE.md §7.5).
 */
class ReceiptMatchingService
{
    private const AMOUNT_TOLERANCE = 0.01;
    private const RULE_BACKWARD_TOLERANCE_DAYS = 3;
    private const RULE_FORWARD_TOLERANCE_DAYS = 45;
    private const AMBIGUITY_SCORE_MARGIN = 0.15;
    private const AI_WINDOW_DAYS = 21;

    public function __construct(
        private AttachmentRepository $attachmentRepository,
        private TransactionRepository $transactionRepository,
        private TransactionAttachmentRepository $transactionAttachmentRepository,
        private JournalService $journalService,
        private ?LlmConnectorInterface $llmConnector = null
    ) {
    }

    /**
     * Runs both passes for a single receipt — called right after
     * Task\ExtractReceiptDataHandler successfully parses it (the point
     * at which amount/date/merchant become known).
     */
    public function matchReceipt(Attachment $receipt): void
    {
        if ($receipt->accountId === null || $this->isAlreadyAssociated($receipt->id)) {
            return;
        }

        $candidates = $this->candidateTransactions($receipt->accountId);

        $match = $this->findRuleBasedMatch($receipt, $candidates);
        if ($match !== null) {
            $this->associate($receipt, $match, 'receipt_auto_matched', 'Reçu associé automatiquement à un mouvement (règles, sans IA)');
            return;
        }

        $this->attemptAiMatch($receipt, $candidates);
    }

    /**
     * Runs matchReceipt() for every still-pending receipt on an account —
     * called right after a bank statement import, since new movements
     * may now exist that complete a match for a receipt uploaded earlier
     * (and, symmetrically, give an AI-matching attempt something to
     * actually compare against for the first time).
     *
     * @return int number of receipts newly matched (rule-based or AI)
     */
    public function matchPendingReceiptsForAccount(int $accountId): int
    {
        $matchedBefore = count($this->transactionAttachmentRepository->findAssociatedAttachmentIds());

        foreach ($this->pendingReceiptsForAccount($accountId) as $receipt) {
            $this->matchReceipt($receipt);
        }

        $matchedAfter = count($this->transactionAttachmentRepository->findAssociatedAttachmentIds());
        return max(0, $matchedAfter - $matchedBefore);
    }

    /**
     * @param Transaction[] $candidates
     */
    private function findRuleBasedMatch(Attachment $receipt, array $candidates): ?Transaction
    {
        if ($receipt->suggestedAmount === null || $candidates === []) {
            return null;
        }

        $referenceDate = $this->referenceDate($receipt);

        $amountMatches = array_values(array_filter(
            $candidates,
            fn(Transaction $t) => abs(abs($t->amount) - $receipt->suggestedAmount) <= self::AMOUNT_TOLERANCE
                && $this->daysBetween($referenceDate, $t->transactionDate) >= -self::RULE_BACKWARD_TOLERANCE_DAYS
                && $this->daysBetween($referenceDate, $t->transactionDate) <= self::RULE_FORWARD_TOLERANCE_DAYS
        ));

        if (count($amountMatches) === 1) {
            return $amountMatches[0];
        }

        if (count($amountMatches) < 2) {
            return null;
        }

        // Several movements share this exact amount within the window —
        // date proximity and merchant-name similarity disambiguate, but
        // only when one candidate is clearly the best.
        $scored = array_map(
            fn(Transaction $t) => ['transaction' => $t, 'score' => $this->score($receipt, $t, $referenceDate)],
            $amountMatches
        );
        usort($scored, fn(array $a, array $b) => $b['score'] <=> $a['score']);

        if ($scored[0]['score'] - $scored[1]['score'] < self::AMBIGUITY_SCORE_MARGIN) {
            return null;
        }

        return $scored[0]['transaction'];
    }

    /**
     * @param Transaction[] $candidates
     */
    private function attemptAiMatch(Attachment $receipt, array $candidates): void
    {
        if ($receipt->matchingAiAttemptedAt !== null) {
            return;
        }

        $referenceDate = $this->referenceDate($receipt);
        $hasFutureCandidate = count(array_filter(
            $candidates,
            fn(Transaction $t) => $this->daysBetween($referenceDate, $t->transactionDate) >= 0
        )) > 0;
        if (!$hasFutureCandidate) {
            return;
        }

        $this->attachmentRepository->markAiMatchAttempted($receipt->id);

        if ($this->llmConnector === null || !$this->llmConnector->isAvailable()) {
            return;
        }

        $windowCandidates = array_values(array_filter(
            $candidates,
            fn(Transaction $t) => abs($this->daysBetween($referenceDate, $t->transactionDate)) <= self::AI_WINDOW_DAYS
        ));
        if ($windowCandidates === []) {
            return;
        }

        $candidatesById = [];
        foreach ($windowCandidates as $t) {
            $candidatesById[$t->id] = $t;
        }

        try {
            $response = $this->llmConnector->complete(new LlmRequest(
                tier: LlmTier::CHEAP,
                prompt: $this->buildAiMatchPrompt($receipt, $windowCandidates),
                responseSchema: [
                    'type' => 'object',
                    'properties' => [
                        'transaction_id' => ['type' => ['integer', 'null']],
                    ],
                    'required' => ['transaction_id'],
                ]
            ));
        } catch (LlmException $e) {
            $this->journalService->log(
                'finance',
                'receipt_ai_match_failed',
                'info',
                "Association IA du reçu échouée : {$e->getMessage()}",
                ['attachment_id' => $receipt->id],
                null
            );
            return;
        }

        $parsed = $response->parsed;
        $transactionId = isset($parsed['transaction_id']) && is_numeric($parsed['transaction_id']) ? (int) $parsed['transaction_id'] : null;

        if ($transactionId === null || !isset($candidatesById[$transactionId])) {
            $this->journalService->log(
                'finance',
                'receipt_ai_match_failed',
                'info',
                'Association IA du reçu échouée : aucun mouvement correspondant identifié.',
                ['attachment_id' => $receipt->id],
                null
            );
            return;
        }

        $this->associate(
            $receipt,
            $candidatesById[$transactionId],
            'receipt_ai_matched',
            'Reçu associé automatiquement à un mouvement (IA)'
        );
    }

    /**
     * @param Transaction[] $candidates
     */
    private function buildAiMatchPrompt(Attachment $receipt, array $candidates): string
    {
        $amount = $receipt->suggestedAmount !== null ? number_format($receipt->suggestedAmount, 2, '.', '') : 'inconnu';
        $date = $receipt->suggestedDate ?? 'inconnue';
        $merchant = $receipt->suggestedLabel ?? 'inconnu';

        $lines = [];
        foreach ($candidates as $t) {
            $lines[] = sprintf(
                '- id=%d date=%s montant=%s libellé="%s"',
                $t->id,
                $t->transactionDate,
                number_format(abs($t->amount), 2, '.', ''),
                $t->label
            );
        }

        return "Voici un reçu à associer à un mouvement bancaire :\n"
            . "Montant : {$amount}\nDate approximative : {$date}\nCommerçant : {$merchant}\n\n"
            . "Voici les mouvements bancaires candidats (environ 3 semaines autour de cette date) :\n"
            . implode("\n", $lines)
            . "\n\nIdentifie l'id du mouvement qui correspond à ce reçu. Si aucun ne correspond avec certitude, réponds avec transaction_id à null plutôt que de deviner.";
    }

    private function score(Attachment $receipt, Transaction $transaction, string $referenceDate): float
    {
        $diffDays = abs($this->daysBetween($referenceDate, $transaction->transactionDate));
        $dateScore = max(0.0, 1.0 - ($diffDays / self::RULE_FORWARD_TOLERANCE_DAYS));

        $labelScore = ($receipt->suggestedLabel !== null && $receipt->suggestedLabel !== '')
            ? $this->labelSimilarity($receipt->suggestedLabel, $transaction->label)
            : 0.0;

        // Date carries more weight than the label match — the label
        // comparison is explicitly approximate (an OCR-read merchant
        // name vs. a bank's own free-text description of the payment).
        return (0.7 * $dateScore) + (0.3 * $labelScore);
    }

    private function labelSimilarity(string $a, string $b): float
    {
        $normalizedA = $this->normalizeLabel($a);
        $normalizedB = $this->normalizeLabel($b);

        if ($normalizedA === '' || $normalizedB === '') {
            return 0.0;
        }

        if (str_contains($normalizedB, $normalizedA) || str_contains($normalizedA, $normalizedB)) {
            return 1.0;
        }

        similar_text($normalizedA, $normalizedB, $percent);
        return $percent / 100;
    }

    private function normalizeLabel(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value);
    }

    /**
     * The date a receipt is judged against: its own (approximate)
     * content date when known, otherwise the day it was uploaded.
     */
    private function referenceDate(Attachment $receipt): string
    {
        return $receipt->suggestedDate ?? substr($receipt->uploadedAt, 0, 10);
    }

    private function daysBetween(string $referenceDate, string $otherDate): int
    {
        $reference = new \DateTimeImmutable($referenceDate);
        $other = new \DateTimeImmutable($otherDate);
        return (int) $reference->diff($other)->format('%r%a');
    }

    private function isAlreadyAssociated(int $attachmentId): bool
    {
        return $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachmentId) !== [];
    }

    /**
     * @return Attachment[]
     */
    private function pendingReceiptsForAccount(int $accountId): array
    {
        $associatedIds = $this->transactionAttachmentRepository->findAssociatedAttachmentIds();
        return array_values(array_filter(
            $this->attachmentRepository->findActiveByAccountId($accountId),
            fn(Attachment $a) => !in_array($a->id, $associatedIds, true)
        ));
    }

    /**
     * @return Transaction[]
     */
    private function candidateTransactions(int $accountId): array
    {
        $associatedIds = $this->transactionAttachmentRepository->findAssociatedTransactionIds();
        return array_values(array_filter(
            $this->transactionRepository->findByAccountId($accountId),
            fn(Transaction $t) => !in_array($t->id, $associatedIds, true)
        ));
    }

    private function associate(Attachment $receipt, Transaction $transaction, string $eventType, string $message): void
    {
        $this->transactionAttachmentRepository->associate($transaction->id, $receipt->id);

        $this->journalService->log(
            'finance',
            $eventType,
            'info',
            $message,
            ['attachment_id' => $receipt->id, 'transaction_id' => $transaction->id],
            null
        );
    }
}
