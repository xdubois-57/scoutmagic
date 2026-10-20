<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Modules\Rental\Repository\RentalStayRepository;

/**
 * Converts the incidents « withheld from the security deposit » into
 * charges of the same amount, once per installation, from the composition
 * root (#980, chantier §2.2).
 *
 * The retired decision kept the same money as a charge, capped at the
 * deposit, so the conversion changes no amount: what was decided, falling
 * back to what was proposed, as it was read. **Settlements are not
 * touched** — a validated version is immutable and keeps its own figures,
 * and `Stay\Settlement::keptCents()` still reads a version recorded before
 * #980 as it was meant.
 *
 * `MigrationRunner` never touches data — every schema file it applies is
 * DDL — so a change of MEANING like this one has nowhere else to live. The
 * shape is `Modules\Leadership\Service\FormationStepMigration`'s: a
 * `settings` flag registered `editable: false`, checked on every request
 * and costing one array lookup once set, since SettingService caches every
 * setting once per request. The UPDATE is idempotent on its own (it matches
 * only rows still on the retired value), so the flag is an optimisation
 * rather than a correctness guard; and a leftover value reads as a charge
 * anyway (`IncidentDecision::fromStored()`).
 */
final class IncidentDecisionMigration
{
    public const SETTING = 'rental_incident_withhold_migrated';

    public function __construct(
        private RentalStayRepository $repository,
        private SettingService $settingService,
        private JournalService $journalService
    ) {
    }

    /** @return int incidents converted by THIS call — 0 once it has run. */
    public function runOnce(): int
    {
        $this->settingService->register(
            self::SETTING,
            '0',
            'boolean',
            'Conversion des incidents retenus sur la caution effectuée',
            'Marque interne : indique que les incidents « À retenir sur la caution » ont été convertis en '
                . '« Facturer », avec le même montant. Jamais modifié à la main.',
            'rental',
            null,
            null,
            false
        );

        if ((string) $this->settingService->get(self::SETTING, 'rental', '0') === '1') {
            return 0;
        }

        $changed = $this->repository->convertWithheldIncidentsToCharges();
        $this->settingService->setInternal(self::SETTING, '1', 'rental');

        if ($changed > 0) {
            // A count, never a description or a booking: the entry sits in
            // a journal, and the descriptions are encrypted precisely
            // because they are about a renter's group (SECURITY.md §5).
            $this->journalService->log(
                'rental',
                'rental_incident_withhold_migrated',
                'info',
                "Incidents « À retenir sur la caution » convertis en « Facturer » : {$changed}",
                ['count' => $changed]
            );
        }

        return $changed;
    }
}
