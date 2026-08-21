<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

/**
 * The verdict on one incoming report. The reason is a **category**, never
 * a free-text detail: it is journaled next to the source IP, and a rejected
 * caller learns only the HTTP status.
 */
final class StatisticsIntakeResult
{
    public const REJECT_INSECURE_TRANSPORT = 'insecure_transport';
    public const REJECT_PAYLOAD_TOO_LARGE = 'payload_too_large';
    public const REJECT_RATE_LIMITED = 'rate_limited';
    public const REJECT_MALFORMED = 'malformed_payload';
    public const REJECT_MISSING_CREDENTIALS = 'missing_credentials';
    public const REJECT_BAD_CREDENTIALS = 'bad_credentials';

    /**
     * @param array<int, string> $unknownFields
     */
    private function __construct(
        public readonly bool $accepted,
        public readonly int $statusCode,
        public readonly ?string $rejectionReason = null,
        public readonly bool $firstRegistration = false,
        public readonly array $unknownFields = []
    ) {
    }

    /**
     * @param array<int, string> $unknownFields
     */
    public static function accepted(bool $firstRegistration, array $unknownFields = []): self
    {
        return new self(true, 204, null, $firstRegistration, $unknownFields);
    }

    public static function rejected(string $reason, int $statusCode): self
    {
        return new self(false, $statusCode, $reason);
    }
}
