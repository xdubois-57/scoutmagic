<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

/**
 * What the triage-extract route answers: the extract, or one identical
 * refusal (ARCHITECTURE.md §8.49sexies).
 *
 * The refusal carries its reason for the JOURNAL and for the tests, never
 * for the caller: the controller answers the same bare 403 whatever the
 * reason, so that nobody outside can tell a wrong token from an unknown
 * reference, a purged archive or a reference another issue already
 * claimed.
 */
final class TriageExtractResult
{
    public const REJECT_INSECURE_TRANSPORT = 'insecure_transport';
    public const REJECT_UNAUTHENTICATED = 'unauthenticated';
    public const REJECT_MALFORMED = 'malformed_request';
    public const REJECT_UNKNOWN_REFERENCE = 'unknown_reference';
    public const REJECT_NO_ARCHIVE = 'no_archive';
    public const REJECT_NO_CONSENT = 'no_consent';
    public const REJECT_ISSUE_MISMATCH = 'issue_mismatch';
    public const REJECT_UNBUILDABLE = 'extract_unbuildable';

    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $rejectionReason,
        public readonly string $bytes,
        public readonly string $reference
    ) {
    }

    public static function accepted(string $reference, string $bytes): self
    {
        return new self(true, null, $bytes, $reference);
    }

    public static function rejected(string $reason): self
    {
        return new self(false, $reason, '', '');
    }
}
