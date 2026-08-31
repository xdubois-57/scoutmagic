<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Modules\Leadership\Repository\FormationLevelMappingRepository;

/**
 * Runs the one-off reclassification of the module's vocabulary mapping
 * once per installation, from the composition root (roadmap IT-19).
 *
 * `MigrationRunner` never touches data — every schema file it applies is
 * DDL — so a change of MEANING like this one has nowhere else to live.
 * The shape is the one the codebase already uses for exactly this
 * (`Core\Member\AddressBlindIndexBackfill` in its time, and the finance
 * receipt-ownership backfill since): a `settings` flag registered
 * `editable: false`, checked on every request and costing one array
 * lookup once set, because SettingService caches every setting once per
 * request. A backfill that only runs when somebody opens the right page
 * leaves the hole open on every installation where nobody does.
 *
 * The underlying statements are idempotent on their own
 * (`FormationLevelMappingRepository::reclassifyLegacyBrevetRows()` only
 * matches rows still on the legacy box), so the flag is an optimisation
 * rather than a correctness guard — losing it would cost two no-op
 * UPDATEs per request, never a wrong reclassification.
 */
final class FormationStepMigration
{
    public const SETTING = 'leadership_formation_steps_migrated';

    public function __construct(
        private FormationLevelMappingRepository $repository,
        private SettingService $settingService,
        private JournalService $journalService
    ) {
    }

    /** @return int rows reclassified by THIS call — 0 once it has run. */
    public function runOnce(): int
    {
        $this->settingService->register(
            self::SETTING,
            '0',
            'boolean',
            'Reclassement des niveaux de formation effectué',
            "Marque interne : indique que les rattachements « brevet » enregistrés avant l'apparition des cases BACV et Woodbadge ont été reclassés. Jamais modifié à la main.",
            'leadership',
            null,
            null,
            false
        );

        if ((string) $this->settingService->get(self::SETTING, 'leadership', '0') === '1') {
            return 0;
        }

        $changed = $this->repository->reclassifyLegacyBrevetRows();
        $this->settingService->setInternal(self::SETTING, '1', 'leadership');

        if ($changed > 0) {
            // A count, never the wordings: the entry sits in a journal a
            // chief reads, and the Formations page lists exactly who holds
            // each value (SECURITY.md §11, same reasoning as
            // Controller\FormationMappingController::journal()).
            $this->journalService->log(
                'leadership',
                'leadership_formation_steps_migrated',
                'info',
                "Rattachements de niveau de formation reclassés en BACV ou Woodbadge : {$changed}",
                ['count' => $changed]
            );
        }

        return $changed;
    }
}
