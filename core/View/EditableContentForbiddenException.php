<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Exception\UserFacingException;

/**
 * This session may not write this editable-content key.
 *
 * Thrown by {@see EditableContentService::set()}, which is the **single
 * point every write to `editable_contents` passes through** — the
 * configuration-mode editor, the rich-text field endpoint and the image
 * upload alike. The individual entry points check first so they can
 * answer in their own shape (a JSON 403, a refused upload); this
 * exception is what makes a future fourth entry point fail closed
 * instead of silently reopening the gap.
 *
 * Its one message is the site's standard refusal sentence and names
 * nothing about the key: an answer that distinguished « no such page »
 * from « not your page » would map out which ids exist.
 */
final class EditableContentForbiddenException extends \RuntimeException implements UserFacingException
{
}
