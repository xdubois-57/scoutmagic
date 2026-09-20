<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Api;

use Core\Exception\UserFacingException;

/**
 * The module's user-facing exception (ARCHITECTURE.md §7.5): the one type it
 * throws across its own boundary, and the one whose message a visitor is
 * shown verbatim.
 *
 * Implementing `UserFacingException` is a claim about EVERY message this
 * class is ever constructed with (AGENTS.md § Exception messages that reach
 * a visitor): French, a full sentence, naming nothing internal — no file
 * path, no class name, no library text. In particular nothing FPDI says
 * about a file it could not parse ever reaches here; the caller writes a
 * sentence and lets `$previous` carry the detail.
 *
 * And nothing a parent typed reaches here either. These screens carry health
 * data and a family's own names: a validation that failed never echoes the
 * value it refused.
 */
class OfficialDocumentsException extends \RuntimeException implements UserFacingException
{
}
