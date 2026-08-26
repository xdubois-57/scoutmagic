<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Exception\UserFacingException;

/**
 * A refused campaign file. **Nothing is created**: not the campaign, not
 * one receivable, not one row — a campaign half-imported is worse than
 * one not imported, because the missing half is invisible.
 *
 * $lines carries every offending line at once, each with what the file
 * actually says on it, so the treasurer fixes the spreadsheet in one
 * pass instead of one error per upload.
 *
 * Marked {@see UserFacingException}: every message here is a French
 * sentence about the treasurer's own spreadsheet — a line number, a
 * column header, a value they typed. Nothing internal appears in one.
 */
class CampaignImportException extends \Exception implements UserFacingException
{
    /**
     * @param array<int, array{line: int, content: string, problem: string}> $lines
     */
    public function __construct(
        public readonly array $lines,
        string $message = "Le chargement a été refusé : aucune campagne n'a été créée."
    ) {
        parent::__construct($message);
    }

    /**
     * A refusal about the file as a whole — a missing column, an
     * unreadable file — rather than about particular lines.
     */
    public static function file(string $message): self
    {
        return new self([], $message);
    }
}
