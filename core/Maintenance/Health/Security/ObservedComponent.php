<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * One component in one execution context, as the host reported it — a
 * measured fact, before any reference data is consulted (issue #1358).
 *
 * Three presences, never two: `present` with the exact version string,
 * `absent` when its absence was measured (the extension is not loaded),
 * and `unknown` when nothing recent enough measured it. An unknown
 * component is « non vérifiable » in the box; an absent one is not in the
 * box at all.
 */
final class ObservedComponent
{
    public const PRESENT = 'present';
    public const ABSENT = 'absent';
    public const UNKNOWN = 'unknown';

    public function __construct(
        /** The stable subject key — {@see HostInventory::SUBJECTS}. */
        public readonly string $subject,
        public readonly string $family,
        public readonly string $presence,
        /** The version exactly as reported, build suffix included. */
        public readonly ?string $version,
        /** When the process that runs it measured it. */
        public readonly ?int $measuredAt,
        /** Why a component is unknown, in French, or a detail worth printing beside it. */
        public readonly string $note = '',
    ) {
    }

    public function label(): string
    {
        return HostInventory::SUBJECTS[$this->subject] ?? $this->subject;
    }
}
