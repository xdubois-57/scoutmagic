<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Template;

use Core\Exception\UserFacingException;

/**
 * A refusal an administrator editing an automatic e-mail is shown
 * verbatim.
 *
 * Implementing the marker is a claim about EVERY message this class is
 * constructed with (AGENTS.md § Exception messages that reach a visitor):
 * each one is a full French sentence naming nothing internal — no path,
 * no SQL, no class name. There are three, all in
 * EmailTemplateCustomisationService, and none of them quotes the content
 * being saved.
 */
class EmailTemplateException extends \RuntimeException implements UserFacingException
{
}
