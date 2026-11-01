<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

class MailException extends \RuntimeException
{
    /** @param list<string> $attemptFailures Redacted reasons from every attempted delivery. */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly array $attemptFailures = []
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return list<string> */
    public function getAttemptFailures(): array
    {
        return $this->attemptFailures;
    }
}
