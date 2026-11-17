<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Modules\InboundMail\Mime\MimeMessageParser;

/**
 * A mailbox held in memory, fed with raw RFC 5322 messages.
 *
 * **This is what makes the sync path testable in CI with no network**
 * (§ iteration 10). It is not a stub that returns canned `FetchedMessage`
 * objects: it runs real raw messages through the same
 * `Mime\MimeMessageParser` the wire path uses, so a test about
 * multipart/alternative, an RFC 2047 subject or an encoded filename is
 * testing the parser that production uses rather than a second one written
 * for tests.
 *
 * It also **records what was asked of it**, so a test can assert the sync
 * loop's behaviour — that a folder was examined before being read, that the
 * cursor was respected — rather than only its output.
 */
class FakeMailboxClient implements IncomingMailboxClientInterface
{
    /** @var array<string, list<array{uid: int, raw: string, receivedAt: ?\DateTimeImmutable}>> */
    private array $foldersByPath = [];

    /** @var array<string, int> */
    private array $uidValidityByFolder = [];

    /** @var array<string, true> */
    private array $sentFolders = [];

    private bool $connected = false;

    /** @var list<string> */
    public array $calls = [];

    private ?\Throwable $connectFailure = null;

    private int $lastSkippedUid = 0;

    /**
     * Every UID a fetch handed back, folder by folder and in order — so a
     * test can say that a message was read once, or never.
     *
     * @var array<string, list<int>>
     */
    public array $fetchedUids = [];

    public function __construct(private MimeMessageParser $parser = new MimeMessageParser())
    {
    }

    // ── Scripting the fixture ───────────────────────────────────────────

    /**
     * $receivedAt is the server's reception date (`INTERNALDATE`), which is
     * what a first read judges by (D11). Left out, the message counts as
     * just arrived: newer than any date a first read asks about, and among
     * such messages the higher UID is the more recent.
     */
    public function addRawMessage(
        string $folder,
        int $uid,
        string $raw,
        ?\DateTimeImmutable $receivedAt = null
    ): void {
        $this->foldersByPath[$folder][] = ['uid' => $uid, 'raw' => $raw, 'receivedAt' => $receivedAt];
        $this->uidValidityByFolder[$folder] ??= 1;
    }

    /**
     * Renumber a folder the way a server does after a restore or a
     * migration — the case a cursor that remembered only a UID would lose
     * mail over (§7.5).
     */
    public function setUidValidity(string $folder, int $uidValidity): void
    {
        $this->uidValidityByFolder[$folder] = $uidValidity;
    }

    /**
     * Have the server mark $folder `\Sent` (RFC 6154), as most do for the
     * folder their webmail files sent mail in. The folder exists, empty,
     * even before a message is added to it.
     */
    public function markSent(string $folder): void
    {
        $this->sentFolders[$folder] = true;
        $this->foldersByPath[$folder] ??= [];
        $this->uidValidityByFolder[$folder] ??= 1;
    }

