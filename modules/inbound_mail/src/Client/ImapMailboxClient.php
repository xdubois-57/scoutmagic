<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * IMAP over `webklex/php-imap` — a pure-PHP client that talks to the server
 * over sockets.
 *
 * **Why a library and not `ext-imap`**: the extension was removed from
 * PHP's core in 8.4 and moved to PECL, which puts it out of reach of
 * essentially every shared host ScoutMagic targets (§7.3). See
 * ARCHITECTURE.md §1 for the dependency's entry.
 *
 * **Nothing here writes to the remote mailbox** (§7.5). Bodies are fetched
 * with `FT_PEEK` via `leaveUnread()`, so reading a message does not mark it
 * `\Seen` — a unit whose treasurer works through an unread inbox must not
 * find it emptied by a background task. `SETS_NOTHING` below pins the
 * consequence in a form a test can assert.
 */
class ImapMailboxClient implements IncomingMailboxClientInterface
{
    /**
     * The fetch option every body read here uses. Named rather than
     * inlined so a test can assert on it: the difference between this and
     * a plain fetch is invisible in a diff and catastrophic in production.
     */
    private const PEEK_ONLY = IMAP::FT_PEEK;

    private ?Client $client = null;

    public function __construct(private ?ClientManager $clientManager = null)
    {
    }

    public function connect(Mailbox $mailbox, MailboxCredentials $credentials): void
    {
        $manager = $this->clientManager ?? new ClientManager();

        try {
            $client = $manager->make([
                'host' => $mailbox->host,
                'port' => $mailbox->port,
                'protocol' => 'imap',
                'encryption' => $mailbox->encryption === '' ? 'ssl' : $mailbox->encryption,
                // **Never negotiable.** An invalid certificate is a failed
                // connection, not a warning to step over: the whole point
                // of reading somebody's mailbox over TLS is that nobody
                // else is reading it too (§7.5).
                'validate_cert' => true,
                'username' => $credentials->username,
                'password' => $credentials->password,
                'timeout' => 30,
                'options' => [
                    'fetch' => self::PEEK_ONLY,
                    'fetch_body' => true,
                    // Flags are not read either: this module has no use for
                    // them, and asking for them on some servers is what
                    // implicitly opens a folder read-write.
                    'fetch_flags' => false,
                ],
            ]);

            $client->connect();
        } catch (\Throwable $e) {
            throw new MailboxConnectionException(self::safeReason($e), 0, $e);
        }

        $this->client = $client;
    }

    public function disconnect(): void
    {
        $this->client?->disconnect();
        $this->client = null;
    }

    /**
     * @return string[]
     */
    public function listFolders(): array
    {
        $client = $this->requireClient();

        try {
            $paths = [];
            foreach ($client->getFolders(false) as $folder) {
                if ($folder instanceof Folder) {
                    $paths[] = $folder->path;
                }
            }

            return $paths;
        } catch (\Throwable $e) {
            throw new MailboxConnectionException(self::safeReason($e), 0, $e);
        }
    }

    public function folderState(string $folder): FolderState
    {
        $imapFolder = $this->requireFolder($folder);

        try {
            // EXAMINE, never SELECT: the read-only form of opening a
            // folder. Some servers set \Recent on SELECT.
            $status = $imapFolder->examine();
        } catch (\Throwable $e) {
            throw new MailboxConnectionException(self::safeReason($e), 0, $e);
        }

        return new FolderState(
            $folder,
            (int) ($status['uidvalidity'] ?? 0),
            (int) ($status['uidnext'] ?? 1) - 1
        );
    }

    /**
     * @return FetchedMessage[]
     */
    public function fetchSince(string $folder, int $sinceUid, int $limit): array
    {
        $imapFolder = $this->requireFolder($folder);

        try {
            // "everything above the cursor" as a server-side UID range,
            // rather than listing the folder and filtering here — the
            // difference on a mailbox with 40 000 messages is the whole
            // sync.
            $messages = $imapFolder->query()
                ->leaveUnread()
                ->whereUid(($sinceUid + 1) . ':*')
                ->setFetchOrderAsc()
                ->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            throw new MailboxConnectionException(self::safeReason($e), 0, $e);
        }

        $fetched = [];
        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                continue;
            }

