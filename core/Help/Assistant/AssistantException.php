<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help\Assistant;

use Core\Exception\UserFacingException;

/**
 * What the assistant refuses to do, in words its asker can read: the
 * quota is spent, the question is empty or absurdly long.
 *
 * NOT for a failure of the model or the connector — those are
 * `Modules\LlmConnector\Api\LlmException`, which already implements
 * UserFacingException and already carries a French sentence, so the
 * endpoint shows it as it comes rather than re-wrapping it (AGENTS.md
 * § Exception messages: never `new X($e->getMessage())`).
 *
 * Implementing the marker is a claim about EVERY message this class is
 * ever constructed with: French, a full sentence, naming nothing
 * internal. Read every `throw new AssistantException` before adding one.
 */
class AssistantException extends \RuntimeException implements UserFacingException
{
}
