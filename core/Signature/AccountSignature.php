<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Signature;

/**
 * The signature of the account signing a document, ready to be put on it
 * (issue #926, lot C1): what `PersonalSignatureService::forSigning()`
 * hands a module, already decoded and re-encoded, before the module takes
 * any lock of its own.
 *
 * `$accountId` is the session's — `forSigning()` takes it from the caller,
 * who read it from `AuthSession`, never from the request. `$signerName` is
 * the account's own first and last name (never a member's display name, a
 * totem that tells the other party nothing — issue #825), null when the
 * account has none: the module then names the signer its own way.
 *
 * `$rememberRequested` is the « Mémoriser » box, always false for a
 * signature that already came from the account. Keeping it is
 * `rememberIfAsked()`, which the module runs inside its own transaction.
 */
final readonly class AccountSignature
{
    public function __construct(
        public int $accountId,
        public SignaturePng $png,
        public SignatureOrigin $origin,
        public bool $rememberRequested,
        public ?string $signerName
    ) {
    }

    /** Whether signing with it also keeps it in the account. */
    public function willBeRemembered(): bool
    {
        return $this->rememberRequested && $this->origin !== SignatureOrigin::Stored;
    }
}
