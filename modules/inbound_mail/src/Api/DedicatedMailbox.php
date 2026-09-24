<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * A mailbox the operator declared to be one module's own
 * (`MailboxPurpose::DEDICATED`), as that module may know it: which box, what
 * it is called, and the address it receives on.
 *
 * The address is the one piece `listMailboxSummaries()` withholds, and it is
 * handed out here for the same reason `probeAddressesFor()` hands it out: a
 * module whose box this IS writes from it — the `Reply-To` of what it sends
 * about its own objects (issue #462). Never the host, the port or the
 * credentials.
 *
 * `address` is null when the box's account name is not an address — an
 * IMAP login such as `locations` — the same test `ReplyAddressService`
 * applies before minting: a login has no domain anyone could write to.
 */
final class DedicatedMailbox
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $address
    ) {
    }
}
