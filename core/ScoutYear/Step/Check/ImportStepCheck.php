<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;
use Core\Security\UserAccountRepository;

/**
 * Step « import », observed: done once import_journal holds an import of
 * the target year — the latest successful one, with its date, its author
 * and its member count. Not « the year has members »: a member row
 * can exist without any import (a temporary member), an import row cannot.
 */
final class ImportStepCheck implements StepCheckProvider
{
    public function __construct(private UserAccountRepository $userAccountRepository)
    {
    }

    public function stepKey(): string
    {
        return 'import';
    }

    public function check(StepContext $context): StepCheck
    {
        $import = $context->latestTargetImport;
        if ($import === null) {
            $action = StepAction::linkTo('import', "Aller à l'import Desk ({$context->targetLabel()})", $context->importUrl());

            return new StepCheck(observedDone: false, primaryAction: $action, actions: [$action]);
        }

        $report = StepAction::linkTo('import_report', 'Voir le rapport', "/admin/import/{$import->id}/rapport");
        $again = StepAction::linkTo('import', 'Importer à nouveau', $context->importUrl());

        return new StepCheck(
            observedDone: true,
            primaryAction: $report,
            actions: [$report, $again],
            details: [
                'imported_at' => $import->importedAt,
                'imported_by' => $this->importerName($import->userAccountId),
                'member_count' => $import->memberCount,
            ]
        );
    }

    private function importerName(?int $userAccountId): ?string
    {
        if ($userAccountId === null) {
            return null;
        }

        $name = $this->userAccountRepository->findNamesByIds([$userAccountId])[$userAccountId] ?? null;
        $full = trim(($name['first_name'] ?? '') . ' ' . ($name['last_name'] ?? ''));

        return $full !== '' ? $full : null;
    }
}
