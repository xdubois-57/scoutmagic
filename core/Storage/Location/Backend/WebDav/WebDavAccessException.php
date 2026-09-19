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
    /**
     * The HTTP status behind this refusal, or 0 when it never got one — a
     * certificate the server would not prove, a connection that died.
     *
     * **Carried because « not there » is not a failure and everything else
     * is.** A caller asking whether an object exists wants null for a 404
     * and an exception for a share that is refusing credentials or simply
     * down: flattening the two answers « non » during an outage, and a
     * copy that believes it then re-uploads everything, or a repatriation
     * decides the source lost a file it still holds.
     */
    public readonly int $status;

    private function __construct(string $message, int $status, ?\Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
    }

    public static function of(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, 0, $previous);
    }

    public static function ofStatus(int $status, string $message, ?\Throwable $previous = null): self
    {
        return new self($message, $status, $previous);
    }

    /**
     * 404, and 409 with it: a WebDAV server answers 409 « Conflict » for a
     * path whose parent collection is missing, which is the same fact
     * about the share — nothing is there — reported one level up.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404 || $this->status === 409;
    }
}
