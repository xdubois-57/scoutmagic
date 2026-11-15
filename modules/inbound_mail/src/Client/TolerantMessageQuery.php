<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;

/**
 * The library's query, except that one message it cannot parse never fails
 * the batch it arrived in.
 *
 * `webklex/php-imap` parses every message inside `get()`, and a parse error
 * there throws for the WHOLE batch: the good messages beside the bad one are
 * not returned either, the sync records a failure, its cursor does not move,
 * and every later pass meets the same message again. Anybody who knows the
 * address can send that message — a `Date:` the library cannot read
 * (`Date: pas une date`), or two `Date:` headers — and stop the box for good.
 *
 * Its `soft_fail` option is not the answer, for three reasons: it is read
 * from the library's global configuration, never from the account options
 * `ImapMailboxClient::connect()` passes; it catches only four exception
 * classes, and the two-`Date:` case — where the PHP release refuses it —
 * escapes as a Carbon exception that is none of them; and what it does
 * catch it drops without a word.
 *
 * So the one per-message step, `make()`, is guarded here instead:
 *
 * - a message that does not parse is parsed again **without its `Date:`
 *   lines** — which is the only header the library parses eagerly enough
 *   to fail on. It comes back with no date, and
 *   `ImapMailboxClient::sentAt()` already stores such a message, dated at
 *   the sync that reads it (not the server's own reception date, which is
 *   never fetched), rather than losing it. Its raw header block is put back
 *   as the server wrote it, since the sender's authentication is read from
 *   it later;
 * - a message that still does not parse is left out, and its UID is kept
 *   so the client can say so. The batch carries on either way.
 *
 * Network and protocol errors are untouched: they are raised by the
 * SEARCH and FETCH commands before `make()` is reached, and still fail the
 * batch as the connection problems they are.
 */
final class TolerantMessageQuery extends WhereQuery
{
    /** @var int[] UIDs stored without their unreadable `Date:` */
    private array $undated = [];

    /** @var int[] UIDs that could not be parsed at all */
    private array $unreadable = [];

    /**
     * What `Folder::query()` does, building this class instead of the
     * library's own.
     */
    public static function on(Folder $folder): self
    {
        $client = $folder->getClient();
        $client->checkConnection();
        $client->openFolder($folder->path);

        return new self($client, $client->extensions);
    }

    /** @return int[] */
    public function undatedUids(): array
    {
        return $this->undated;
    }

    /** @return int[] */
    public function unreadableUids(): array
    {
        return $this->unreadable;
    }

    /**
     * @param array<mixed> $flags as the library's own `make()` receives them
     */
    protected function make(int $uid, int $msglist, string $header, string $content, array $flags): ?Message
    {
        try {
            $message = parent::make($uid, $msglist, $header, $content, $flags);
            if ($message !== null) {
                return $message;
            }
        } catch (\Throwable) {
            // Read again below, without the header that fails.
        }

        try {
            $message = parent::make($uid, $msglist, self::withoutDate($header), $content, $flags);
        } catch (\Throwable) {
            $message = null;
        }

        if ($message === null) {
            $this->unreadable[] = $uid;

            return null;
        }

        $parsedHeader = $message->getHeader();
        if ($parsedHeader !== null) {
            $parsedHeader->raw = $header;
        }
        $this->undated[] = $uid;

        return $message;
    }

    /**
     * The header block without any `Date:` field, folded continuation lines
     * included. Nothing else is touched: `Resent-Date:` and the like are
     * other fields, and the library does not parse them.
     */
    public static function withoutDate(string $header): string
    {
        $lines = preg_split('/(?<=\n)/', $header, -1, PREG_SPLIT_NO_EMPTY) ?: [$header];
        $kept = '';
        $inDate = false;

        foreach ($lines as $line) {
            // A line opening on whitespace continues the field above it,
            // so it goes or stays with that field.
            if ($line[0] !== ' ' && $line[0] !== "\t") {
                $inDate = preg_match('/^date[ \t]*:/i', $line) === 1;
            }
            if (!$inDate) {
                $kept .= $line;
            }
        }

        return $kept;
    }
}