    public function failNextConnect(\Throwable $failure): void
    {
        $this->connectFailure = $failure;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    // ── The client contract ─────────────────────────────────────────────

    public function connect(Mailbox $mailbox, MailboxCredentials $credentials): void
    {
        $this->calls[] = 'connect';

        if ($this->connectFailure !== null) {
            $failure = $this->connectFailure;
            $this->connectFailure = null;

            throw new MailboxConnectionException($failure->getMessage(), 0, $failure);
        }

        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->calls[] = 'disconnect';
        $this->connected = false;
    }

    /**
     * @return RemoteFolder[]
     */
    public function listFolders(): array
    {
        $this->calls[] = 'listFolders';

        return array_map(
            fn(string $path): RemoteFolder => new RemoteFolder($path, isset($this->sentFolders[$path])),
            array_keys($this->foldersByPath)
        );
    }

    public function folderState(string $folder): FolderState
    {
        $this->calls[] = 'folderState:' . $folder;

        $uids = array_map(static fn(array $entry) => $entry['uid'], $this->foldersByPath[$folder] ?? []);

        return new FolderState(
            $folder,
            $this->uidValidityByFolder[$folder] ?? 1,
            $uids === [] ? 0 : max($uids)
        );
    }

    /**
     * @return FetchedMessage[]
     */
    public function fetchSince(string $folder, int $sinceUid, int $limit): array
    {
        $this->calls[] = 'fetchSince:' . $folder . ':' . $sinceUid;
        $this->lastSkippedUid = 0;

        $entries = $this->foldersByPath[$folder] ?? [];
        usort($entries, static fn(array $a, array $b) => $a['uid'] <=> $b['uid']);

        $fetched = [];
        foreach ($entries as $entry) {
            if ($entry['uid'] <= $sinceUid) {
                continue;
            }
            if (count($fetched) >= $limit) {
                break;
            }

            // The same per-message guard the real client carries
            // (Client\ImapMailboxClient::fetchSince()): a double that
            // loses a whole batch where production loses one message is a
            // double that tests the wrong thing.
            try {
                $fetched[] = $this->parse($folder, $entry);
            } catch (\Throwable) {
                $this->lastSkippedUid = max($this->lastSkippedUid, $entry['uid']);
                continue;
            }
        }

        return $fetched;
    }

    /**
     * What the IMAP client does with `UID SEARCH SINCE` and the reception
     * dates, over the same messages: since $since by reception date, the
     * $limit most recent of them, never judged by UID.
     */
    public function firstReadSelection(string $folder, \DateTimeImmutable $since, int $limit): FirstReadSelection
    {
        $this->calls[] = 'firstReadSelection:' . $folder . ':' . $since->format('Y-m-d') . ':' . $limit;

        $entries = $this->foldersByPath[$folder] ?? [];
        $recent = array_values(array_filter(
            $entries,
            static fn(array $entry): bool => $entry['receivedAt'] === null
                || $entry['receivedAt']->format('Y-m-d') >= $since->format('Y-m-d')
        ));
        usort($recent, static function (array $a, array $b): int {
            if ($a['receivedAt'] === null || $b['receivedAt'] === null) {
                $byDate = ($a['receivedAt'] === null ? 1 : 0) <=> ($b['receivedAt'] === null ? 1 : 0);
            } else {
                $byDate = $a['receivedAt'] <=> $b['receivedAt'];
            }

            return $byDate !== 0 ? -$byDate : $b['uid'] <=> $a['uid'];
        });
        $retained = array_map(static fn(array $entry): int => $entry['uid'], array_slice($recent, 0, max(0, $limit)));
        $uids = array_map(static fn(array $entry): int => $entry['uid'], $entries);

        return new FirstReadSelection(
            $retained,
            $uids === [] ? 0 : max($uids),
            count($entries) - count($retained)
        );
    }

    /**
     * @param int[] $uids
     * @return FetchedMessage[]
     */
    public function fetchUids(string $folder, array $uids): array
    {
        $this->calls[] = 'fetchUids:' . $folder . ':' . count($uids);

        $wanted = array_flip($uids);
        $entries = array_values(array_filter(
            $this->foldersByPath[$folder] ?? [],
            static fn(array $entry): bool => isset($wanted[$entry['uid']])
        ));
        usort($entries, static fn(array $a, array $b) => $a['uid'] <=> $b['uid']);

        $fetched = [];
        foreach ($entries as $entry) {
            try {
                $fetched[] = $this->parse($folder, $entry);
            } catch (\Throwable) {
                continue;
            }
        }

        return $fetched;
    }

    /**
     * @param array{uid: int, raw: string, receivedAt: ?\DateTimeImmutable} $entry
     */
    private function parse(string $folder, array $entry): FetchedMessage
    {
        $message = $this->parser->parse($entry['raw'], $entry['uid'], $folder);
        $this->fetchedUids[$folder][] = $entry['uid'];

        return $message;
    }

    public function lastSkippedUid(): int
    {
        return $this->lastSkippedUid;
    }
}
