<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * One reason a Desk export is refused: the line, the rule, and the French
 * sentence the administrator who deposited the file reads.
 *
 * The sentence names the line, the Tiers code, the column and — except for
 * Nom and Prenom — the cell found there. It never names a member. It is
 * shown on the import page only, never journaled: the journal counts
 * refusals per rule and nothing else (SECURITY.md §13).
 */
final class DeskImportError
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly DeskImportRule $rule,
        public readonly string $message
    ) {
    }
}
