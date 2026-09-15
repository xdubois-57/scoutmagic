<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend\WebDav;

use Core\Exception\UserFacingException;

/**
 * A WebDAV share refusing, or unreachable.
 *
 * **Every message this class carries is a French sentence an operator can
 * act on**, which is what lets it implement {@see UserFacingException}:
 * the roadmap asks that an invalid certificate, a path that is not there
 * and a wrong password each arrive named rather than as a bare exception,
 * and a named failure is worth nothing if the name stays in the log.
 *
 * What never travels in that sentence is the server's own words. cURL
 * talks about TLS internals in English, and a WebDAV server answers a bad
 * password with whatever its vendor wrote; both are carried as the
 * exception's cause, for the journal, and neither reaches the screen.
 */
final class WebDavAccessException extends \RuntimeException implements UserFacingException
{
    public static function of(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, 0, $previous);
    }
}
