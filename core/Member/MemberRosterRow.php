<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Member\Movement\MemberMovementResult;

/**
 * One display-ready row for the section-roster page — already decrypted by
 * SectionRosterService, never in Twig or the Controller (SECURITY.md §5).
 */
final class MemberRosterRow
{
    /**
     * @param string[] $emails every known email, primary (Desk) first, in
     *        a stable order — never just "the first one"
     * @param array<int, array{label: string, value: string}> $phones
     */
    public function __construct(
        public readonly int $memberYearId,
        public readonly int $memberId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $totem,
        public readonly string $functionLabel,
        public readonly string $bucket,
        public readonly array $emails,
        public readonly array $phones,
        public readonly MemberMovementResult $movement
    ) {
    }
}
