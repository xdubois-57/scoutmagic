<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\Transaction;

/**
 * The single source of truth for how a movement's "Contrepartie" and
 * "Description" columns are derived, wherever the site shows them —
 * Controller\MovementController's movements table AND its search()
 * (the receipts page's "Associer à un mouvement" dialog). Both methods
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
    /**
     * The one bound on a description's length (issue #838): long enough
     * for « Location LOC-D4E5F6 — Chalet des Pins — Jean Dupont », short
     * enough for a table column.
     */
    public const DESCRIPTION_MAX_LENGTH = 90;
    private const MIN_COMMENT_LENGTH = 5;
    private const SEPARATOR = ' — ';

    /**
     * The counterparty's own name when the bank told us one; otherwise
     * the name this account's history attaches to the same IBAN (issue
     * #838 — Service\MovementDescriptionService works it out, nothing is
     * stored); otherwise the merchant name OCR'd off the first linked
     * receipt, if any; otherwise the movement's own account name — always
     * something meaningful to show, never blank.
     */
    public static function counterparty(
        Transaction $movement,
        ?Attachment $firstReceipt,
        string $accountName,
        ?string $inferredName = null
    ): string {
        if ($movement->counterpartyName !== null && trim($movement->counterpartyName) !== '') {
            return $movement->counterpartyName;
        }
        if ($inferredName !== null && trim($inferredName) !== '') {
            return $inferredName;
        }
        if (
            $firstReceipt !== null
            && $firstReceipt->suggestedLabel !== null
            && trim($firstReceipt->suggestedLabel) !== ''
        ) {
            return $firstReceipt->suggestedLabel;
        }
        return $accountName;
    }

    /**
     * A human description of the movement, computed every time it is shown
     * and never stored (issue #838): a late reconciliation, a corrected
     * split or a better-named receivable changes it at once.
     *
     * Not a chain of exclusive branches — every source of meaning that is
     * there adds its fragment, best first: what the movement pays (the
     * receivable taking the largest share, « + N autres » for the rest),
     * the first receipt's description, the counterparty, the bank text
     * once its structured communication is taken out, the treasurer's
     * comment, the category. Empty and repeated fragments drop out, and
     * the whole is bounded by DESCRIPTION_MAX_LENGTH.
     *
     * A structured communication is an identifier, never a description:
     * it is never shown. A credit carrying one that pays nothing reads
     * « Paiement non imputé » — what « Paiements à traiter » calls it.
     *
     * Pure: everything it needs comes in, already resolved for a whole
     * page at once.
     *
     * @param array{
     *     receivable_label?: ?string,
     *     other_receivables?: int,
     *     counterparty?: ?string,
     *     category?: ?string
     * } $context
     */
    public static function description(Transaction $movement, ?Attachment $firstReceipt, array $context = []): string
    {
        $structured = self::structuredCommunicationOf($movement);
        $fragments = [];

        $receivableLabel = trim((string) ($context['receivable_label'] ?? ''));
        if ($receivableLabel !== '') {
            $others = (int) ($context['other_receivables'] ?? 0);
            $othersLabel = $others === 1 ? 'autre créance' : 'autres créances';
            $fragments[] = $others > 0
                ? sprintf('%s (+ %d %s)', $receivableLabel, $others, $othersLabel)
                : $receivableLabel;
        } elseif ($movement->amount > 0 && $structured && $movement->notAReceivableAt === null) {
            $fragments[] = 'Paiement non imputé';
        }

        $fragments[] = $firstReceipt?->suggestedDescription;
        $fragments[] = $context['counterparty'] ?? $movement->counterpartyName;
        $fragments[] = self::freeText($movement->label);
        if ($movement->comment !== null && mb_strlen(trim($movement->comment)) > self::MIN_COMMENT_LENGTH) {
            $fragments[] = $movement->comment;
        }
        $fragments[] = $context['category'] ?? null;

        $composed = self::compose($fragments);
        if ($composed !== '') {
            return $composed;
        }

        // Nothing human left: the bank's own details, as a last resort —
        // never the structured communication on its own.
        $details = self::freeText((string) $movement->extraDetails);
        if ($details !== null) {
            return self::bounded($details);
        }
        if (!$structured && trim($movement->label) !== '') {
            return self::bounded(trim($movement->label));
        }

        return $movement->amount > 0 ? 'Virement reçu' : 'Paiement';
    }

    /**
     * Joins what is there, in the order given: empty fragments skipped, a
     * fragment already said by an earlier one skipped (« Jean Dupont »
     * after « Location LOC-1 — Jean Dupont »), bounded with an ellipsis.
     *
     * @param array<int, string|null> $fragments
     */
    public static function compose(array $fragments): string
    {
        $kept = [];
        foreach ($fragments as $fragment) {
            $fragment = trim((string) preg_replace('/\s+/u', ' ', (string) $fragment));
            if ($fragment === '') {
                continue;
            }
            foreach ($kept as $already) {
                if (mb_stripos($already, $fragment) !== false) {
                    continue 2;
                }
            }
            $kept[] = $fragment;
        }

        return self::bounded(implode(self::SEPARATOR, $kept));
    }

    /**
     * The bank text with every valid structured communication taken out,
     * or null when what is left has no letter in it — a bare reference
     * number is not a description.
     */
    private static function freeText(string $text): ?string
    {
        $text = (string) preg_replace('/[+*]{3}[\d\/ .\-]*[+*]{3}/', ' ', $text);
        $text = (string) preg_replace_callback(
            '/(?<!\d)\d{3}[\/ .\-]?\d{4}[\/ .\-]?\d{5}(?!\d)/',
            static fn(array $match): string => StructuredCommunicationService::isValid($match[0]) ? ' ' : $match[0],
            $text
        );
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return preg_match('/\p{L}/u', $text) === 1 ? $text : null;
    }

    private static function structuredCommunicationOf(Transaction $movement): bool
    {
        $stored = $movement->structuredCommunication;
        if ($stored !== null && StructuredCommunicationService::isValid($stored)) {
            return true;
        }
        foreach (StructuredCommunicationService::extract($movement->label) as $candidate) {
            if (StructuredCommunicationService::isValid($candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function bounded(string $text): string
    {
        if (mb_strlen($text) <= self::DESCRIPTION_MAX_LENGTH) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH - 1)) . '…';
    }
}
