<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact;

use Core\Exception\UserFacingException;

/**
 * A contact card that could not be produced, in words a chef d'unité can
 * act on.
 *
 * Implementing {@see UserFacingException} is a claim about EVERY message
 * this class is ever constructed with (`AGENTS.md` § Exception messages
 * that reach a visitor): French, a full sentence, and naming nothing
 * internal. It is a narrow claim to keep — there is exactly one throw
 * site, {@see ContactQrCodeBuilder::build()}, and it never quotes the
 * card. **Nothing that reaches a message here may name the member**: the
 * whole point of a contact card is that it carries a minor's phone number
 * and home address, and an error message is displayed, logged and
 * screenshotted.
 */
class ContactCardException extends \RuntimeException implements UserFacingException
{
}