            $uid = (int) $message->getUid();
            // A UID range starting at `n+1` is inclusive of `n+1`, but a
            // server that ignores the range (or a `*` that resolves to the
            // last message when the folder has none above the cursor) can
            // still hand back the cursor message itself.
            if ($uid <= $sinceUid) {
                continue;
            }

            $fetched[] = $this->toFetchedMessage($message, $folder, $uid);
        }

        return $fetched;
    }

    private function toFetchedMessage(Message $message, string $folder, int $uid): FetchedMessage
    {
        $from = $message->getFrom()->first();
        $toEmails = [];
        foreach ($message->getTo()->toArray() as $recipient) {
            $mail = is_object($recipient) ? ($recipient->mail ?? null) : null;
            if (is_string($mail) && $mail !== '') {
                $toEmails[] = strtolower($mail);
            }
        }

        return new FetchedMessage(
            uid: $uid,
            folder: $folder,
            messageId: (string) $message->getMessageId()->first(),
            subject: (string) $message->getSubject()->first(),
            fromEmail: strtolower((string) (is_object($from) ? ($from->mail ?? '') : '')),
            fromName: self::nonEmptyOrNull(is_object($from) ? (string) ($from->personal ?? '') : ''),
            toEmails: $toEmails,
            inReplyTo: self::nonEmptyOrNull((string) $message->getInReplyTo()->first()),
            references: self::splitReferences((string) $message->getReferences()->first()),
            sentAt: self::sentAt($message),
            bodyText: $message->getTextBody(),
            bodyHtml: $message->getHTMLBody(),
            attachments: $this->toFetchedAttachments($message)
        );
    }

    /**
     * @return FetchedAttachment[]
     */
    private function toFetchedAttachments(Message $message): array
    {
        $attachments = [];

        foreach ($message->getAttachments() as $attachment) {
            if (!$attachment instanceof Attachment) {
                continue;
            }

            $content = $attachment->content;
            if ($content === '') {
                continue;
            }

            $attachments[] = new FetchedAttachment(
                filename: (string) ($attachment->name ?? 'piece-jointe'),
                declaredMimeType: (string) ($attachment->getMimeType() ?? 'application/octet-stream'),
                bytes: $content,
                isInline: strtolower((string) ($attachment->disposition ?? '')) === 'inline',
                contentId: self::nonEmptyOrNull((string) ($attachment->id ?? ''))
            );
        }

        return $attachments;
    }

    private static function sentAt(Message $message): \DateTimeImmutable
    {
        $date = $message->getDate()->first();
        if (is_object($date) && method_exists($date, 'format')) {
            return new \DateTimeImmutable((string) $date->format('Y-m-d H:i:s'));
        }

        // A message with no parseable Date: is still a message. Dropping it
        // would lose mail over a malformed header somebody else wrote.
        return new \DateTimeImmutable('now');
    }

    /**
     * @return string[]
     */
    private static function splitReferences(string $references): array
    {
        if (trim($references) === '') {
            return [];
        }

        $parts = preg_split('/\s+/', trim($references)) ?: [];

        return array_values(array_filter($parts, static fn(string $part) => $part !== ''));
    }

    private static function nonEmptyOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function requireClient(): Client
    {
        if ($this->client === null) {
            throw new MailboxConnectionException('La boîte n\'est pas connectée.');
        }

        return $this->client;
    }

    private function requireFolder(string $folder): Folder
    {
        try {
            $imapFolder = $this->requireClient()->getFolderByPath($folder);
        } catch (MailboxConnectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MailboxConnectionException(self::safeReason($e), 0, $e);
        }

        if ($imapFolder === null) {
            throw new MailboxConnectionException('Le dossier demandé est introuvable sur le serveur.');
        }

        return $imapFolder;
    }

    /**
     * The exception's class, and nothing it wrote.
     *
     * A library's own message routinely contains the account name and, on
     * some servers, the server's verbatim rejection of the credential that
     * was just tried — neither of which belongs in a database column a page
     * renders (§7.9). `MailboxErrorFormatter` turns this into the sentence
     * the superadmin actually reads.
     */
    private static function safeReason(\Throwable $e): string
    {
        $class = $e::class;
        $short = substr($class, (int) strrpos($class, '\\') + 1);

        return $short;
    }
}
