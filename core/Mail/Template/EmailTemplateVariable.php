<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Template;

/**
 * One placeholder an administrator may drop into a customised e-mail.
 *
 * `name` is what appears between the braces — `{{ member_name }}` — and
 * is substituted as a plain string, never evaluated (see
 * EmailTemplateRenderer, and SECURITY.md's reasoning about content edited
 * from an administration page).
 *
 * `label` is the French wording of the button that inserts it, and
 * `example` is what the preview puts in its place, so an administrator
 * sees a sentence rather than a row of braces. The example is also what
 * the « M'envoyer un test » button sends, which is why it has to read
 * like a real value rather than "xxx".
 */
final class EmailTemplateVariable
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $example
    ) {
    }
}
