<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\File\UploadException;
use Core\File\UploadHandler;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Client\FetchedMessage;
use Modules\InboundMail\Client\IncomingMailboxClientInterface;
use Modules\InboundMail\Client\MailboxConnectionException;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;

/**
 * Reading every enabled mailbox and keeping what somebody claimed.
 *
 * The whole shape of this class follows from two rules that pull in
 * opposite directions:
 *
 * - **Nothing unclaimed is ever written** (§7.6). A message no consumer
 *   recognises is dropped: not stored, not queued, not notified. Keeping it
 *   "in case" would build an archive of the unit's mailbox with no screen
 *   to read it — the worst possible position under the RGPD.
 * - **The cursor still advances past it.** Otherwise the same unclaimed
 *   message is fetched, parsed and discarded on every run forever, and a
 *   mailbox with one unrecognised newsletter at the top never gets past it.
 *
 * So the cursor moves on the UID actually seen, whatever was decided about
 * the message. That is also why a UIDVALIDITY change is safe to handle by
 * re-reading a folder from zero: the message-level deduplication is on
 * Message-ID **within the mailbox**, so a re-read recognises what it
 * already has whatever the message has since been associated with.
 *
 * A stored message and the business objects it belongs to are two separate
 * writes: `create()` puts the message down, `addLink()` associates it. A
 * message already in the box therefore never gets written twice — at most
 * it gains an association it did not have.
 */
class MailboxSyncService
{
    /**
     * How many messages one run pulls from one folder.
     *
     * Bounded because a first sync against a five-year-old mailbox would
     * otherwise try to pull five years inside one request that a shared
     * host's max_execution_time kills halfway — leaving the cursor
     * unmoved and the identical doomed attempt repeating on every tick.
     * The task simply comes back for the rest.
     */
    public const BATCH_SIZE = 50;

    public function __construct(
        private InboundMailboxRepository $mailboxRepository,
        private InboundMessageRepository $messageRepository,
        private MessageConsumerRegistry $consumerRegistry,
        private MessageContentSanitizer $sanitizer,
        private AttachmentPolicy $attachmentPolicy,
        private MailboxErrorFormatter $errorFormatter,
        private MailboxClientFactory $clientFactory,
        private AnalysisResultApplier $applier,
        private ?UploadHandler $uploadHandler = null
    ) {
    }

    /**
     * Poll every enabled mailbox.
     *
     * @return SyncReport one entry per mailbox, whatever happened to it
     */
    public function syncAll(\DateTimeImmutable $now): SyncReport
    {
        $report = new SyncReport();

        foreach ($this->mailboxRepository->findEnabled() as $mailbox) {
            $report->add($mailbox->id, $this->syncMailbox($mailbox, $now));
        }

        return $report;
    }

    /**
     * Poll one mailbox, recording success or failure on it either way.
     *
     * A failure here is never silent: it lands on the mailbox row, where
     * the configuration page shows it (§7.4). A box that quietly stopped
     * collecting is worse than one that says it is broken.
     */
    public function syncMailbox(Mailbox $mailbox, \DateTimeImmutable $now): SyncOutcome
    {
        if (!$this->consumerRegistry->hasConsumers()) {
            // Nothing would claim anything, so every message would be read
            // and dropped. Not an error — just nothing worth connecting for.
            return SyncOutcome::skipped('Aucun module ne consomme le courrier entrant.');
        }

        $credentials = $this->mailboxRepository->findCredentials($mailbox->id);
        if ($credentials === null) {
            return SyncOutcome::skipped('Identifiants introuvables.');
        }

        $client = $this->clientFactory->forMailbox($mailbox);
        $stored = 0;
        $seen = 0;

        try {
            $client->connect($mailbox, $credentials);

            foreach ($mailbox->watchedFolders() as $folder) {
                $folderState = $client->folderState($folder);
                $cursor = $this->mailboxRepository
                    ->findCursor($mailbox->id, $folder)
                    ->forUidValidity($folderState->uidValidity);

                foreach ($client->fetchSince($folder, $cursor->lastUid, self::BATCH_SIZE) as $message) {
                    $seen++;
                    if ($this->store($mailbox, $message)) {
                        $stored++;
                    }

                    // Advanced for every message, claimed or not — see the
                    // class docblock.
                    $cursor = $cursor->advancedTo($message->uid);
                }

                $this->mailboxRepository->saveCursor($cursor);
            }
        } catch (MailboxConnectionException | \RuntimeException $e) {
            $reason = $this->errorFormatter->format($e);
            $this->mailboxRepository->recordFailure($mailbox->id, $reason, $now);
            $client->disconnect();

            return SyncOutcome::failed($reason);
        }

        $client->disconnect();
        $this->mailboxRepository->recordSuccess($mailbox->id, $now);

        return SyncOutcome::succeeded($seen, $stored);
    }

