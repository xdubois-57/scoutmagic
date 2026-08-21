<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Mailbox;

/**
 * The kinds of mailbox this module can read.
 *
 * **IMAP is the only real one, and that is a decision rather than a
 * shortfall** (§7.2). A native Gmail connector would need Google's
 * `gmail.readonly` scope, which is *restricted*: every unit deploying
 * ScoutMagic would owe an annual paid security assessment, and without it
 * their tokens expire every seven days and their sync breaks weekly. A
 * Gmail box connects here over IMAP with an app password like any other.
 *
 * The enum stays, so a native connector can be added later without
 * reshaping the storage — but it is not implemented, and adding a case
 * without a client is how a mailbox ends up unreadable.
 */
enum ProviderType: string
{
    case IMAP = 'imap';

    /**
     * A client that reads from a scripted, in-memory mailbox. Not a
     * degraded IMAP: it exists so the whole sync path — cursor, dedup,
     * UIDVALIDITY handling, MIME, attachments, claiming — is testable end
     * to end in CI with no network and no server.
     */
    case FAKE = 'fake';

    public function label(): string
    {
        return match ($this) {
            self::IMAP => 'IMAP',
            self::FAKE => 'Boîte de test (sans réseau)',
        };
    }

    /**
     * Whether an operator may create one of these from the configuration
     * page. The fake provider is a test fixture, not a product feature.
     */
    public function isSelectable(): bool
    {
        return $this === self::IMAP;
    }
}
