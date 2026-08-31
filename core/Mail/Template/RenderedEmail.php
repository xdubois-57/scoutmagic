<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Template;

/**
 * One e-mail, rendered and ready to hand to
 * `Core\Mail\MailService::send()` — and nothing more than that.
 *
 * The subject carries NO `[{short_name}]` prefix: MailService adds that
 * itself to every message it sends, and a renderer that added it too
 * would double it on exactly the e-mails an administrator had touched.
 */
final class RenderedEmail
{
    public function __construct(
        public readonly string $subject,
        public readonly string $bodyHtml,
        public readonly string $bodyText
    ) {
    }
}
