<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Client\PruningMailboxClientInterface;

/**
 * The fake, plus the one thing the real IMAP client can do that it
 * cannot: remove a message (roadmap IT-07).
 *
 * **Separate from `FakeMailboxClient` on purpose.** That one implements
 * the reading contract and nothing else, which is what lets a test assert
 * that a consumer asking to prune against an ordinary client removes
 * nothing at all — the half of the lock that lives on the client side.
 */
final class PruningFakeMailboxClient extends FakeMailboxClient implements PruningMailboxClientInterface
{
    /** @var list<array{folder: string, uid: int}> */
    public array $deleted = [];

    public function deleteMessage(string $folder, int $uid): bool
    {
        $this->deleted[] = ['folder' => $folder, 'uid' => $uid];

        return true;
    }
}
