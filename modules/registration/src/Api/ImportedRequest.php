<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * One request of an old site, as Api\RequestImportWriter files it — every
 * reference already resolved to this site's ids by the caller (the scout
 * year by its label, the sections and the fee category by their Desk code,
 * the members by their Desk id), so that nothing here knows the archive's
 * format.
 *
 * `$fields` are the request's own fields under the public form's keys —
 * `parent_name`, `child_last_name`, `child_first_name`, `gender`,
 * `birth_date`, `street`, `number`, `postal_code`, `city`, `email`,
 * `phone1`, `phone2`, `remarks`, `previous_unit_answer`,
 * `previous_unit_name` — as the archive gave them: the writer trims them
 * and refuses a value that is not a string.
 *
 * `$linkedMemberId` is read for an `encoded` request only, which requires
 * it; `$refusalReason` for a `refused` one only.
 */
final class ImportedRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_ENCODED = 'encoded';

    /** The statuses an imported request may carry — the module's own, and no other. */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REFUSED,
        self::STATUS_WITHDRAWN,
        self::STATUS_ENCODED,
    ];

    /** The two reasons a refusal may carry: « Pas de place » and « Autre ». */
    public const REFUSAL_REASONS = ['no_room', 'other'];

    /**
     * @param array<string, mixed> $fields
     * @param list<int> $siblingMemberIds members.id of the declared siblings
     * @param list<ImportedSecondaryEmail> $secondaryEmails
     */
    public function __construct(
        public readonly int $scoutYearId,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly string $status,
        public readonly array $fields,
        public readonly ?string $refusalReason = null,
        public readonly ?\DateTimeImmutable $decidedAt = null,
        public readonly ?int $desiredSectionId = null,
        public readonly ?int $intendedSectionId = null,
        public readonly ?int $feeCategoryId = null,
        public readonly ?string $internalNotes = null,
        public readonly array $siblingMemberIds = [],
        public readonly ?int $linkedMemberId = null,
        public readonly array $secondaryEmails = []
    ) {
    }
}
