<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * A passkey ceremony's body that cannot even be read (issue #1131): a
 * field missing or of the wrong type, base64url that does not decode,
 * truncated CBOR, clientDataJSON that is not a JSON object. It is not a
 * failed verification — nothing was verified — so the controllers answer
 * it with HTTP 400 and USER_MESSAGE, never a 500 (SECURITY.md § 35).
 *
 * Deliberately not a \RuntimeException: AccountController::passkeyRegister()
 * maps every RuntimeException to « L'enregistrement a échoué. » with a
 * 200, and a caller that forgot this class must not quietly blend it into
 * that. Its message is technical (it names the field) and stays out of
 * every answer; only USER_MESSAGE reaches the visitor.
 */
final class WebAuthnMalformedRequest extends \InvalidArgumentException
{
    /** What the visitor is told, whatever was wrong. */
    public const USER_MESSAGE = 'Requête invalide.';
}
