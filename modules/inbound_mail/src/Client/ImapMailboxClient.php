<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

use Core\Service\DateInput;
use Illuminate\Support\Collection;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Modules\InboundMail\Mime\MimeMessageParser;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Header;
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
 * **Nothing here writes to the remote mailbox** (§7.5), with exactly one
 * named exception below. Bodies are fetched with `FT_PEEK` via
 * `leaveUnread()`, so reading a message does not mark it `\Seen` — a unit
 * whose treasurer works through an unread inbox must not find it emptied
 * by a background task. `SETS_NOTHING` below pins the consequence in a
 * form a test can assert.
 *
 * **The exception is `deleteMessage()`**, and it is declared through a
 * second interface rather than added to the reading one
 * ({@see PruningMailboxClientInterface} for why). It removes a message
 * only when a consumer has declared
 * {@see \Modules\InboundMail\Api\PruningConsumerInterface} AND answered
 * yes about that message — two locks, of which this class is one.
 * `MailboxSyncService` is what holds the other, and no path here reaches
 * this method on its own.
 */
class ImapMailboxClient implements IncomingMailboxClientInterface, PruningMailboxClientInterface
{
    private ?Client $client = null;

    /**
     * How many UIDs one `UID FETCH (UID INTERNALDATE)` names. A first read
     * may weigh up thousands of them, and a command line of a few thousand
     * numbers is past what some servers accept (RFC 7162 §4 recommends
     * clients stay under 8 192 octets).
     */
    private const INTERNALDATE_SLICE = 500;

