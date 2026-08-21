<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Client\ImapMailboxClient;
use Modules\InboundMail\Client\IncomingMailboxClientInterface;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\ProviderType;

/**
 * A mailbox's provider type into the client that reads it.
 *
 * The indirection is what keeps `MailboxSyncService` free of any IMAP
 * vocabulary — it never names a client class, so the whole sync path can be
 * exercised in CI against `FakeMailboxClient` with no network and no
 * server, and the production path differs by one line here.
 *
 * `register()` lets a test (or a future native connector) supply its own
 * client for a provider type without this class knowing about it.
 */
class MailboxClientFactory
{
    /** @var array<string, IncomingMailboxClientInterface> */
    private array $overrides = [];

    public function register(ProviderType $type, IncomingMailboxClientInterface $client): void
    {
        $this->overrides[$type->value] = $client;
    }

    public function forMailbox(Mailbox $mailbox): IncomingMailboxClientInterface
    {
        if (isset($this->overrides[$mailbox->providerType->value])) {
            return $this->overrides[$mailbox->providerType->value];
        }

        return match ($mailbox->providerType) {
            ProviderType::IMAP => new ImapMailboxClient(),
            ProviderType::FAKE => new FakeMailboxClient(),
        };
    }
}
