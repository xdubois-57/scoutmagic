<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Template;

/**
 * A customised e-mail the renderer refused to send because its stored body
 * leaves out a required variable (EmailTemplateVariable::$required).
 *
 * Its own class so a sender can record « not sent, the text is missing
 * its link » as the failure it is, distinct from an unknown template id.
 * The message is the one the editor shows for the same mistake, a French
 * sentence naming the variable and nothing internal.
 */
final class MissingRequiredVariableException extends EmailTemplateException
{
}
