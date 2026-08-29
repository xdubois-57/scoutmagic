<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Api;

use Core\Exception\UserFacingException;

/**
 * A refused mass-mail action, stated in the chief's own terms (« Seul un
 * brouillon peut passer en mode test. »). Marked {@see UserFacingException}:
 * every message is a French sentence written for the person on the page, and
 * none is ever built from a caught exception's own text — the send failures
 * that carry PHPMailer's English go through
 * {@see \Core\Exception\UserFacingMessage::from()} at their display site
 * instead.
 *
 * Part of the module's PUBLIC contract (ARCHITECTURE.md §7.5):
 * Api\MassMailDraftInterface declares it in its @throws, so a consumer
 * catching it needs it in the `Api\` namespace — the same precedent as
 * LlmConnector\Api\LlmException and Gallery\Api\GalleryException.
 */
class MassMailException extends \RuntimeException implements UserFacingException
{
}
