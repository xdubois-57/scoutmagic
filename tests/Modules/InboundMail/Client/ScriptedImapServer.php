<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\IMAP;

/**
 * An IMAP server answering from a script, at the protocol boundary.
 *
 * Everything above it runs for real: `webklex/php-imap`'s `Client`,
 * `Folder`, `WhereQuery` and `Message::make()`, with the configuration
 * `ImapMailboxClient::connect()` really passes. Only the socket is gone.
 * That is what lets a test show how the LIBRARY reacts to a message or an
 * answer — which is the half nobody controls — rather than how a double
 * written to agree with our code would.
 *
 * A command the scenario wants refused is answered the way a server
 * refuses one, with a tagged `NO`, so the library raises what it raises
 * in production.
 */
final class ScriptedImapServer extends ImapProtocol
{
    /** @var array<string, array<int, string>> folder => uid => raw RFC 5322 message */
    public array $folders = ['INBOX' => []];

    public int $uidValidity = 1;

    /** @var list<string> commands to answer with a tagged NO: list, examine, search, select, store */
    public array $refuse = [];

    /** @var list<array{folder: string, uid: int}> */
    public array $deleted = [];

    /** @var list<string> every flag set the client asked for, in order */
    public array $stored = [];

    /**
     * @var list<string> every command that opens a folder or reads a
     * message, as the server would see it (#1231): EXAMINE or SELECT, and
     * each FETCH item
     */
    public array $commands = [];

    private ?string $selected = null;

    /** @var list<int> */
    private array $flaggedDeleted = [];

    public function __construct()
    {
        parent::__construct(new Config());
    }

    public function __destruct()
    {
        // The parent logs out over a socket this server never opened.
    }

    public function connected(): bool
    {
        return true;
    }

    public function logout(): Response
    {
        return $this->ok(true);
    }

    public function folders(string $reference = '', string $folder = '*'): Response
    {
        if (in_array('list', $this->refuse, true)) {
            return $this->no('LIST');
        }

        $listing = [];
        foreach (array_keys($this->folders) as $path) {
            $listing[$path] = ['delimiter' => '/', 'flags' => []];
        }

        return $this->ok($listing);
    }

    public function examineFolder(string $folder = 'INBOX'): Response
    {
        if (in_array('examine', $this->refuse, true)) {
            return $this->no('EXAMINE');
        }
        $this->commands[] = 'EXAMINE ' . $folder;
        $this->selected = $folder;

        return $this->ok($this->status($folder));
    }

    public function selectFolder(string $folder = 'INBOX'): Response
    {
        if (in_array('select', $this->refuse, true)) {
            return $this->no('SELECT');
        }
        $this->commands[] = 'SELECT ' . $folder;
        $this->selected = $folder;

        return $this->ok($this->status($folder));
    }

    public function search(array $params, int|string $uid = IMAP::ST_UID): Response
    {
        if (in_array('search', $this->refuse, true)) {
            return $this->no('UID SEARCH');
        }

        return $this->ok(array_keys($this->folders[(string) $this->selected] ?? []), true);
    }

    public function flags(int|array $uids, int|string $uid = IMAP::ST_UID): Response
    {
        $this->commands[] = 'FETCH FLAGS';
        $flags = [];
        foreach ((array) $uids as $id) {
            $flags[$id] = [];
        }

        return $this->ok($flags, true);
    }

    public function headers(int|array $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): Response
    {
        $this->commands[] = 'FETCH ' . $rfc . '.HEADER';

        return $this->ok($this->part((array) $uids, 0), true);
    }

    public function content(int|array $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): Response
    {
        $this->commands[] = 'FETCH ' . $rfc . '.TEXT';

        return $this->ok($this->part((array) $uids, 1), true);
    }

    /**
     * A FETCH of named items. Only `BODY.PEEK[TEXT]` is answered — under
     * the name a real server gives it back, `BODY[TEXT]` — since it is the
     * one the client asks for this way.
     *
     * @param array<int, string>|string $items
     * @param array<int, int>|int $from
     */
    public function fetch(
        array|string $items,
        array|int $from,
        mixed $to = null,
        int|string $uid = IMAP::ST_UID
    ): Response {
        $items = (array) $items;
        $this->commands[] = 'FETCH ' . implode(' ', $items);
        if (in_array('fetch', $this->refuse, true)) {
            return $this->no('UID FETCH');
        }

        $result = [];
        foreach ($this->part((array) $from, 1) as $id => $body) {
            $result[$id] = ['UID' => $id];
            if (in_array('BODY.PEEK[TEXT]', $items, true)) {
                $result[$id]['BODY[TEXT]'] = $body;
            }
        }

        return $this->ok($result, true);
    }

    public function store(
        array|string $flags,
        int $from,
        ?int $to = null,
        ?string $mode = null,
        bool $silent = true,
        int|string $uid = IMAP::ST_UID,
        ?string $item = null
    ): Response {
        if (in_array('store', $this->refuse, true)) {
            return $this->no('UID STORE');
        }
        $this->stored[] = ($mode === '-' ? '-' : '+') . implode(' ', (array) $flags);
        if ($mode !== '-' && in_array('\\Deleted', (array) $flags, true)) {
            $this->flaggedDeleted[] = $from;
        }

        return $this->ok(true);
    }

    public function expunge(): Response
    {
        foreach ($this->flaggedDeleted as $uid) {
            unset($this->folders[(string) $this->selected][$uid]);
            $this->deleted[] = ['folder' => (string) $this->selected, 'uid' => $uid];
        }
        $this->flaggedDeleted = [];

        return $this->ok([], true);
    }

    /** @return array<string, int> */
    private function status(string $folder): array
    {
        $uids = array_keys($this->folders[$folder] ?? []);

        return [
            'exists' => count($uids),
            'uidvalidity' => $this->uidValidity,
            'uidnext' => ($uids === [] ? 0 : max($uids)) + 1,
        ];
    }

    /**
     * @param list<int|string> $uids
     * @return array<int, string> the header block (0) or the body (1) of each message
     */
    private function part(array $uids, int $half): array
    {
        $parts = [];
        foreach ($uids as $id) {
            $raw = $this->folders[(string) $this->selected][(int) $id] ?? null;
            if ($raw === null) {
                continue;
            }
            $split = explode("\r\n\r\n", $raw, 2);
            $parts[(int) $id] = $half === 0 ? $split[0] . "\r\n\r\n" : ($split[1] ?? '');
        }

        return $parts;
    }

    private function ok(mixed $result, bool $canBeEmpty = false): Response
    {
        return Response::empty()->setResult($result)->setCanBeEmpty($canBeEmpty);
    }

    /** The server's own refusal, carrying the account name as real servers' answers do. */
    private function no(string $command): Response
    {
        return Response::make(1, [$command], ['TAG1 NO [UNAVAILABLE] ' . $command . ' refused for chef@unite.be']);
    }
}
