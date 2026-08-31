<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

/**
 * The verdict on one incoming ticket (roadmap IT-23).
 *
 * **Two shapes of "no", and the difference is the whole design.** A bad or
 * absent signature is a 403 — the caller is not who it says it is, and
 * nothing about the request is worth processing. Everything else answers
 * **200 with a refusal in the body**: a client that receives a non-2xx
 * retries, and there is nothing to retry about a category that does not
 * exist or a quota already spent. Same reasoning as the GitHub webhook.
 *
 * The reason is a category, never free text: it is journaled, and a
 * rejected caller learns only what it can act on.
 */
final class TicketIntakeResult
{
    public const REJECT_INSECURE_TRANSPORT = 'insecure_transport';
    public const REJECT_PAYLOAD_TOO_LARGE = 'payload_too_large';
    public const REJECT_RATE_LIMITED = 'rate_limited';
    public const REJECT_MALFORMED = 'malformed_payload';
    public const REJECT_UNKNOWN_CATEGORY = 'unknown_category';
    /** Missing, malformed or wrong — never said which. */
    public const REJECT_UNAUTHENTICATED = 'unauthenticated';

    private function __construct(
        public readonly bool $accepted,
        public readonly int $statusCode,
        public readonly ?string $rejectionReason = null,
        /**
         * What the instance is told, and what a maintainer quotes back in
         * their reply. Never the row id: an instance learning « ticket 41 »
         * learns how many tickets this receiver has had.
         */
        public readonly ?string $ticketReference = null
    ) {
    }

    public static function accepted(string $ticketReference): self
    {
        return new self(true, 200, null, $ticketReference);
    }

    /** A refusal the caller can do something about, or nothing about. */
    public static function refused(string $reason): self
    {
        return new self(false, 200, $reason);
    }

    /**
     * The one rejection that is not a 200: whoever called is not
     * authenticated. Absent and wrong credentials are the same answer, so
     * a caller can never learn which installation ids exist.
     */
    public static function unauthenticated(): self
    {
        return new self(false, 403, self::REJECT_UNAUTHENTICATED);
    }
}
