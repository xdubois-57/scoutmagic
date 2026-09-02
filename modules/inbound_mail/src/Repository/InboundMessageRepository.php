<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Repository;

use Core\Mail\RawHeaderBlock;
use Core\Security\EncryptionService;
use Core\Service\DateInput;
use Modules\InboundMail\Api\AttachmentOmission;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\OmittedAttachment;

/**
 * The stored messages, their associations and their attachments.
 *
 * **A message and the objects it belongs to are two different things.** The
 * message is a row of `inbound_messages`; each association it carries is a
 * row of `inbound_message_links`. That is what lets one email be a
 * booking's correspondence and an invoice at the same time, and what makes
 * "detach" mean "remove one association" rather than "destroy the message".
 *
 * **Every read offered to a consumer is scoped**, either to a business
 * reference of its own or to a mailbox and a Message-ID. There is
 * deliberately no `findAll()` and no free-text search on this path: an
 * unscoped query is how a manager's access to one booking turns into a
 * window onto the unit's whole mailbox (§7.11), and the way to stop that is
 * not to write the query.
 *
 * Personal data — the subject, the addresses, both bodies, the attachment
 * names — is encrypted at rest and only ever decrypted here. What is
 * indexed instead is a blind index, since an encrypted column cannot be
 * compared.
 */
class InboundMessageRepository
{
    /**
     * The floor a message earns when its last association is removed (A4).
     *
     * Retention is measured on `sent_at`, so without this a message from
     * 2024 detached by mistake would be gone on the next nightly purge.
     * Thirty days is a window somebody can notice a mis-click in.
     */
    public const UNLINK_GRACE_DAYS = 30;

    /**
     * How much of a raw header block is kept. A message that crossed
     * several relays carries a long chain of `Received` lines, and there
     * is no useful ceiling on how long — one crossing a mailing list and
     * three forwarders can run to tens of kilobytes, per message, on a box
     * that keeps months of mail.
     *
     * 16 KiB holds the whole chain of anything ordinary. Past it the value
     * is cut and **says so inside itself**, the way the support package's
     * collectors declare their own truncation: a diagnosis read from a
     * silently shortened header block is a diagnosis of the wrong message.
     *
     * The rule itself now lives in `Core\Mail\RawHeaderBlock`, because a
     * second table keeps a header block too — the diagnostic probes of
     * `Modules\SupportDashboard` — and neither module may reach into the
     * other's repository for it. These two stay as aliases: they are what
     * this class's own tests and readers already look for.
     */
    public const MAX_RAW_HEADERS_BYTES = RawHeaderBlock::MAX_BYTES;