    /** See `IncomingMailboxClientInterface::lastSkippedUid()`. */
    private int $lastSkippedUid = 0;

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
                // No `options` here (#1248): the library reads its
                // `options.*` from its global configuration, never from an
                // account, so `fetch => FT_PEEK` and the rest were set and
                // ignored. How a message is read — EXAMINE, BODY.PEEK, no
                // flags — is decided in TolerantMessageQuery, which issues
                // the commands itself.
            ]);

            $client->connect();
        } catch (\Throwable $e) {
            throw MailboxConnectionException::at(MailboxConnectionException::STEP_CONNECT, $e);
        }

        $this->client = $client;
    }

    public function disconnect(): void
    {
        $this->client?->disconnect();
        $this->client = null;
    }

    /**
     * The raw LIST, rather than `getFolders()`: the library builds its
     * `Folder` objects from the same answer but keeps only the attributes
     * it knows, and `\Sent` (RFC 6154) is not one of them. Same command,
     * same read — only nothing is thrown away.
     *
     * @return RemoteFolder[]
     */
    public function listFolders(): array
    {
        $client = $this->requireClient();

        try {
            $listing = $client->getConnection()->folders('', '*')->validatedData();

            return RemoteFolder::fromListing(is_array($listing) ? $listing : []);
        } catch (\Throwable $e) {
            throw MailboxConnectionException::at(MailboxConnectionException::STEP_LIST, $e);
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
            throw MailboxConnectionException::at(MailboxConnectionException::STEP_OPEN_FOLDER, $e);
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
        $this->lastSkippedUid = 0;

        $imapFolder = $this->requireFolder($folder);

        try {
            // "everything above the cursor" as a server-side UID range,
            // rather than listing the folder and filtering here — the
            // difference on a mailbox with 40 000 messages is the whole
            // sync.
            //
            // Through TolerantMessageQuery rather than `query()`: the
            // library parses every message inside `get()`, and one it
            // cannot parse (an unreadable or doubled `Date:`) used to fail
            // the whole batch here, as a connection problem — the cursor
            // never moved and the box was stuck behind that message for
            // good. What still fails here is the server or the network.
            $query = TolerantMessageQuery::on($imapFolder);
            $messages = $query
                ->leaveUnread()
                ->whereUid(($sinceUid + 1) . ':*')
                ->setFetchOrderAsc()
                ->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            throw MailboxConnectionException::at(MailboxConnectionException::STEP_FETCH, $e);
        }

        // Above the cursor only: the cursor message a `*` can hand back
        // was already read, and traced, by an earlier pass. A UID range
        // starting at `n+1` is inclusive of `n+1`, but a server that
        // ignores the range (or a `*` that resolves to the last message
        // when the folder has none above the cursor) can still hand back
        // the cursor message itself.
        [$fetched, $skipped] = $this->convert(
            $folder,
            $query,
            $messages,
            static fn(int $uid): bool => $uid > $sinceUid
        );
        $this->lastSkippedUid = $skipped === [] ? 0 : max($skipped);

        return $fetched;
    }

    /**
     * Which messages a first read takes (D11), asked of the server without
     * reading one of them.
     *
     * The folder is EXAMINEd (read-only), `UID SEARCH SINCE` names what it
     * received in the window — SINCE compares the server's reception date,
     * not the `Date:` a sender wrote — and only when that is more than
     * $limit are the reception dates fetched, `UID FETCH (UID INTERNALDATE)`
     * in slices short enough for any server's command line, to keep the most
     * recent. No body, no header, no flag: nothing a mail client open at the
     * same moment could notice.
     */
    public function firstReadSelection(string $folder, \DateTimeImmutable $since, int $limit): FirstReadSelection
    {
        $imapFolder = $this->requireFolder($folder);

        try {
            $status = $imapFolder->examine();
            $imapFolder->getClient()->setActiveFolder($imapFolder->path);
            $connection = $imapFolder->getClient()->getConnection();

            // A server that leaves UIDNEXT out is asked for the folder's
            // UIDs instead: 0 here would retain nothing and start the
            // cursor at the bottom of the folder, the whole history.
            $highestUid = isset($status['uidnext'])
                ? (int) $status['uidnext'] - 1
                : self::highestUidIn($connection->search(['ALL'], IMAP::ST_UID)->validatedData());
            $found = $connection->search(['SINCE', $since->format('j-M-Y')], IMAP::ST_UID)->validatedData();
            $uids = array_values(array_filter(
                array_map('intval', is_array($found) ? $found : []),
                static fn(int $uid): bool => $uid > 0 && $uid <= $highestUid
            ));

            if (count($uids) > $limit) {
                if (!$connection instanceof ImapProtocol) {
                    // The PHP-extension protocol, which connect() never asks for.
                    throw new \RuntimeException('Reception dates need the IMAP protocol.');
                }
                $uids = self::mostRecentlyReceived($connection, $uids, $limit);
            }
        } catch (\Throwable $e) {
            throw MailboxConnectionException::at(MailboxConnectionException::STEP_FETCH, $e);
        }

        return new FirstReadSelection(
            $uids,
            $highestUid,
            max(0, (int) ($status['exists'] ?? 0) - count($uids))
        );
    }

    /**
     * The highest UID a `UID SEARCH ALL` named, 0 for an empty folder.
     */
    private static function highestUidIn(mixed $found): int
    {
        $uids = array_map('intval', is_array($found) ? $found : []);

        return $uids === [] ? 0 : max(0, max($uids));
    }

    /**
     * The retained messages of a first read, by UID — the same passive
     * fetch as `fetchSince()` (EXAMINE, `RFC822.HEADER`, `BODY.PEEK[TEXT]`),
     * over a named set rather than a range. Called with at most
     * `MailboxSyncService::BATCH_SIZE` UIDs, so the command line stays short.
     *
     * @param int[] $uids
     * @return FetchedMessage[]
     */
    public function fetchUids(string $folder, array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn(int $uid) => $uid > 0)));
        if ($uids === []) {
            return [];
        }
        sort($uids);

        $imapFolder = $this->requireFolder($folder);

        try {
            // Straight to the fetch, with no SEARCH: the UIDs are already
            // known, and a UID no longer in the folder is simply not in the
            // server's answer.
            $query = TolerantMessageQuery::on($imapFolder);
            $messages = $query
                ->leaveUnread()
                ->setFetchOrderAsc()
                ->limit(count($uids))
                ->curate_messages(new Collection($uids));
        } catch (\Throwable $e) {
            throw MailboxConnectionException::at(MailboxConnectionException::STEP_FETCH, $e);
        }

        // Only what was asked for: a server that ignores the set must not
        // slip a message set aside back in.
        $wanted = array_flip($uids);
        [$fetched] = $this->convert($folder, $query, $messages, static fn(int $uid): bool => isset($wanted[$uid]));

        return $fetched;
    }

    /**
     * The $limit UIDs among $uids the server received last, by
     * `INTERNALDATE`, the higher UID first between two of the same second.
     * A UID the server no longer answers for is gone, and dropped.
     *
     * @param list<int> $uids
     * @return list<int>
     */
    private static function mostRecentlyReceived(ImapProtocol $connection, array $uids, int $limit): array
    {
        $receivedAt = [];
        foreach (array_chunk($uids, self::INTERNALDATE_SLICE) as $slice) {
            $answer = $connection->fetch(['UID', 'INTERNALDATE'], $slice, null, IMAP::ST_UID)->validatedData();
            foreach (is_array($answer) ? $answer : [] as $uid => $items) {
                $date = is_array($items) ? ($items['INTERNALDATE'] ?? null) : null;
                $receivedAt[(int) $uid] = self::internalDate(is_string($date) ? $date : '');
            }
        }

        uksort($receivedAt, static fn(int $a, int $b): int => [$receivedAt[$b], $b] <=> [$receivedAt[$a], $a]);

        return array_slice(array_map('intval', array_keys($receivedAt)), 0, max(0, $limit));
    }

    /**
     * An `INTERNALDATE` (`17-Jul-1996 02:44:25 -0700`, the day possibly
     * space-padded) as a timestamp — 0, the oldest of all, for one the
     * server wrote in some other form.
     */
    private static function internalDate(string $value): int
    {
        // Through DateInput, the one place a date is parsed (a NUL byte makes
        // the date parser throw). Not exact: a server may write the month
        // in another case than PHP formats it back.
        $parsed = DateInput::parse('!j-M-Y H:i:s O', trim($value), false);

        return $parsed?->getTimestamp() ?? 0;
    }

    /**
     * Turn what the query handed back into our messages, keeping only the
     * UIDs $keep accepts, and trace what it could not read — named by
     * folder and UID only.
     *
     * @param iterable<mixed> $messages
     * @param \Closure(int): bool $keep
     * @return array{0: FetchedMessage[], 1: int[]} the messages, and the UIDs skipped as unreadable
     */
    private function convert(string $folder, TolerantMessageQuery $query, iterable $messages, \Closure $keep): array
    {
        $skipped = [];
        foreach ($query->undatedUids() as $uid) {
            if ($keep($uid)) {
                self::trace($folder, $uid, 'has an unreadable Date header; kept, dated at this sync');
            }
        }
        foreach ($query->unreadableUids() as $uid) {
            if ($keep($uid)) {
                self::trace($folder, $uid, 'could not be parsed; skipped');
                $skipped[] = $uid;
            }
        }

        $fetched = [];
        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                continue;
            }

            $uid = (int) $message->getUid();
            if (!$keep($uid)) {
                continue;
            }

            // Converting is INSIDE the loop's own guard, not only the
            // IMAP request above. One message that cannot be turned into
            // ours must cost that message and nothing else: an exception
            // escaping here would reach Service\MailboxSyncService's
            // catch-all, which records the whole pass as failed and saves
            // no cursor — so every later pass would meet the same message.
            try {
                $fetched[] = $this->toFetchedMessage($message, $folder, $uid);
            } catch (\Throwable $e) {
                // @catch-unreachable the library parses every message eagerly, inside
                // TolerantMessageQuery::make(), and what fails there is caught there; toFetchedMessage()
                // only reads what it already parsed. Malformed Message-ID, In-Reply-To and References,
                // doubled headers, an unknown charset and broken base64 all come back as messages. Kept
                // for a library release that reads lazily: one message must still cost only itself.
                self::trace($folder, $uid, 'could not be read (' . self::safeReason($e) . '); skipped');
                $skipped[] = $uid;
                continue;
            }
        }

        return [$fetched, $skipped];
    }

    public function lastSkippedUid(): int
    {
        return $this->lastSkippedUid;
    }

    /**
     * A line in the server's error log about one message, named by folder
     * and UID — never by its sender, subject or Message-ID, and never with
     * the library's own exception text, which quotes the headers.
     */
    private static function trace(string $folder, int $uid, string $what): void
    {
        error_log('Inbound mail: message UID ' . $uid . ' in folder "' . $folder . '" ' . $what . '.');
    }

    private function toFetchedMessage(Message $message, string $folder, int $uid): FetchedMessage
    {
        $from = $message->getFrom()->first();
        // `getHeader()` is declared nullable by the library, and a
        // message it never parsed a header for would be exactly the one
        // whose headers are being asked about. Read defensively rather
        // than through `?->`, which PHPStan rejects here because it
        // narrows the type at this point.
        $header = $message->getHeader();
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
            subject: self::decodeHeader((string) $message->getSubject()->first()),
            fromEmail: strtolower((string) (is_object($from) ? ($from->mail ?? '') : '')),
            fromName: self::nonEmptyOrNull(
                self::decodeHeader(is_object($from) ? (string) ($from->personal ?? '') : '')
            ),
            toEmails: $toEmails,
            inReplyTo: self::nonEmptyOrNull((string) $message->getInReplyTo()->first()),
            references: self::splitReferences((string) $message->getReferences()->first()),
            sentAt: self::sentAt($message),
            bodyText: $message->getTextBody(),
            bodyHtml: $message->getHTMLBody(),
            attachments: $this->toFetchedAttachments($message),
            // The header block exactly as the receiving server wrote it.
            //
            // This was simply not passed, and the omission was invisible
            // from the tests: `Client\FakeMailboxClient` parses real MIME
            // through `Mime\MimeMessageParser`, which fills it, so every
            // test about headers passed while the wire path handed every
            // consumer an empty string. What it cost is the whole point
            // of `Api\MessageRetentionPreference::wantsRawHeaders()`
            // (roadmap IT-22): the diagnostic mail probe read
            // « SPF absent, DKIM absent, DMARC absent » on every message
            // ever received, which is what an empty header block says and
            // not what any of those servers did.
            rawHeaders: $header instanceof Header ? $header->raw : ''
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
                filename: self::decodeHeader((string) ($attachment->name ?? 'piece-jointe')),
                declaredMimeType: (string) ($attachment->getMimeType() ?? 'application/octet-stream'),
                bytes: $content,
                isInline: strtolower((string) ($attachment->disposition ?? '')) === 'inline',
                contentId: self::nonEmptyOrNull((string) ($attachment->id ?? ''))
            );
        }

        return $attachments;
    }

    /**
     * RFC 2047 encoded words, decoded — because `webklex/php-imap` does not
     * decode them here.
     *
     * Its `HeaderDecoder` delegates to `imap_mime_header_decode()`, and
     * when `ext-imap` is absent its own fallback returns the header
     * **unchanged** and reports success, so the later `iconv_mime_decode()`
     * branch is never reached. `ext-imap` was removed from PHP's core in
     * 8.4, which is exactly the host every ScoutMagic installation runs on
     * (see this class's opening note) — so on a real deployment every
     * accented subject reached the screen as
     * `=?utf-8?Q?Voici_le_re=C3=A7u?=`, and every consumer looking for a
     * reference in the subject was reading that instead of the words.
     *
     * `Mime\MimeMessageParser::decodeWords()` is the decoder the parsed
     * path already uses, reused deliberately: the wire path and the
     * `Client\FakeMailboxClient` a test drives must not decode headers two
     * different ways, or a test proves nothing about production. It is a
     * no-op on an already-decoded value — a header with no `=?` in it is
     * returned as it stands — so a host that *does* have `ext-imap`, where
     * the library decodes correctly, is unaffected.
     */
    private static function decodeHeader(string $value): string
    {
        return MimeMessageParser::decodeWords($value);
    }

    private static function sentAt(Message $message): \DateTimeImmutable
    {
        $date = $message->getDate()->first();
        if ($date instanceof \DateTimeInterface) {
            // Moved into the site's time zone before it loses its offset
            // (#1248). Stored as a wall clock, like every other date here,
            // a `+0200` sending time was kept at its own clock hour and read
            // back in the site's zone — an hour or two off.
            $local = \DateTimeImmutable::createFromInterface($date)
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $sent = DateInput::fromStorage($local->format('Y-m-d H:i:s'));
            if ($sent !== null) {
                return $sent;
            }
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
            throw MailboxConnectionException::at(MailboxConnectionException::STEP_OPEN_FOLDER, $e);
        }

        if ($imapFolder === null) {
            throw new MailboxConnectionException(
                'Le dossier demandé est introuvable sur le serveur.',
                0,
                null,
                MailboxConnectionException::STEP_OPEN_FOLDER
            );
        }

        return $imapFolder;
    }

    /**
     * The exception's class, and nothing it wrote.
     *
     * A library's own message routinely contains the account name and, on
     * some servers, the server's verbatim rejection of the credential that
     * was just tried — neither of which belongs in a database column a page
     * renders (§8.6). `MailboxErrorFormatter` turns this into the sentence
     * the superadmin actually reads.
     */
    private static function safeReason(\Throwable $e): string
    {
        $class = $e::class;
        // A class in the global namespace has no backslash at all, and
        // `strrpos()` answers false there — cast to 0, plus one, and the
        // name lost its first letter: a mail host that refused a login
        // was reported to the superadmin as « untimeException », and a
        // PHP Error as « rror ».
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    /**
     * Remove one message, by UID (roadmap IT-07).
     *
     * **`SELECT` and not `EXAMINE` here, and only here.** Every other
     * folder open in this class is the read-only form on purpose; a
     * deletion cannot be, since a folder opened read-only refuses the flag
     * at the protocol level. That is the boundary this method crosses, it
     * crosses it for one consumer's own measuring boxes, and it says so
     * rather than letting a reader assume the class is uniformly safe.
     *
     * The delete is expunged straight away rather than left as a `\Deleted`
     * flag: a flagged message still occupies the box and still comes back
     * on the next fetch, so a seed box would fill up exactly as if nothing
     * had happened — while looking, to anybody opening it, as though the
     * site had rifled through their mail and left it marked.
     *
     * Never throws. A copy that could not be removed has still been
     * measured, and losing the sync over housekeeping would cost the unit
     * its mail to save it some disk.
     */
    public function deleteMessage(string $folder, int $uid): bool
    {
        try {
            $imapFolder = $this->requireFolder($folder);
            $imapFolder->select();

            $message = $imapFolder->query()->leaveUnread()->whereUid($uid)->limit(1)->get()->first();
            if (!$message instanceof Message) {
                // Already gone — which is the outcome the caller wanted.
                return true;
            }

            $message->delete(true);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
