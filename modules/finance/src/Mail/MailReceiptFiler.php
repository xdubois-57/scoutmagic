<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Mail;

use Core\Security\EncryptionService;
use Modules\Finance\Api\ExpenseReceiptInterface;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\ReceiptMailSourceRepository;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageLink;

/**
 * Files a mail attachment as a receipt by the unattended door, and
 * remembers where it came from (#1342, decisions 3, 8 and 9).
 *
 * One class for the three places that do it — an association made on
 * arrival (`FinanceMessageConsumer::onLinked()`), a receipt read off the
 * general address (`analyzeStored()`), and the catch-up of associations
 * whose receipt was never filed (`catchUp()`, from the deferred pass and
 * from `Task\CatchUpMailReceiptsHandler`) — because the rules about what
 * may be filed again are the whole point, and three copies of them would
 * be three chances to disagree.
 */
class MailReceiptFiler
{
    private ReceiptMailSourceRepository $sources;
    private AttachmentRepository $known;

    public function __construct(
        \PDO $pdo,
        EncryptionService $encryption,
        private ExpenseReceiptInterface $receipts,
        /**
         * Says that a receipt was NOT filed, and why — see
         * `FinanceMessageConsumer`'s own `$onFilingFailed`.
         *
         * @var (\Closure(\Throwable, string, int): void)|null
         */
        private ?\Closure $onFilingFailed = null,
        /**
         * Says what became of each attachment, in the journal (#1406). Null
         * says nothing, which is what a test that does not read the journal
         * wants.
         */
        private ?MailReceiptTrace $trace = null
    ) {
        $this->sources = new ReceiptMailSourceRepository($pdo);
        $this->known = new AttachmentRepository($pdo, $encryption);
    }

    /** Whether this attachment has already been through finance, whatever became of its receipt. */
    public function alreadyThrough(InboundAttachment $attachment): bool
    {
        return $this->sources->has($attachment->id);
    }

    /**
     * The receipts an automatic association should have filed and did
     * not — the controlled catch-up of #1342's decision 9.
     *
     * `onLinked()` files a message's receipts once, when the association
     * is new, and a failure there (an unreadable file, finance refusing it,
     * or the bootstrap gaps #1342 fixed) left the message on the account
     * with no receipt, for ever.
     *
     * Only associations nobody made. A person's association files under
     * that person's right to the account; filing it here, by the
     * unattended door, would be this module granting what the person was
     * refused.
     *
     * And nothing finance already holds, in ANY state
     * ({@see self::heldAnywhere()}): a catch-up that brought back a
     * deleted receipt would be undoing a decision.
     *
     * @param MessageLink[] $links
     * @param \Closure(int): ?string $readFile
     * @param int $budget at most this many attachments are looked at; the
     *                    rest stay untouched, and unremembered, for the next
     *                    pass
     * @return int how many attachments were looked at — filed, or only
     *             remembered — so a caller can tell a pass that still had
     *             work from one that found none
     */
    public function catchUp(InboundMessage $message, array $links, \Closure $readFile, int $budget = PHP_INT_MAX): int
    {
        $considered = 0;

        foreach ($links as $link) {
            if ($link->createdByUserAccountId !== null) {
                continue;
            }
            $unattributed = $link->businessReference === FinanceMessageConsumer::REFERENCE_UNKNOWN;
            $accountId = $unattributed
                ? null
                : FinanceMessageConsumer::accountIdFromReference($link->businessReference);
            if (!$unattributed && $accountId === null) {
                continue;
            }

            foreach (self::receiptAttachmentsOf($message, $link) as $attachment) {
                if ($considered >= $budget) {
                    return $considered;
                }
                if ($this->sources->settled($attachment->id)) {
                    continue;
                }
                // Counted even when it cannot be read: a pass that met only
                // unreadable files has not found the history empty. And
                // counted against the file, so the catch-up gives up on it
                // after a few runs (ReceiptMailSourceRepository::MAX_UNREADABLE_TRIES)
                // rather than holding the walk open for ever. The reader
                // says it in the journal.
                $considered++;
                $content = $readFile($attachment->fileId);
                if ($content === null || $content === '') {
                    $this->sources->markUnreadable($attachment->id, $message->id);
                    continue;
                }

                $held = $this->heldAnywhere($attachment, $content, $accountId);
                if ($held !== false) {
                    $this->sources->record(
                        $attachment->id,
                        $message->id,
                        $held,
                        ReceiptMailSourceRepository::REASON_ALREADY_HELD
                    );
                    $this->said(MailReceiptTrace::ALREADY_HELD, $message, $attachment, $held);
                    continue;
                }

                // A refusal is remembered too, and was said in the journal
                // by fileUnattended(): finance refusing the same bytes for
                // the same account again next hour is not worth a second
                // try, and a history that never ends is not a catch-up.
                if (!$this->fileUnattended($message, $attachment, $content, $accountId, $link->origin->value)) {
                    $this->sources->record(
                        $attachment->id,
                        $message->id,
                        null,
                        ReceiptMailSourceRepository::REASON_NOT_FILED
                    );
                }
            }
        }

        return $considered;
    }