    /** The marker a truncated header block ends with. */
    public const RAW_HEADERS_TRUNCATION_MARKER = RawHeaderBlock::TRUNCATION_MARKER;

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Write the message itself. It belongs to nobody yet — `addLink()` is
     * what associates it with something.
     *
     * `$rawHeaders` is written only when a consumer that claimed the
     * message asked for it (roadmap IT-22); null and empty both mean "keep
     * nothing", which is the ordinary case and leaves the column NULL.
     *
     * @param string[] $toEmails
     */
    public function create(
        int $mailboxId,
        string $folder,
        int $uidValidity,
        int $imapUid,
        string $messageId,
        ?string $inReplyTo,
        string $subject,
        string $fromEmail,
        ?string $fromName,
        string $bodyText,
        string $bodyHtml,
        \DateTimeImmutable $sentAt,
        array $toEmails = [],
        bool $isBulk = false,
        ?string $rawHeaders = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_messages
                (mailbox_id, folder, uid_validity, imap_uid,
                 message_id_blind_index, in_reply_to_blind_index, from_email_blind_index,
                 subject_encrypted, from_email_encrypted, from_name_encrypted, message_id_encrypted,
                 in_reply_to_encrypted, to_emails_encrypted, body_text_encrypted, body_html_encrypted,
                 raw_headers_encrypted, sent_at, is_bulk)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $mailboxId,
            $folder,
            $uidValidity,
            $imapUid,
            $this->messageIdIndex($messageId),
            $inReplyTo !== null ? $this->messageIdIndex($inReplyTo) : null,
            $this->encryption->blindIndex(EncryptionService::normalizeEmailForIndex($fromEmail), 'email'),
            $this->encryption->encrypt($subject, 'inbound_messages.subject'),
            $this->encryption->encrypt($fromEmail, 'inbound_messages.from_email'),
            $fromName !== null ? $this->encryption->encrypt($fromName, 'inbound_messages.from_name') : null,
            $this->encryption->encrypt($messageId, 'inbound_messages.message_id'),
            $inReplyTo !== null ? $this->encryption->encrypt($inReplyTo, 'inbound_messages.in_reply_to') : null,
            $toEmails !== []
                ? $this->encryption->encrypt(implode("\n", $toEmails), 'inbound_messages.to_emails')
                : null,
            $this->encryption->encrypt($bodyText, 'inbound_messages.body_text'),
            $this->encryption->encrypt($bodyHtml, 'inbound_messages.body_html'),
            $rawHeaders === null || $rawHeaders === ''
                ? null
                : $this->encryption->encrypt(RawHeaderBlock::bounded($rawHeaders), 'inbound_messages.raw_headers'),
            $sentAt->format('Y-m-d H:i:s'),
            $isBulk ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // ── Associations ────────────────────────────────────────────────────

    /**
     * Associate a message with a business object, **idempotently**.
     *
     * Two people orienting the same message towards the same target produce
     * one association, not two, and neither of them sees an error: the
     * second is simply told nothing new happened. Towards two different
     * targets they produce two associations, which is a valid state rather
     * than a conflict.
     *
     * The SELECT-then-INSERT is deliberate rather than an
     * `INSERT ... ON DUPLICATE KEY`, which SQLite — the test database —
     * spells differently; the unique index is still what makes it correct
     * under a race, and that is what the catch below is for.
     *
     * @return bool whether an association was actually created
     */
    public function addLink(
        int $messageId,
        string $consumerId,
        string $businessReference,
        LinkOrigin $origin,
        int $attachmentId = 0,
        ?int $createdByUserAccountId = null
    ): bool {
        if ($this->hasLink($messageId, $consumerId, $businessReference, $attachmentId)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_message_links
                (message_id, consumer_id, business_reference, attachment_id, link_origin, created_by_user_account_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        try {
            $stmt->execute([
                $messageId,
                $consumerId,
                $businessReference,
                $attachmentId,
                $origin->value,
                $createdByUserAccountId,
            ]);
        } catch (\PDOException) {
            // The unique index caught a concurrent insert of the same
            // association. Idempotent means idempotent: that is the state
            // the caller asked for, so it is not an error.
            return false;
        }

        return true;
    }

    public function hasLink(
        int $messageId,
        string $consumerId,
        string $businessReference,
        int $attachmentId = 0
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM inbound_message_links
              WHERE message_id = ? AND consumer_id = ? AND business_reference = ? AND attachment_id = ?
              LIMIT 1'
        );
        $stmt->execute([$messageId, $consumerId, $businessReference, $attachmentId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Remove one consumer's association with one business object — every
     * attachment-level association included, since removing "this message
     * belongs to that booking" cannot sensibly leave "one of its files
     * belongs to that booking" behind.
     *
     * `$now` is what stamps `last_unlinked_at`, and is a parameter rather
     * than a `new DateTimeImmutable()` inside for the reason every other
     * timestamp in this repository is: an instant the caller controls is an
     * instant a test can control, and the thirty-day floor this stamp feeds
     * (A4) is otherwise only assertable by waiting a month.
     *
     * @return bool whether anything was removed
     */
    public function removeLink(
        int $messageId,
        string $consumerId,
        string $businessReference,
        ?\DateTimeImmutable $now = null
    ): bool {
        $stmt = $this->pdo->prepare(
            'DELETE FROM inbound_message_links
              WHERE message_id = ? AND consumer_id = ? AND business_reference = ?'
        );
        $stmt->execute([$messageId, $consumerId, $businessReference]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        // Stamped only when the LAST association goes. The retention floor
        // of A4 protects a message nobody points at any more; a message
        // that still belongs to somebody was never at risk, and stamping it
        // would push its eventual purge out for no reason.
        if ($this->countLinks($messageId) === 0) {
            $stamp = $this->pdo->prepare('UPDATE inbound_messages SET last_unlinked_at = ? WHERE id = ?');
            $stamp->execute([($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'), $messageId]);
        }

        return true;
    }

    /**
     * Every association a message carries, whoever made it.
     *
     * Unscoped on purpose, and the one read that is: this is what
     * `Service\InboundMessageAccessRegistry` asks before letting anybody
     * download an attachment, and it needs the whole list to know which
     * consumers to put the question to. Nothing about the message itself
     * comes back with it.
     *
     * @return MessageLink[]
     */
    public function findLinksForMessage(int $messageId): array
    {
        return $this->findLinksFor([$messageId])[$messageId] ?? [];
    }

    /**
     * How many associations a message still carries — what tells a caller
     * whether removing one has left the message belonging to nobody.
     */
    public function countLinks(int $messageId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM inbound_message_links WHERE message_id = ?');
        $stmt->execute([$messageId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Move a consumer's association from one of its references to another.
     *
     * Idempotent in the same sense as `addLink()`: if the message is
     * already associated with the target, the source association is simply
     * removed rather than colliding with it.
     */
    public function moveToReference(
        int $messageId,
        string $consumerId,
        string $fromReference,
        string $toReference
    ): bool {
        if (!$this->hasLink($messageId, $consumerId, $fromReference)) {
            return false;
        }

        if ($this->hasLink($messageId, $consumerId, $toReference)) {
            $this->removeLink($messageId, $consumerId, $fromReference);

            return true;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE inbound_message_links SET business_reference = ?
              WHERE message_id = ? AND consumer_id = ? AND business_reference = ?'
        );
        $stmt->execute([$toReference, $messageId, $consumerId, $fromReference]);

        return $stmt->rowCount() > 0;
    }

    // ── Propositions ────────────────────────────────────────────────────

    /**
     * Record a proposition, unless the same one already exists **in any
     * state**.
     *
     * A proposition somebody set aside is never re-created, and that is the
     * whole subtlety here: `dismissed_at` is final (A3/D10). A consumer
     * re-emitting the same guess after a manual re-analysis must not undo a
     * human decision — so the existence check deliberately ignores whether
     * the row is dismissed.
     *
     * @return bool whether a proposition was actually created
     */
    public function addCandidate(
        int $messageId,
        string $consumerId,
        MessageCandidate $candidate
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM inbound_message_candidates
              WHERE message_id = ? AND consumer_id = ? AND business_reference = ? AND attachment_id = ?
              LIMIT 1'
        );
        $stmt->execute([$messageId, $consumerId, $candidate->businessReference, $candidate->attachmentId]);
        if ($stmt->fetchColumn() !== false) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_message_candidates
                (message_id, attachment_id, consumer_id, business_reference,
                 evidence_type, evidence_label_encrypted, evidence_explanation_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        try {
            $stmt->execute([
                $messageId,
                $candidate->attachmentId,
                $consumerId,
                $candidate->businessReference,
                $candidate->evidenceType,
                $this->encryption->encrypt($candidate->label, 'inbound_message_candidates.evidence_label'),
                $this->encryption->encrypt(
                    $candidate->explanation,
                    'inbound_message_candidates.evidence_explanation'
                ),
            ]);
        } catch (\PDOException) {
            // The unique index caught a concurrent insert of the same
            // proposition. That is the state the caller asked for.
            return false;
        }

        return true;
    }

    /**
     * Set a proposition aside, for good.
     */
    public function dismissCandidate(
        int $messageId,
        string $consumerId,
        string $businessReference,
        int $attachmentId,
        \DateTimeImmutable $now
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_message_candidates SET dismissed_at = ?
              WHERE message_id = ? AND consumer_id = ? AND business_reference = ? AND attachment_id = ?
                AND dismissed_at IS NULL'
        );
        $stmt->execute([
            $now->format('Y-m-d H:i:s'),
            $messageId,
            $consumerId,
            $businessReference,
            $attachmentId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The propositions still standing on a message — the ones set aside are
     * not among them.
     *
     * @return MessageCandidate[]
     */
    public function findActiveCandidates(int $messageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM inbound_message_candidates
              WHERE message_id = ? AND dismissed_at IS NULL
           ORDER BY id ASC'
        );
        $stmt->execute([$messageId]);

        return array_map(
            fn(array $row) => new MessageCandidate(
                businessReference: (string) $row['business_reference'],
                label: $this->encryption->decrypt(
                    (string) $row['evidence_label_encrypted'],
                    'inbound_message_candidates.evidence_label'
                ),
                evidenceType: (string) $row['evidence_type'],
                explanation: $this->encryption->decrypt(
                    (string) $row['evidence_explanation_encrypted'],
                    'inbound_message_candidates.evidence_explanation'
                ),
                attachmentId: (int) $row['attachment_id'],
                id: (int) $row['id'],
                consumerId: (string) $row['consumer_id']
            ),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Whether a message still carries a proposition nobody has set aside —
     * what keeps it out of the retention purge.
     */
    public function hasActiveCandidates(int $messageId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM inbound_message_candidates
              WHERE message_id = ? AND dismissed_at IS NULL LIMIT 1'
        );
        $stmt->execute([$messageId]);

        return $stmt->fetchColumn() !== false;
    }

    // ── The deferred, content-level pass ────────────────────────────────

    /**
     * Message ids that have never been through the deferred pass, oldest
     * first, bounded.
     *
     * Bounded because `poor_mans_cron` runs inside a page view on shared
     * hosting: a pass that tried to extract the text of every PDF in a
     * five-year-old mailbox would be killed by `max_execution_time`, and
     * the task would come back to the same doomed batch on every tick.
     *
     * @return int[]
     */
    public function findMessagesAwaitingStoredAnalysis(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM inbound_messages
              WHERE stored_analysis_at IS NULL
           ORDER BY id ASC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * One message with everything on it, unscoped.
     *
     * **The one read that is not scoped to a consumer, and it is not
     * reachable from `Api\InboundMailInterface`.** It exists for the
     * deferred analysis pass, which has to hand a message to consumers that
     * are not associated with it yet — that being the whole point of asking
     * them. Adding it to the public interface would undo §7.11's rule that
     * a manager's access to one booking never becomes a window onto the
     * unit's mailbox, so it stays here, on the repository, where only this
     * module's own tasks reach it.
     */
    public function findAnyForAnalysis(int $messageId): ?InboundMessage
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inbound_messages WHERE id = ?');
        $stmt->execute([$messageId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $links = $this->findLinksFor([$messageId])[$messageId] ?? [];

        return $this->hydrate(
            $row,
            $this->findAttachmentsFor([$messageId])[$messageId] ?? [],
            $links,
            $links[0]->consumerId ?? '',
            $links[0]->businessReference ?? '',
            $this->findOmittedAttachmentsFor([$messageId])[$messageId] ?? []
        );
    }

    /**
     * The stored messages that belong to NOTHING, in the boxes given,
     * newest first.
     *
     * What « relancer l'analyse » works on. Unlinked is the whole filter,
     * and deliberately: a message already attached to a stay, a booking or
     * an invoice is a message somebody's reading already settled, and
     * offering it around again could only produce a second claim on
     * something that is not in doubt. What IS in doubt is the mail nobody
     * could attribute — which is exactly the list this screen shows.
     *
     * @param int[] $mailboxIds
     * @return InboundMessage[]
     */
    public function findUnlinkedForReanalysis(array $mailboxIds, int $limit): array
    {
        if ($mailboxIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($mailboxIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM inbound_messages m
              WHERE m.mailbox_id IN (' . $placeholders . ')
                AND NOT EXISTS (SELECT 1 FROM inbound_message_links l WHERE l.message_id = m.id)
           ORDER BY m.sent_at DESC, m.id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute(array_map('intval', array_values($mailboxIds)));

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Put messages back in front of the deferred, content-level pass.
     *
     * The narrow counterpart of `queueAllForStoredAnalysis()`: that one
     * offers the WHOLE box again and belongs to a superadmin, this one
     * takes the ids a caller already scoped and is what a chief's
     * « relancer l'analyse » reaches. Propositions somebody set aside stay
     * set aside either way — `addCandidate()` refuses to re-create them.
     *
     * @param int[] $messageIds
     */
    public function queueForStoredAnalysis(array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_messages SET stored_analysis_at = NULL WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_map('intval', array_values($messageIds)));
    }

    public function markStoredAnalysisDone(int $messageId, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE inbound_messages SET stored_analysis_at = ? WHERE id = ?');
        $stmt->execute([$now->format('Y-m-d H:i:s'), $messageId]);
    }

    /**
     * Offer every stored message to the deferred pass again — the manual
     * « Réanalyser le courrier conservé ».
     *
     * The indispensable corollary of analysing only once (§8.58): without
     * it, enabling Finances on `tresorerie@` after three months of
     * collecting would produce exactly nothing. It only clears the marker;
     * propositions already set aside stay set aside, because `addCandidate()`
     * refuses to re-create them.
     *
     * @return int the number of messages queued for re-analysis
     */
    public function queueAllForStoredAnalysis(): int
    {
        $stmt = $this->pdo->query('UPDATE inbound_messages SET stored_analysis_at = NULL');

        return $stmt === false ? 0 : $stmt->rowCount();
    }

    // ── Reads scoped to one consumer ────────────────────────────────────

    /**
     * @return InboundMessage[]
     */
    public function findForReference(string $consumerId, string $businessReference): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM inbound_messages m
               JOIN inbound_message_links l ON l.message_id = m.id
              WHERE l.consumer_id = ? AND l.business_reference = ?
           GROUP BY m.id
           ORDER BY m.sent_at ASC, m.id ASC'
        );
        $stmt->execute([$consumerId, $businessReference]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $row) => (int) $row['id'], $rows);
        $attachments = $this->findAttachmentsFor($ids);
        $links = $this->findLinksFor($ids);
        $omitted = $this->findOmittedAttachmentsFor($ids);

        return array_map(
            fn(array $row) => $this->hydrate(
                $row,
                $attachments[(int) $row['id']] ?? [],
                $links[(int) $row['id']] ?? [],
                $consumerId,
                $businessReference,
                $omitted[(int) $row['id']] ?? []
            ),
            $rows
        );
    }

    public function findOneForReference(string $consumerId, string $businessReference, int $messageId): ?InboundMessage
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM inbound_messages m
               JOIN inbound_message_links l ON l.message_id = m.id
              WHERE m.id = ? AND l.consumer_id = ? AND l.business_reference = ?
              LIMIT 1'
        );
        $stmt->execute([$messageId, $consumerId, $businessReference]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate(
            $row,
            $this->findAttachmentsFor([$messageId])[$messageId] ?? [],
            $this->findLinksFor([$messageId])[$messageId] ?? [],
            $consumerId,
            $businessReference,
            $this->findOmittedAttachmentsFor([$messageId])[$messageId] ?? []
        );
    }

    /**
     * The message ids associated with one business object.
     *
     * @return int[]
     */
    public function findMessageIdsForReference(string $consumerId, string $businessReference): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT message_id FROM inbound_message_links
              WHERE consumer_id = ? AND business_reference = ?'
        );
        $stmt->execute([$consumerId, $businessReference]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The message this mailbox already holds under that Message-ID, if any.
     *
     * **Per mailbox, not per business object.** A message exists once in a
     * box however many objects it ends up associated with — which is what
     * makes a UIDVALIDITY reset a re-read rather than a duplication (§7.5),
     * and what stopped the same email being stored twice because two
     * modules recognised it.
     */
    public function findIdByMessageId(int $mailboxId, string $messageId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM inbound_messages
              WHERE mailbox_id = ? AND message_id_blind_index = ?
              LIMIT 1'
        );
        $stmt->execute([$mailboxId, $this->messageIdIndex($messageId)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * The business object a known message belongs to, looked up by one of
     * the Message-IDs a reply names. This is what makes a reply carrying no
     * reference land on the right object anyway (§7.6, level 2) — and it
     * lives here rather than in a consumer because the consumer has no way
     * to look inside this module's storage.
     *
     * @param string[] $messageIds most specific first
     * @return array{reference: string, consumer_id: string}|null
     */
    public function findReferenceByThread(int $mailboxId, string $consumerId, array $messageIds): ?array
    {
        foreach ($messageIds as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $stmt = $this->pdo->prepare(
                'SELECT l.business_reference, l.consumer_id
                   FROM inbound_messages m
                   JOIN inbound_message_links l ON l.message_id = m.id
                  WHERE m.mailbox_id = ? AND l.consumer_id = ? AND m.message_id_blind_index = ?
                  LIMIT 1'
            );
            $stmt->execute([$mailboxId, $consumerId, $this->messageIdIndex($candidate)]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (is_array($row)) {
                return [
                    'reference' => (string) $row['business_reference'],
                    'consumer_id' => (string) $row['consumer_id'],
                ];
            }
        }

        return null;
    }

    // ── Deletion ────────────────────────────────────────────────────────

    /**
     * Destroy a message outright — its associations, its attachment rows,
     * then itself.
     *
     * Attachment rows go first, explicitly. The schema does declare
     * `ON DELETE CASCADE`, but relying on it for correctness would make
     * this method behave differently depending on the engine underneath —
     * and the caller reads `countAttachmentsForFile()` immediately
     * afterwards to decide whether a stored file is now unreferenced.
     * Deleting them here means that count is right on every engine, with
     * the cascade left as a backstop rather than as the mechanism.
     */
    public function deleteMessage(int $messageId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM inbound_messages WHERE id = ? LIMIT 1');
        $stmt->execute([$messageId]);
        if ($stmt->fetchColumn() === false) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM inbound_message_links WHERE message_id = ?');
        $stmt->execute([$messageId]);

        $stmt = $this->pdo->prepare('DELETE FROM inbound_message_candidates WHERE message_id = ?');
        $stmt->execute([$messageId]);

        $stmt = $this->pdo->prepare('DELETE FROM inbound_message_attachments WHERE message_id = ?');
        $stmt->execute([$messageId]);

        $stmt = $this->pdo->prepare('DELETE FROM inbound_messages WHERE id = ?');
        $stmt->execute([$messageId]);

        return true;
    }

    // ── Attachments ─────────────────────────────────────────────────────

    public function addAttachment(
        int $messageId,
        int $fileId,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        string $contentHash
    ): int {
        return $this->insertAttachment($messageId, $fileId, $filename, $mimeType, $sizeBytes, $contentHash, null);
    }

    /**
     * Record an attachment that arrived but was **not kept**.
     *
     * The row exists precisely because the file does not. Without it the
     * screen shows one attachment fewer than the sender sent, and nobody
     * can tell whether ScoutMagic dropped it or the sender never attached
     * it — so it says instead: the message arrived, this file was not kept,
     * it is still in the original mailbox.
     *
     * @param string $reason one of AttachmentOmission's values
     */
    public function addOmittedAttachment(
        int $messageId,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        string $contentHash,
        string $reason
    ): int {
        return $this->insertAttachment($messageId, null, $filename, $mimeType, $sizeBytes, $contentHash, $reason);
    }

    private function insertAttachment(
        int $messageId,
        ?int $fileId,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        string $contentHash,
        ?string $omissionReason
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_message_attachments
                (message_id, file_id, filename_encrypted, mime_type, size_bytes, content_hash, omission_reason)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $messageId,
            $fileId,
            $this->encryption->encrypt($filename, 'inbound_message_attachments.filename'),
            $mimeType,
            $sizeBytes,
            $contentHash,
            $omissionReason,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The file already stored for these exact bytes **in this mailbox**, if
     * any — the same signature logo on ten messages is one file (§7.8).
     *
     * Per mailbox rather than per business object: now that the business
     * reference is no longer what a message is stored under, it is not what
     * a file can be deduplicated within either. The mailbox is the real
     * boundary — bytes never travel between two of the unit's boxes on
     * their own.
     */
    public function findFileIdByHash(int $mailboxId, string $contentHash): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.file_id
               FROM inbound_message_attachments a
               JOIN inbound_messages m ON a.message_id = m.id
              WHERE m.mailbox_id = ? AND a.content_hash = ? AND a.file_id IS NOT NULL
              LIMIT 1'
        );
        $stmt->execute([$mailboxId, $contentHash]);
        $fileId = $stmt->fetchColumn();

        return $fileId === false ? null : (int) $fileId;
    }

    /**
     * The file ids a message's attachments point at, so a caller deleting
     * the message can clean up the files nothing else references.
     *
     * @return int[]
     */
    public function findFileIdsForMessage(int $messageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT file_id FROM inbound_message_attachments WHERE message_id = ? AND file_id IS NOT NULL'
        );
        $stmt->execute([$messageId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return int[]
     */
    public function findFileIdsForReference(string $consumerId, string $businessReference): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.file_id
               FROM inbound_message_attachments a
               JOIN inbound_message_links l ON l.message_id = a.message_id
              WHERE l.consumer_id = ? AND l.business_reference = ? AND a.file_id IS NOT NULL'
        );
        $stmt->execute([$consumerId, $businessReference]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * A message that still holds this file, other than the one named.
     *
     * Deduplication means several messages can share one stored file while
     * `files.owner_id` names only one of them. When that one is destroyed,
     * the survivors need the ownership handed over — otherwise the file
     * keeps pointing at a message that no longer exists, and
     * `Service\InboundMessageAccessRegistry` finds no associations to ask
     * about, silently locking out the very people who may read it.
     *
     * @return int|null the message id, or null when nothing holds it any more
     */
    public function findMessageHoldingFile(int $fileId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT message_id FROM inbound_message_attachments
              WHERE file_id = ? ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$fileId]);
        $messageId = $stmt->fetchColumn();

        return $messageId === false ? null : (int) $messageId;
    }

    /**
     * Every (file, message) pair an attachment row records, oldest first —
     * what the one-time reprise of `files.owner_type`/`owner_id` walks.
     *
     * @return array<int, array{file_id: int, message_id: int}>
     */
    public function findAttachmentFileOwners(): array
    {
        $stmt = $this->pdo->query(
            'SELECT file_id, message_id FROM inbound_message_attachments
              WHERE file_id IS NOT NULL ORDER BY id ASC'
        );

        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn(array $row) => [
                'file_id' => (int) $row['file_id'],
                'message_id' => (int) $row['message_id'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * A file a consumer re-classified stops being the message's file.
     *
     * The attachment row stays — the message did carry that file and a
     * reader must still be told so — but it stops naming it, and it says
     * why (`AttachmentOmission::RECLASSIFIED`). That is what keeps the
     * retention purge honest: it deletes the stored files its own
     * attachment rows still point at, so a file that has become a document
     * of a booking or a stay is no longer among them and outlives the
     * message by exactly as long as that document does.
     *
     * Doing it the other way round — leaving the row pointing at the file
     * and teaching the purge about every module's document tables — would
     * put knowledge of the consumers back inside this module, which is the
     * one thing §8.58 does not allow.
     *
     * @return int the number of attachment rows released
     */
    public function releaseAttachmentFile(int $messageId, int $fileId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_message_attachments
                SET file_id = NULL, omission_reason = ?
              WHERE message_id = ? AND file_id = ?'
        );
        $stmt->execute([AttachmentOmission::RECLASSIFIED->value, $messageId, $fileId]);

        return $stmt->rowCount();
    }

    /**
     * How many stored attachments still point at a file — what tells a
     * caller whether deleting a message may also delete the file, or
     * whether another message deduplicated onto it.
     */
    public function countAttachmentsForFile(int $fileId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM inbound_message_attachments WHERE file_id = ?');
        $stmt->execute([$fileId]);

        return (int) $stmt->fetchColumn();
    }

    // ── The business triage list (§8.58, IT-07) ─────────────────────────

    /**
     * The mail one consumer's own users may sort: what it associated or
     * merely proposed on a reference they can reach, plus — on a mailbox
     * that consumer reads in full — everything else in that box.
     *
     * **Propositions are included, and that is the point.** A proposition
     * exists to be confirmed or dismissed by somebody who knows; a list
     * showing only what the module was already sure about would hide
     * exactly the messages that need a human.
     *
     * **The reference list comes from the caller**, because only the
     * consumer knows which of its own objects this requester may manage —
     * `inbound_mail` has no idea what a booking is (§8.58). An empty list
     * with no full-read mailbox therefore returns nothing, which is the
     * right answer for a manager who manages nothing.
     *
     * @param string[] $ownReferences the references the requester may reach
     * @param int[] $fullReadMailboxIds boxes this consumer reads entirely
     * @return InboundMessage[] newest first, bounded
     */
    public function findForTriage(
        string $consumerId,
        array $ownReferences,
        array $fullReadMailboxIds,
        int $limit
    ): array {
        $clauses = [];
        $params = [];

        if ($ownReferences !== []) {
            $placeholders = implode(',', array_fill(0, count($ownReferences), '?'));

            $clauses[] = "EXISTS (SELECT 1 FROM inbound_message_links l
                                   WHERE l.message_id = m.id AND l.consumer_id = ?
                                     AND l.business_reference IN ({$placeholders}))";
            $params[] = $consumerId;
            foreach ($ownReferences as $reference) {
                $params[] = $reference;
            }

            $clauses[] = "EXISTS (SELECT 1 FROM inbound_message_candidates c
                                   WHERE c.message_id = m.id AND c.consumer_id = ?
                                     AND c.dismissed_at IS NULL
                                     AND c.business_reference IN ({$placeholders}))";
            $params[] = $consumerId;
            foreach ($ownReferences as $reference) {
                $params[] = $reference;
            }
        }

        if ($fullReadMailboxIds !== []) {
            $placeholders = implode(',', array_fill(0, count($fullReadMailboxIds), '?'));
            $clauses[] = "m.mailbox_id IN ({$placeholders})";
            foreach ($fullReadMailboxIds as $mailboxId) {
                $params[] = (int) $mailboxId;
            }
        }

        if ($clauses === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM inbound_messages m
              WHERE (' . implode(' OR ', $clauses) . ')
           ORDER BY m.sent_at DESC, m.id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * One consumer's still-standing propositions on a set of messages, so a
     * triage screen can render them without querying inside its own loop.
     *
     * Scoped to the consumer: another module's propositions on the same
     * message are not this screen's business, and showing them would leak
     * one module's guesses into another's audience.
     *
     * @param int[] $messageIds
     * @return array<int, MessageCandidate[]>
     */
    public function findCandidatesForConsumer(array $messageIds, string $consumerId): array
    {
        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM inbound_message_candidates
              WHERE message_id IN ({$placeholders}) AND consumer_id = ? AND dismissed_at IS NULL
           ORDER BY id ASC"
        );
        $stmt->execute([...$messageIds, $consumerId]);

        $byMessage = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byMessage[(int) $row['message_id']][] = new MessageCandidate(
                businessReference: (string) $row['business_reference'],
                label: $this->encryption->decrypt(
                    (string) $row['evidence_label_encrypted'],
                    'inbound_message_candidates.evidence_label'
                ),
                evidenceType: (string) $row['evidence_type'],
                explanation: $this->encryption->decrypt(
                    (string) $row['evidence_explanation_encrypted'],
                    'inbound_message_candidates.evidence_explanation'
                ),
                attachmentId: (int) $row['attachment_id'],
                id: (int) $row['id'],
                consumerId: (string) $row['consumer_id']
            );
        }

        return $byMessage;
    }

    // ── The general mailbox (§8.58, IT-06) ──────────────────────────────

    /**
     * One page of the unit's whole mail, newest first.
     *
     * **Cursor pagination, never OFFSET** (A13). This table grows without
     * bound; `LIMIT 40 OFFSET 8000` makes MariaDB walk eight thousand rows
     * to throw them away, and the page a chef d'unité opens least often is
     * the one that gets slowest. The cursor is the pair `(sent_at, id)`
     * rather than `sent_at` alone, because two messages can share a second
     * — a mailing list delivering a batch does it routinely — and a cursor
     * on the timestamp alone would skip every message after the first of
     * that second, permanently and invisibly.
     *
     * **There is no full-text search here, deliberately** (D16). Searching
     * an encrypted column means either decrypting the whole table on every
     * keystroke or keeping a plaintext index of everything anybody ever
     * wrote to the unit. The filters below are all metadata, which is what
     * the blind indexes and the plain columns already allow.
     *
     * @param array{mailbox_id?: int|null, association?: string, include_bulk?: bool} $filters
     * @param array{sent_at: string, id: int}|null $after the last row of the previous page
     * @return InboundMessage[]
     */
    public function findPage(array $filters, ?array $after, int $limit): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (($filters['mailbox_id'] ?? null) !== null) {
            $where[] = 'm.mailbox_id = ?';
            $params[] = (int) $filters['mailbox_id'];
        }

        $association = (string) ($filters['association'] ?? 'all');
        if ($association === 'none') {
            // « Sans association » means nothing points at it AND nothing
            // has been proposed about it — a message with a proposition
            // waiting is not unattended, it is waiting for somebody.
            $where[] = 'NOT EXISTS (SELECT 1 FROM inbound_message_links l WHERE l.message_id = m.id)';
            $where[] = 'NOT EXISTS (SELECT 1 FROM inbound_message_candidates c
                                     WHERE c.message_id = m.id AND c.dismissed_at IS NULL)';
        } elseif ($association === 'some') {
            $where[] = 'EXISTS (SELECT 1 FROM inbound_message_links l WHERE l.message_id = m.id)';
        }

        if (($filters['include_bulk'] ?? false) !== true) {
            $where[] = 'm.is_bulk = 0';
        }

        if ($after !== null) {
            // Strictly "older than the last row shown", by the same pair the
            // ORDER BY uses, or the page boundary repeats or skips a row.
            $where[] = '(m.sent_at < ? OR (m.sent_at = ? AND m.id < ?))';
            $params[] = $after['sent_at'];
            $params[] = $after['sent_at'];
            $params[] = $after['id'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM inbound_messages m
              WHERE ' . implode(' AND ', $where) . '
           ORDER BY m.sent_at DESC, m.id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);

        return $this->hydrateAll($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * How many messages the box holds that nobody has looked at yet — the
     * figure the attention page shows.
     *
     * A count, never a listing: an attention point says how many and where
     * to go, never who wrote or what about (§7.9).
     */
    public function countUnassociated(bool $includeBulk = false): int
    {
        $sql = 'SELECT COUNT(*) FROM inbound_messages m
                 WHERE NOT EXISTS (SELECT 1 FROM inbound_message_links l WHERE l.message_id = m.id)
                   AND NOT EXISTS (SELECT 1 FROM inbound_message_candidates c
                                    WHERE c.message_id = m.id AND c.dismissed_at IS NULL)';
        if (!$includeBulk) {
            $sql .= ' AND m.is_bulk = 0';
        }

        $stmt = $this->pdo->query($sql);

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * Hydrate a set of rows read without a business reference — the
     * general mailbox's shape, where a message is not being looked at
     * through any one association.
     *
     * `consumerId` and `businessReference` on the result are the FIRST
     * association the message carries, or empty strings when it carries
     * none. They mean « l'angle par lequel on regarde », and here there is
     * no angle; `$links` is the honest answer and is always complete.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return InboundMessage[]
     */
    private function hydrateAll(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $row) => (int) $row['id'], $rows);
        $attachments = $this->findAttachmentsFor($ids);
        $links = $this->findLinksFor($ids);
        $omitted = $this->findOmittedAttachmentsFor($ids);

        return array_map(
            function (array $row) use ($attachments, $links, $omitted) {
                $id = (int) $row['id'];
                $own = $links[$id] ?? [];

                return $this->hydrate(
                    $row,
                    $attachments[$id] ?? [],
                    $own,
                    $own[0]->consumerId ?? '',
                    $own[0]->businessReference ?? '',
                    $omitted[$id] ?? []
                );
            },
            $rows
        );
    }

    /** How many messages the general list is hiding as automatic mail. */
    public function countBulk(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM inbound_messages WHERE is_bulk = 1');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * One message, whatever it is associated with — the general mailbox's
     * own read.
     *
     * Deliberately NOT on `Api\InboundMailInterface`: that contract is
     * scoped to one consumer and one business reference on every call, and
     * an unscoped read reachable from another module is exactly how a
     * manager's access to one booking becomes a window onto the unit's
     * whole mailbox (§7.11). This one stays here, behind a route the Chef
     * d'Unité alone can reach.
     */
    public function findAnyById(int $messageId): ?InboundMessage
    {
        return $this->findAnyForAnalysis($messageId);
    }

    // ── Retention and quota ─────────────────────────────────────────────

    /**
     * The messages that belong to nobody and that nobody has proposed
     * anything about, old enough to go.
     *
     * **Two clocks, and the later one wins** (A4):
     *
     * - `sent_at + retention` — the retention is measured on the message's
     *   own date, never on when its last association was removed. A message
     *   from 2024 that somebody detaches today does not thereby earn a
     *   fresh 90 days.
     * - `last_unlinked_at + 30 days` — but it does earn a floor. Detaching
     *   a three-year-old message by mistake must not make it disappear on
     *   the next nightly purge, with no window to notice.
     *
     * A proposition somebody set aside protects nothing (A3): `dismissed_at`
     * is a decision that this message is not that module's business, and
     * treating it as a reason to keep the message would make "écarter" mean
     * the opposite of what it says.
     *
     * @return int[] oldest first, bounded
     */
    public function findPurgeableMessageIds(\DateTimeImmutable $now, int $retentionDays, int $limit): array
    {
        $sentBefore = $now->modify('-' . max(0, $retentionDays) . ' days')->format('Y-m-d H:i:s');
        $unlinkedBefore = $now->modify('-' . self::UNLINK_GRACE_DAYS . ' days')->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'SELECT m.id
               FROM inbound_messages m
              WHERE m.sent_at < ?
                AND (m.last_unlinked_at IS NULL OR m.last_unlinked_at < ?)
                AND NOT EXISTS (SELECT 1 FROM inbound_message_links l WHERE l.message_id = m.id)
                AND NOT EXISTS (
                        SELECT 1 FROM inbound_message_candidates c
                         WHERE c.message_id = m.id AND c.dismissed_at IS NULL
                    )
           ORDER BY m.sent_at ASC, m.id ASC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$sentBefore, $unlinkedBefore]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The oldest messages nothing points at, whatever their age.
     *
     * The quota's emergency valve (D5), and deliberately NOT the same query
     * as the retention purge: this one ignores both clocks, because the
     * disk is full now and a message from last week that belongs to nobody
     * is a better thing to lose than the unit's ability to receive mail at
     * all.
     *
     * @return int[]
     */
    public function findOldestUnclaimedMessageIds(int $limit): array
    {
        $stmt = $this->pdo->query(
            'SELECT m.id
               FROM inbound_messages m
              WHERE NOT EXISTS (SELECT 1 FROM inbound_message_links l WHERE l.message_id = m.id)
                AND NOT EXISTS (
                        SELECT 1 FROM inbound_message_candidates c
                         WHERE c.message_id = m.id AND c.dismissed_at IS NULL
                    )
           ORDER BY m.sent_at ASC, m.id ASC
              LIMIT ' . max(1, $limit)
        );

        return $stmt === false ? [] : array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * How many bytes this module's attachments occupy.
     *
     * Summed over the ROWS that actually kept a file. Counting the omitted
     * ones would make a box that is refusing writes look ever fuller, and
     * the quota would never let go.
     *
     * DISTINCT on the file id: deduplication means several attachment rows
     * legitimately share one stored file, and counting it once per row
     * would inflate the figure until the quota fired on space nobody uses.
     */
    /**
     * How many messages each mailbox holds — the figure the configuration
     * screen shows next to each box.
     *
     * A count per box, never a listing: this repository deliberately offers
     * no unscoped read of message content (§7.11), and a superadmin screen
     * about hosts and ports is not where that would start.
     *
     * @return array<int, int> keyed by mailbox id
     */
    public function countByMailbox(): array
    {
        $stmt = $this->pdo->query(
            'SELECT mailbox_id, COUNT(*) AS total FROM inbound_messages GROUP BY mailbox_id'
        );

        if ($stmt === false) {
            return [];
        }

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['mailbox_id']] = (int) $row['total'];
        }

        return $counts;
    }

    public function totalStoredBytes(): int
    {
        $stmt = $this->pdo->query(
            'SELECT COALESCE(SUM(size_bytes), 0) FROM (
                 SELECT DISTINCT file_id, size_bytes
                   FROM inbound_message_attachments
                  WHERE file_id IS NOT NULL
             ) AS kept'
        );

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    // ── One-time reprise ────────────────────────────────────────────────

    /**
     * Drop the associations that pointed at a **reserved reference**
     * rather than at a real business object.
     *
     * Camps had one: `unsorted`, a bucket masquerading as a stay. Removing
     * the rows is the whole migration — the messages themselves stay
     * exactly where they are, become "nothing points at this", and fall
     * under the module's own retention, which is what they should have
     * been under all along. Nothing is deleted here.
     *
     * Idempotent: a second run finds no rows and reports 0.
     *
     * @return int the number of associations removed
     */
    public function dropReservedReference(string $consumerId, string $reference): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM inbound_message_links WHERE consumer_id = ? AND business_reference = ?'
        );
        $stmt->execute([$consumerId, $reference]);

        return $stmt->rowCount();
    }

    /**
     * Turn every message's legacy `consumer_id`/`business_reference`/
     * `link_origin` triplet into a row of `inbound_message_links`.
     *
     * Runs once per installation, guarded by a setting in the composition
     * root — the `member_section_periods_backfilled` shape. Idempotent
     * regardless: `addLink()` refuses to create an association that already
     * exists, so a second run writes nothing and reports 0.
     *
     * Returns -1 when the legacy columns are already gone, which is both a
     * fresh installation and an installation that has passed the release
     * dropping them. Neither is an error and neither has anything to do.
     */
    public function backfillLinks(): int
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT id, consumer_id, business_reference, link_origin FROM inbound_messages
                  WHERE consumer_id IS NOT NULL AND consumer_id <> \'\''
            );
        } catch (\PDOException) {
            return -1;
        }

        if ($stmt === false) {
            return -1;
        }

        $created = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $origin = LinkOrigin::tryFrom((string) $row['link_origin']);
            if ($origin === null) {
                // An origin this build no longer knows. The association is
                // real and must survive; only the label for how it was
                // decided is lost, and 'sender' is the honest floor —
                // never presented as certain.
                $origin = LinkOrigin::SENDER;
            }

            if ($this->addLink(
                (int) $row['id'],
                (string) $row['consumer_id'],
                (string) $row['business_reference'],
                $origin
            )) {
                $created++;
            }
        }

        return $created;
    }

    // ── Hydration ───────────────────────────────────────────────────────

    /**
     * @param int[] $messageIds
     * @return array<int, InboundAttachment[]>
     */
    private function findAttachmentsFor(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM inbound_message_attachments WHERE message_id IN ({$placeholders}) ORDER BY id ASC"
        );
        $stmt->execute($messageIds);

        $byMessage = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // An omitted attachment has no file to point at, so it is not
            // an InboundAttachment at all — see findOmittedAttachmentsFor().
            if ($row['file_id'] === null || $row['omission_reason'] !== null) {
                continue;
            }

            $byMessage[(int) $row['message_id']][] = new InboundAttachment(
                id: (int) $row['id'],
                messageId: (int) $row['message_id'],
                fileId: (int) $row['file_id'],
                filename: $this->encryption->decrypt(
                    (string) $row['filename_encrypted'],
                    'inbound_message_attachments.filename'
                ),
                mimeType: (string) $row['mime_type'],
                sizeBytes: (int) $row['size_bytes'],
                contentHash: (string) $row['content_hash']
            );
        }

        return $byMessage;
    }

    /**
     * @param int[] $messageIds
     * @return array<int, OmittedAttachment[]>
     */
    private function findOmittedAttachmentsFor(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM inbound_message_attachments
              WHERE message_id IN ({$placeholders}) AND omission_reason IS NOT NULL
           ORDER BY id ASC"
        );
        $stmt->execute($messageIds);

        $byMessage = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $reason = AttachmentOmission::tryFrom((string) $row['omission_reason']);
            if ($reason === null) {
                // A reason this build no longer knows. The honest fallback
                // is the one that says least: something went wrong at
                // write time.
                $reason = AttachmentOmission::STORAGE_ERROR;
            }

            $byMessage[(int) $row['message_id']][] = new OmittedAttachment(
                id: (int) $row['id'],
                messageId: (int) $row['message_id'],
                filename: $this->encryption->decrypt(
                    (string) $row['filename_encrypted'],
                    'inbound_message_attachments.filename'
                ),
                mimeType: (string) $row['mime_type'],
                sizeBytes: (int) $row['size_bytes'],
                reason: $reason
            );
        }

        return $byMessage;
    }

    /**
     * @param int[] $messageIds
     * @return array<int, MessageLink[]>
     */
    private function findLinksFor(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM inbound_message_links WHERE message_id IN ({$placeholders}) ORDER BY id ASC"
        );
        $stmt->execute($messageIds);

        $byMessage = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byMessage[(int) $row['message_id']][] = new MessageLink(
                consumerId: (string) $row['consumer_id'],
                businessReference: (string) $row['business_reference'],
                origin: LinkOrigin::tryFrom((string) $row['link_origin']) ?? LinkOrigin::SENDER,
                attachmentId: (int) $row['attachment_id'],
                createdByUserAccountId: $row['created_by_user_account_id'] !== null
                    ? (int) $row['created_by_user_account_id']
                    : null,
                createdAt: DateInput::fromStorage((string) $row['created_at'])
            );
        }

        return $byMessage;
    }

    /**
     * A Message-ID is not personal data the way an address is, but it can
     * carry one — plenty of servers build it from the sender's local part —
     * so it is indexed through the same keyed blind index as everything
     * else rather than a bare hash.
     */
    private function messageIdIndex(string $messageId): string
    {
        return $this->encryption->blindIndex(strtolower(trim($messageId, "<> \t")), 'inbound_message_id');
    }

    /**
     * @param array<string, mixed> $row
     * @param InboundAttachment[] $attachments
     * @param MessageLink[] $links
     * @param OmittedAttachment[] $omittedAttachments
     */
    private function hydrate(
        array $row,
        array $attachments,
        array $links,
        string $consumerId,
        string $businessReference,
        array $omittedAttachments = []
    ): InboundMessage {
        $toEmails = [];
        if ($row['to_emails_encrypted'] !== null) {
            $toEmails = array_values(array_filter(explode(
                "\n",
                $this->encryption->decrypt((string) $row['to_emails_encrypted'], 'inbound_messages.to_emails')
            ), static fn(string $email) => $email !== ''));
        }

        return new InboundMessage(
            id: (int) $row['id'],
            mailboxId: (int) $row['mailbox_id'],
            consumerId: $consumerId,
            businessReference: $businessReference,
            linkOrigin: $this->scopedOrigin($links, $consumerId, $businessReference),
            subject: $this->encryption->decrypt((string) $row['subject_encrypted'], 'inbound_messages.subject'),
            fromEmail: $this->encryption->decrypt((string) $row['from_email_encrypted'], 'inbound_messages.from_email'),
            fromName: $row['from_name_encrypted'] !== null
                ? $this->encryption->decrypt((string) $row['from_name_encrypted'], 'inbound_messages.from_name')
                : null,
            messageId: $this->encryption->decrypt((string) $row['message_id_encrypted'], 'inbound_messages.message_id'),
            inReplyTo: $row['in_reply_to_encrypted'] !== null
                ? $this->encryption->decrypt((string) $row['in_reply_to_encrypted'], 'inbound_messages.in_reply_to')
                : null,
            sentAt: DateInput::requireFromStorage((string) $row['sent_at'], 'sent_at'),
            bodyText: $this->encryption->decrypt((string) $row['body_text_encrypted'], 'inbound_messages.body_text'),
            bodyHtml: $this->encryption->decrypt((string) $row['body_html_encrypted'], 'inbound_messages.body_html'),
            toEmails: $toEmails,
            attachments: $attachments,
            links: $links,
            omittedAttachments: $omittedAttachments,
            rawHeaders: ($row['raw_headers_encrypted'] ?? null) !== null
                ? $this->encryption->decrypt((string) $row['raw_headers_encrypted'], 'inbound_messages.raw_headers')
                : null
        );
    }

    /**
     * How the association this read was scoped to was decided. A message
     * carrying several says nothing about the others here — that is what
     * `$links` is for.
     *
     * @param MessageLink[] $links
     */
    private function scopedOrigin(array $links, string $consumerId, string $businessReference): LinkOrigin
    {
        foreach ($links as $link) {
            if ($link->consumerId === $consumerId && $link->businessReference === $businessReference) {
                return $link->origin;
            }
        }

        return LinkOrigin::SENDER;
    }
}
