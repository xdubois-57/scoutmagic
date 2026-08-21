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
    /** @var array<string, list<array{uid: int, raw: string}>> */
    private array $foldersByPath = [];

    /** @var array<string, int> */
    private array $uidValidityByFolder = [];

    private bool $connected = false;

    /** @var list<string> */
    public array $calls = [];

    private ?\Throwable $connectFailure = null;

    public function __construct(private MimeMessageParser $parser = new MimeMessageParser())
    {
    }

    // ── Scripting the fixture ───────────────────────────────────────────

    public function addRawMessage(string $folder, int $uid, string $raw): void
    {
        $this->foldersByPath[$folder][] = ['uid' => $uid, 'raw' => $raw];
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
     * @return string[]
     */
    public function listFolders(): array
    {
        $this->calls[] = 'listFolders';

        return array_keys($this->foldersByPath);
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

            $fetched[] = $this->parser->parse($entry['raw'], $entry['uid'], $folder);
        }

        return $fetched;
    }
}
