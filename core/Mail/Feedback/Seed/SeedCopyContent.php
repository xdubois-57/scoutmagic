<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed;

/**
 * What a seed copy is allowed to carry (roadmap IT-07).
 *
 * **It exists because the message actually being sent is the wrong thing
 * to copy**, and the first version of this feature copied it.
 *
 * A mailing is personalised per recipient: `mass_mail` appends, to each
 * message, a one-click unsubscribe link holding a freshly minted
 * capability token for THAT member. Forwarding `send()`'s own body to a
 * seed box therefore put a **working link to act on one real member's
 * behalf** into a mailbox hosted by a third party — every campaign, one
 * arbitrary member, no way to know which. « The same personal data as the
 * mailing » is what the privacy notice promises; a live capability is a
 * different and stronger thing, and nothing disclosed it.
 *
 * So the transport no longer guesses. The caller is the only layer that
 * knows which half of a message is the campaign and which half is one
 * person's, and it says so here. **No content, no copies** — the safe
 * default, because the failure mode of the alternative is a silent leak.
 *
 * What travels is the campaign as written: the same body every recipient
 * received the substance of, with none of the parts minted for one of
 * them.
 */
final class SeedCopyContent
{
    /**
     * @param array<string, string> $extraHeaders Headers the copy may
     *        carry. The caller strips whatever names or empowers a
     *        recipient — see `List-Unsubscribe` in
     *        `Modules\MassMail\Task\SendBatchHandler`, where the
     *        campaign's own one-click URL is a capability and the copy
     *        gets a `mailto:` instead.
     */
    public function __construct(
        public readonly string $bodyHtml,
        public readonly string $bodyText,
        public readonly array $extraHeaders = []
    ) {
    }
}
