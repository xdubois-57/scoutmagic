<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

use Core\Import\ImportRecord;

/**
 * Everything a step check may depend on that is not its own business,
 * computed once per request by ScoutYearTransitionService::contextFor()
 * and handed to every StepCheckProvider.
 *
 * Built from server state only — the public year, the staff year, the
 * caller's own session preview — never from anything the browser sends:
 * the JSON fragment of the page and the page itself must be computed for
 * the same person and the same target year.
 *
 * $latestTargetImport is the most recent row of import_journal for the
 * target year. That table is written only inside a successful import's
 * transaction, so « a row exists » IS « a successful import exists »:
 * there is no status column to consult, and none must be added.
 */
final class StepContext
{
    /**
     * @param array{id: int, label: string, start_date: string, end_date: string} $targetYear
     * @param array{id: int, label: string, start_date: string, end_date: string} $publicYear
     */
    public function __construct(
        public readonly array $targetYear,
        public readonly array $publicYear,
        public readonly ?int $staffYearId,
        public readonly ?int $sessionPreviewId,
        public readonly \DateTimeImmutable $now,
        public readonly ?ImportRecord $latestTargetImport
    ) {
    }

    public function targetId(): int
    {
        return (int) $this->targetYear['id'];
    }

    public function targetLabel(): string
    {
        return (string) $this->targetYear['label'];
    }

    public function publicId(): int
    {
        return (int) $this->publicYear['id'];
    }

    public function publicLabel(): string
    {
        return (string) $this->publicYear['label'];
    }

    public function hasSuccessfulImport(): bool
    {
        return $this->latestTargetImport !== null;
    }

    /**
     * What a check that reads the target year's data says before there is
     * any: never a success, never a point to check.
     */
    public function waitingForImportMessage(): string
    {
        return "Le contrôle sera disponible après un import réussi de {$this->targetLabel()}.";
    }

    /** The Import Desk page, opened on the target year (the page decides whether it may preselect it). */
    public function importUrl(): string
    {
        return '/admin/import?annee=' . $this->targetId();
    }
}
