<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Security\EncryptionService;

/**
 * The unguessable key to one receivable's QR image.
 *
 * A payment reminder carries the QR as an **image URL**, not as an
 * attachment: `Core\Security\HtmlSanitizer`'s URL allowlist is `http`,
 * `https`, `mailto`, `tel` and nothing else — `cid:` and `data:` are both
 * refused, and widening that list would reopen precisely the hole its own
 * comment describes having closed. So the QR travels the same way the
 * editor's "Insérer une image" button already sends one: an absolute
 * https URL. A mail client has no session, so the URL has to carry its
 * own proof.
 *
 * **Derived, never stored.** The token is an HMAC of the receivable's id
 * under the installation's blind-index key — the same primitive that
 * already lets the site look up an encrypted value without decrypting it.
 * Nothing to persist, nothing to purge, no expiry to get wrong, and the
 * same receivable always yields the same URL — which is what makes the
 * archived copy of a sent mail show the same image as the one that went
 * out, with no transformation and nothing stored to make it true.
 *
 * The exposure is deliberately the same as the mail's: whoever has the
 * link sees one receivable's amount and communication, which is what the
 * mail says in text anyway. Revocation is by key rotation, which is the
 * honest consequence of storing nothing.
 */
class ReceivableQrTokenService
{
    private const PURPOSE = 'finance_receivable_qr';

    public function __construct(private EncryptionService $encryption)
    {
    }

    public function tokenFor(int $receivableId): string
    {
        return $this->encryption->blindIndex((string) $receivableId, self::PURPOSE);
    }

    /**
     * Constant-time, so a wrong token cannot be narrowed down by how long
     * the refusal takes.
     */
    public function isValid(int $receivableId, string $token): bool
    {
        return hash_equals($this->tokenFor($receivableId), $token);
    }

    /**
     * The absolute URL a mail body points its <img> at.
     *
     * Absolute because the reader is a mail client, not a page of this
     * site: a relative path resolves against nothing there.
     */
    public function urlFor(int $receivableId, string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/finance/qr/' . $receivableId . '/' . $this->tokenFor($receivableId);
    }
}
