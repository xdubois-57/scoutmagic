<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * One scout year a Desk import may target today, as
 * {@see DeskImportYearPolicy} decided it.
 *
 * $confirmation says whether the import page has to ask before importing
 * into it, and why — the server then demands the year back in
 * `confirmed_year`, so a forged or replayed POST without it is refused.
 */
final class DeskImportYearOption
{
    /** The public year, while the next one is already importable: importing changes what the families see. */
    public const CONFIRM_PUBLIC_DURING_WINDOW = 'public_during_window';

    /** A year outside the usual rule, offered only because configuration mode is on. */
    public const CONFIRM_CONFIGURATION_MODE = 'configuration_mode';

    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly bool $isPublic,
        public readonly bool $isNext,
        /** One of the CONFIRM_* constants, or null when the year needs no confirmation. */
        public readonly ?string $confirmation
    ) {
    }

    public function needsConfirmation(): bool
    {
        return $this->confirmation !== null;
    }
}
