<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

/**
 * What a StatisticsTransportInterface implementation reports back: an HTTP
 * status and body, or a transport-level error with no status at all
 * (DNS failure, refused connection, timeout).
 */
final class StatisticsTransportResponse
{
    private function __construct(
        public readonly ?int $statusCode,
        public readonly string $body,
        public readonly ?string $errorMessage
    ) {
    }

    public static function response(int $statusCode, string $body): self
    {
        return new self($statusCode, $body, null);
    }

    public static function transportError(string $message): self
    {
        return new self(null, '', $message);
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
