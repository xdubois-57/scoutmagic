<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Mailbox;

/**
 * The secret half of a mailbox's configuration.
 *
 * **Separate from `Mailbox` on purpose.** Everything a page, a listing, a
 * log line or an error message ever needs lives on `Mailbox`; the password
 * lives here, is only ever loaded by the repository when a client is about
 * to be built, and never reaches a template. A single object carrying both
 * would make "do not display a secret" a rule somebody has to remember on
 * every screen rather than a property of the code.
 *
 * There is no `__toString()`, and there never should be one: it is exactly
 * how a password ends up interpolated into a log line.
 */
class MailboxCredentials
{
    public function __construct(
        public readonly string $username,
        public readonly string $password
    ) {
    }

    /**
     * Deliberately blanks the secret in var_dump()/print_r() output, which
     * is where it would otherwise surface first — a stack trace rendered
     * during a connection failure prints constructor arguments.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => '***'];
    }
}