    /**
     * Offer a message to every consumer and store it if anybody made
     * something of it.
     *
     * @return bool whether anything was written
     */
    private function store(Mailbox $mailbox, FetchedMessage $message): bool
    {
        $candidate = new CandidateMessage(
            mailboxId: $mailbox->id,
            subject: $message->subject,
            fromEmail: $message->fromEmail,
            fromName: $message->fromName,
            messageId: $message->messageId,
            inReplyTo: $message->inReplyTo,
            references: $message->references,
            toEmails: $message->toEmails,
            sentAt: $message->sentAt,
            // Sanitised before a consumer ever reads it: looking for a
            // reference in a body must not be where raw attacker HTML is
            // first handled (§7.9).
            bodyText: $this->sanitizer->sanitizeText($message->bodyText),
            bodyHtml: $this->sanitizer->sanitizeHtml($message->bodyHtml)
        );

        // EVERY consumer is asked, and every answer is applied. Under the
        // old first-claim-wins rule the second module to recognise a
        // message was never even asked, so an email that is both a
        // booking's correspondence and an invoice could only ever be one
        // of the two.
        $results = $this->consumerRegistry->analyzeAll($candidate);
        if ($results === []) {
            return false;
        }

        // The message may already be in this box — after a UIDVALIDITY
        // reset made the folder be re-read, or because it arrived in two
        // watched folders. The message is written once; only the
        // associations may be new, and applying them is idempotent.
        $existingId = $message->messageId !== ''
            ? $this->messageRepository->findIdByMessageId($mailbox->id, $message->messageId)
            : null;

        if ($existingId !== null) {
            $this->notifyLinked($existingId, $this->applier->apply($existingId, $results));

            // Nothing was *stored*: the message was already here, and its
            // attachments with it.
            return false;
        }

        $storedId = $this->messageRepository->create(
            mailboxId: $mailbox->id,
            folder: $message->folder,
            uidValidity: 0,
            imapUid: $message->uid,
            messageId: $message->messageId,
            inReplyTo: $message->inReplyTo,
            subject: $message->subject,
            fromEmail: $message->fromEmail,
            fromName: $message->fromName,
            bodyText: $candidate->bodyText,
            bodyHtml: $candidate->bodyHtml,
            sentAt: $message->sentAt,
            toEmails: $message->toEmails
        );

        $created = $this->applier->apply($storedId, $results);

        // Attachments before the callbacks, deliberately: a consumer's
        // `onLinked()` is where it turns them into documents of its own,
        // and it reads them off the stored message.
        $this->storeAttachments($message, $storedId, $mailbox->id, $candidate->bodyHtml);

        $this->notifyLinked($storedId, $created);

        return true;
    }

    /**
     * Tell each consumer about the associations that were actually created.
     *
     * @param array<int, array{consumerId: string, link: MessageLink}> $created
     */
    private function notifyLinked(int $messageId, array $created): void
    {
        foreach ($created as $entry) {
            $consumer = $this->consumerRegistry->find($entry['consumerId']);
            if ($consumer === null) {
                continue;
            }

            $this->notifyConsumer($consumer, $messageId, $entry['link']);
        }
    }

