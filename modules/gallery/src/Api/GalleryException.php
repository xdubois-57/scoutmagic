<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

use Core\Exception\UserFacingException;

/**
 * A refused gallery operation. Part of the module's PUBLIC contract
 * (ARCHITECTURE.md §7.5): a consuming module that must catch it — camps
 * and groups both do — needs it in the `Api\` namespace, the same
 * precedent as LlmConnector\Api\LlmException. Marked
 * {@see UserFacingException}: every message is a French sentence written
 * for the chief or the visitor. The two sites that re-wrap an
 * upload/decode failure route the original through
 * {@see \Core\Exception\UserFacingMessage::from()} rather than trusting
 * `$e->getMessage()` blindly.
 */
class GalleryException extends \RuntimeException implements UserFacingException
{
}
