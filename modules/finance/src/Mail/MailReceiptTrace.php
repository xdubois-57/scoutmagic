<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Mail;

use Core\Journal\JournalService;

/**
 * What finance made of one attachment of an inbound message, said once in
 * the journal (#1406).
 *
 * **Why it exists.** Everything on the mail-to-receipt path that produced
 * nothing said nothing: a receipt properly filed, one dropped because
 * finance already held its bytes, and an attachment nobody ever looked at
 * left the same journal — no line. A treasurer asking « pourquoi mon reçu
 * n'est pas dans Finance ? » had nothing to read. Each outcome is now one
 * `info` line with a reason that says which of them it was.
 *
 * **What it may say.** Ids and a reason code, never a sender, a subject, a
 * file name or a word of the document (§8.6): the journal travels in a
 * support archive. The sentence is fixed text this application wrote.
 *
 * **A trace, never a gate.** Nothing here may fail the filing it describes:
 * a database that is down must not turn saying « déjà dans Finance » into a
 * second failure that stops the message's other attachments being looked at.
 */
class MailReceiptTrace
{
    public const EVENT = 'inbound_receipt_outcome';

    /** A receipt now exists on a known account. */
    public const FILED = 'filed';

    /** A receipt now exists in « Compte inconnu ». */
    public const FILED_UNKNOWN_ACCOUNT = 'filed_unknown_account';

    /** Finance already holds these bytes (live, or deleted from this message): nothing created. */
    public const ALREADY_HELD = 'already_held';

    /** This attachment was already through finance: not looked at again. */
    public const ALREADY_THROUGH = 'already_through';

    /** The document was read and does not read like a receipt. */
    public const NOT_A_RECEIPT = 'not_a_receipt';

    /** The reading failed: the deferred pass may come back (`MAX_ANALYSIS_ATTEMPTS`). */
    public const READING_FAILED = 'reading_failed';

    /** A person made the association but could not be turned into a role: nothing filed. */
    public const PERSON_UNRESOLVED = 'person_unresolved';

    private const SENTENCES = [
        self::FILED => 'un reçu a été créé',
        self::FILED_UNKNOWN_ACCOUNT => 'un reçu a été rangé dans « Compte inconnu »',
        self::ALREADY_HELD => 'déjà dans Finance, pas rangé à nouveau',
        self::ALREADY_THROUGH => 'déjà passé par Finance, pas relu',
        self::NOT_A_RECEIPT => 'ne ressemble pas à un reçu',
        self::READING_FAILED => 'lecture impossible, le document sera relu',
        self::PERSON_UNRESOLVED => 'la personne qui a rattaché le message n\'a pas de rôle connu, aucun reçu créé',
    ];

    public function __construct(private ?JournalService $journal = null)
    {
    }

    /**
     * @param int|null $receiptId the receipt that holds or came from this
     *                            attachment, when there is one
     */
    public function said(string $reason, int $messageId, int $attachmentId, ?int $receiptId = null): void
    {
        if ($this->journal === null) {
            return;
        }

        $context = ['message_id' => $messageId, 'attachment_id' => $attachmentId, 'reason' => $reason];
        if ($receiptId !== null) {
            $context['receipt_id'] = $receiptId;
        }

        try {
            $this->journal->log(
                'finance',
                self::EVENT,
                'info',
                sprintf(
                    'Courrier #%d, pièce jointe #%d : %s',
                    $messageId,
                    $attachmentId,
                    self::SENTENCES[$reason] ?? $reason
                ),
                $context,
                null
            );
        } catch (\Throwable) {
            // A trace is worth having and worth nothing at the price of the
            // filing it describes (see MailReceiptFiler::sayNotFiled()).
        }
    }
}