    /**
     * Hand the stored message back to the consumer whose association was
     * just created, so it can do its own bookkeeping — turning attachments into documents, for
     * instance (§7.8).
     *
     * Deliberately after the write, and deliberately unable to fail the
     * run: the message is already stored, and one module's bookkeeping
     * throwing must not cost the unit the rest of its mail. Nothing about
     * the failure is logged either, since anything identifying enough to be
     * useful would be personal data in the journal (§7.9).
     */
    private function notifyConsumer(
        MessageConsumerInterface $consumer,
        int $messageId,
        MessageLink $link
    ): void {
        $stored = $this->messageRepository->findOneForReference(
            $link->consumerId,
            $link->businessReference,
            $messageId
        );
        if ($stored === null) {
            return;
        }

        try {
            $consumer->onLinked($stored, $link);
        } catch (\Throwable) {
            // See the docblock: swallowed on purpose, and silently.
        }
    }

    private function storeAttachments(
        FetchedMessage $message,
        int $messageId,
        int $mailboxId,
        string $sanitizedHtml
    ): void {
        if ($this->uploadHandler === null || $message->attachments === []) {
            return;
        }

        foreach ($message->attachments as $attachment) {
            if (!$this->attachmentPolicy->accepts($attachment, $sanitizedHtml)) {
                continue;
            }

            $hash = $attachment->contentHash();

            // The same bytes already stored in this box: record the second
            // reference to the one file rather than writing it twice
            // (§7.8). Per mailbox, since the business reference is no
            // longer what a message is stored under.
            $existingFileId = $this->messageRepository->findFileIdByHash($mailboxId, $hash);
            if ($existingFileId !== null) {
                $this->messageRepository->addAttachment(
                    $messageId,
                    $existingFileId,
                    $attachment->filename,
                    (string) $this->attachmentPolicy->detectMimeType($attachment->bytes),
                    $attachment->sizeBytes(),
                    $hash
                );
                continue;
            }

            $fileId = $this->writeAttachment($attachment->bytes, $attachment->filename, $messageId);
            if ($fileId === null) {
                continue;
            }

            $this->messageRepository->addAttachment(
                $messageId,
                $fileId,
                $attachment->filename,
                (string) $this->attachmentPolicy->detectMimeType($attachment->bytes),
                $attachment->sizeBytes(),
                $hash
            );
        }
    }

    /**
     * Through `UploadHandler` rather than writing the file here.
     *
     * It is not an upload, but everything that makes an upload safe applies
     * verbatim: the type is sniffed from the bytes, the stored name is
     * generated rather than taken from the sender, EXIF is stripped from
     * images — an attachment photographed inside somebody's home carries
     * GPS coordinates — the file lands outside `public/`, and the row it
     * creates is what `FileAccessGuard` later checks. Reimplementing that
     * here would mean maintaining a second copy of it (§7.9).
     *
     * **The file is owned by the message**, which is what actually
     * partitions it. `role_min = 'intendant'` is a floor, not a lock: on
     * its own it let any intendant read any attachment of any watched box
     * by walking `/files/{id}`. The `inbound_message` ownership sends
     * `FileAccessGuard` to `Service\InboundMessageAccessRegistry`, which
     * asks the consumers actually associated with the message.
     */
    private function writeAttachment(string $bytes, string $filename, int $messageId): ?int
    {
        $temporary = tempnam(sys_get_temp_dir(), 'inbound-mail-');
        if ($temporary === false) {
            return null;
        }

        try {
            if (file_put_contents($temporary, $bytes) === false) {
                return null;
            }

            return $this->uploadHandler?->handle(
                [
                    'tmp_name' => $temporary,
                    'name' => $filename,
                    'size' => strlen($bytes),
                    'error' => UPLOAD_ERR_OK,
                ],
                'inbound_mail/attachments',
                $this->attachmentPolicy->allowedMimeTypes(),
                $this->attachmentPolicy->maxSizeBytes(),
                // Never public, and never the renter's: an attachment is
                // internal until a consumer decides otherwise (§7.8).
                'intendant',
                'inbound_mail',
                // No author: a synchronisation is nobody's session.
                null,
                InboundMessageAccessRegistry::OWNER_TYPE,
                $messageId
            );
        } catch (UploadException) {
            // A rejected attachment is a dropped attachment, never a failed
            // synchronisation: the message itself is already stored, and
            // one unusable file must not cost the unit the mail around it.
            return null;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
