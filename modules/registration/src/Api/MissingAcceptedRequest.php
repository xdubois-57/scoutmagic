<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * An accepted registration request whose child is not in the target year's
 * Desk import — one entry of Api\PreparedImportGaps::$acceptedRequestsMissing.
 *
 * `$status` says which of the module's two readings found it missing:
 * `accepted` — not linked to a member, and no imported member shares its
 * name and birth date; `encoded` — linked to a member (by an import or by
 * hand) who has no active row in the target year.
 */
final class MissingAcceptedRequest
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_ENCODED = 'encoded';

    public function __construct(
        public readonly int $requestId,
        /** The child's name, decrypted in the module's repository. */
        public readonly string $childName,
        /** One of the two constants above. */
        public readonly string $status,
        /** The « section prévue », when one was chosen. */
        public readonly ?int $intendedSectionId
    ) {
    }
}
