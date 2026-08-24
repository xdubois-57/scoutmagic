<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help;

/**
 * A help topic file that cannot be loaded: malformed front matter, a
 * missing or invalid field, an id colliding with another topic. Always a
 * defect in shipped content (docs/help/ or a module's help/ directory),
 * never a runtime condition — the invariant tests in tests/Core/Help/
 * exist so one of these can never reach a release.
 *
 * Deliberately NOT a Core\Exception\UserFacingException: the message
 * names file paths and field names, which a visitor must never see
 * (AGENTS.md § Exception messages).
 */
class HelpException extends \RuntimeException
{
}