    /**
     * The attachments of a receipt type an association covers: all of
     * them, or the one it names.
     *
     * @return InboundAttachment[]
     */
    public static function receiptAttachmentsOf(InboundMessage $message, MessageLink $link): array
    {
        return array_values(array_filter(
            $message->attachments,
            static fn(InboundAttachment $attachment): bool => in_array(
                $attachment->mimeType,
                FinanceMessageConsumer::RECEIPT_MIME_TYPES,
                true
            ) && ($link->attachmentId === 0 || $link->attachmentId === $attachment->id)
        ));
    }

    /**
     * Files one attachment by the unattended door and remembers where it
     * came from. True when the receipt exists afterwards.
     */
    public function fileUnattended(
        InboundMessage $message,
        InboundAttachment $attachment,
        string $content,
        ?int $accountId,
        string $reason
    ): bool {
        try {
            $fileId = $this->receipts->storeUnattendedReceipt(
                $content,
                $attachment->mimeType,
                $attachment->filename,
                $accountId
            );
        } catch (\Throwable $e) {
            $this->sayNotFiled($e, $attachment);

            return false;
        }

        $this->remember($message, $attachment, $fileId, $reason);

        return true;
    }

    /**
     * Provenance for the receipt a filing produced — or already had: the
     * store hands back the existing receipt when it recognises the bytes,
     * and that receipt came from this message too.
     */
    public function remember(InboundMessage $message, InboundAttachment $attachment, int $fileId, string $reason): void
    {
        try {
            $receipt = $this->known->findByFileId($fileId);
            $this->sources->record($attachment->id, $message->id, $receipt?->id, $reason);
            // What the store handed back may be a receipt it already held
            // (it recognises its bytes): the line says a receipt exists for
            // this attachment, which is true either way.
            $this->said(
                $receipt !== null && $receipt->accountId === null
                    ? MailReceiptTrace::FILED_UNKNOWN_ACCOUNT
                    : MailReceiptTrace::FILED,
                $message,
                $attachment,
                $receipt?->id
            );
        } catch (\Throwable $e) {
            // The receipt exists; only its provenance could not be written.
            // Worth saying, never worth failing a filing over.
            $this->sayNotFiled($e, $attachment);
        }
    }

    /**
     * Remembers an attachment whose bytes finance already holds, pointing
     * at the receipt that holds them, so a later pass skips it at once.
     */
    public function rememberHeld(InboundMessage $message, InboundAttachment $attachment, string $content): void
    {
        try {
            $held = array_key_first($this->known->statusesByContentHash(hash('sha256', $content)));
            $this->sources->record(
                $attachment->id,
                $message->id,
                $held,
                ReceiptMailSourceRepository::REASON_ALREADY_HELD
            );
            $this->said(MailReceiptTrace::ALREADY_HELD, $message, $attachment, $held);
        } catch (\Throwable $e) {
            // As remember(): a provenance that cannot be written is said,
            // and costs the message's other attachments nothing.
            $this->sayNotFiled($e, $attachment);
        }
    }

    /**
     * Whether these bytes may be filed from THIS message.
     *
     * Not when finance holds them as a live receipt anywhere — the same
     * invoice forwarded twice, or one a treasurer has since moved onto an
     * account. A receipt somebody DELETED is archived, and comes back only
     * from a NEW message (decision 3): an archived receipt known to have
     * come from other messages does not stop this one, while one from this
     * message — or of unknown origin, uploaded by hand or filed before
     * provenance existed — does, since nobody can say it is not the same.
     */
    public function mayFileFrom(InboundMessage $message, string $content): bool
    {
        foreach ($this->known->statusesByContentHash(hash('sha256', $content)) as $id => $status) {
            if ($status === Attachment::STATUS_ACTIVE) {
                return false;
            }
            $from = $this->sources->messageIdsFor($id);
            if ($from === [] || in_array($message->id, $from, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The receipt finance already holds for this attachment, in any state,
     * as its id — or false when it holds none.
     *
     * The bytes first. Then, for the receipts filed before finance kept a
     * hash, the same name and type: a catch-up walks a history that can be
     * older than the hash, and a receipt it cannot recognise by its bytes
     * would otherwise be filed a second time — or brought back after
     * somebody deleted it. Each old receipt answers for one attachment only
     * ({@see AttachmentRepository::findUnhashedByName()}), so a common name
     * costs at most one receipt per old one: the cheaper mistake.
     */
    private function heldAnywhere(InboundAttachment $attachment, string $content, ?int $accountId): int|false
    {
        $byBytes = array_key_first($this->known->statusesByContentHash(hash('sha256', $content)));
        if ($byBytes !== null) {
            return $byBytes;
        }

        return $this->known->findUnhashedByName($attachment->filename, $attachment->mimeType, $accountId) ?? false;
    }

    /**
     * One journal line saying what became of this attachment — see
     * {@see MailReceiptTrace}. Never fails the caller.
     */
    public function said(
        string $reason,
        InboundMessage $message,
        InboundAttachment $attachment,
        ?int $receiptId = null
    ): void {
        $this->trace?->said($reason, $message->id, $attachment->id, $receiptId);
    }

    public function sayNotFiled(\Throwable $e, InboundAttachment $attachment): void
    {
        if ($this->onFilingFailed === null) {
            return;
        }

        try {
            ($this->onFilingFailed)($e, $attachment->mimeType, $attachment->id);
        } catch (\Throwable) {
            // What the composition roots install here writes a row: a
            // database that is down would turn SAYING that a receipt was
            // not filed into a second, louder failure, and the rest of this
            // message's attachments would never be looked at. A trace is
            // worth having and worth nothing at that price.
        }
    }
}
