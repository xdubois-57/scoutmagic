<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\Transaction;

/**
 * The single source of truth for how a movement's "Contrepartie" and
 * "Description" columns are derived, wherever the site shows them —
 * Controller\MovementController's movements table AND its search()
 * (the receipts page's "Associer à un mouvement" dialog), and
 * Controller\DashboardController's "Derniers mouvements". Both methods
 * are pure functions of already-known data (no AI, no I/O) — the caller
 * is responsible for resolving "the first receipt linked to this
 * movement" (Repository\TransactionAttachmentRepository::
 * findFirstAttachmentIdsByTransactionIds(), "first" meaning lowest
 * attachment id, since the join table itself tracks no association
 * order) and the movement's own account name once, then passing them
 * in — never re-queried per call.
 */
final class MovementPresenter
{
    private const DESCRIPTION_TRUNCATE_LENGTH = 30;
    private const MIN_COMMENT_LENGTH = 5;

    /**
     * The counterparty's own name when the bank told us one; otherwise
     * the merchant name OCR'd off the first linked receipt, if any;
     * otherwise the movement's own account name — always something
     * meaningful to show, never blank.
     */
    public static function counterparty(Transaction $movement, ?Attachment $firstReceipt, string $accountName): string
    {
        if ($movement->counterpartyName !== null && trim($movement->counterpartyName) !== '') {
            return $movement->counterpartyName;
        }
        if ($firstReceipt !== null && $firstReceipt->suggestedLabel !== null && trim($firstReceipt->suggestedLabel) !== '') {
            return $firstReceipt->suggestedLabel;
        }
        return $accountName;
    }

    /**
     * A human-sensible one-liner built purely from already-known fields
     * (module spec follow-up: "sans IA") — in priority order: the bank
     * label itself, when it actually contains real text rather than
     * just digits/punctuation (truncated to a fixed length so it never
     * blows out the column); the admin's own comment, when it's more
     * than a token few characters; the description OCR'd off the first
     * linked receipt; and finally the raw label regardless of whether
     * it "contains text" (a purely numeric/symbolic label is still the
     * only thing there is to show).
     */
    public static function description(Transaction $movement, ?Attachment $firstReceipt): string
    {
        if (self::containsText($movement->label)) {
            return mb_substr($movement->label, 0, self::DESCRIPTION_TRUNCATE_LENGTH);
        }
        if ($movement->comment !== null && mb_strlen(trim($movement->comment)) > self::MIN_COMMENT_LENGTH) {
            return $movement->comment;
        }
        if ($firstReceipt !== null && $firstReceipt->suggestedDescription !== null && trim($firstReceipt->suggestedDescription) !== '') {
            return $firstReceipt->suggestedDescription;
        }
        return $movement->label;
    }

    /**
     * At least one Unicode letter — a label made up of only digits,
     * spaces, and punctuation (e.g. a bare bank reference number)
     * doesn't count as "containing text".
     */
    private static function containsText(string $value): bool
    {
        return preg_match('/\p{L}/u', $value) === 1;
    }
}
