<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Config\AppClock;
use Core\Config\SettingService;
use Core\Service\DateInput;
use Modules\Rental\Document\Landlord;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalComplianceRepository;

/**
 * What the Locations module knows is wrong and no other page says
 * (issue #718 § C points 1 and 7), for every asset not archived:
 *
 * - **no landlord address** — the contract and the invoice of that asset
 *   print « — » where it belongs. Until now that warning only appeared on
 *   a booking's Documents tab and when a document was generated, which is
 *   to say once a renter was already waiting for the paper;
 * - **a compliance entry already expired**. Only expired: the register's
 *   own reminder (`RentalComplianceService::dueForReminder()`) already
 *   speaks up before the date, and a point before it would say the same
 *   thing twice.
 *
 * Registered only inside the module's enabled block of public/index.php,
 * so a site without Locations never runs it.
 */
class RentalAttentionProvider implements AttentionPointProvider
{
    public function __construct(
        private RentalAssetRepository $assets,
        private RentalComplianceRepository $compliance,
        private SettingService $settingService
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Locations';
    }

    public function collect(int $scoutYearId): array
    {
        $assets = [];
        foreach ($this->assets->findAllActive() as $asset) {
            $assets[$asset->id] = $asset;
        }
        if ($assets === []) {
            return [];
        }

        return array_merge(
            $this->missingLandlordAddresses($assets),
            $this->expiredCompliance($assets)
        );
    }

    /**
     * @param array<int, RentalAsset> $assets
     * @return AttentionPoint[]
     */
    private function missingLandlordAddresses(array $assets): array
    {
        $points = [];
        foreach ($assets as $asset) {
            if (Landlord::forAsset($this->settingService, $asset)->address !== null) {
                continue;
            }
            $points[] = new AttentionPoint(
                title: "L'adresse du bailleur de « " . $asset->name . ' » est vide',
                why: 'Le contrat et la facture de ce bien imprimeront « — » à sa place. Renseignez le bailleur '
                    . "de ce bien, ou demandez à un administrateur de renseigner l'adresse postale de l'unité "
                    . 'dans les paramètres du site.',
                actionLabel: 'Renseigner le bailleur',
                actionUrl: '/mes-locations/' . rawurlencode($asset->slug) . '/reglages',
                severity: AttentionPoint::SEVERITY_URGENT
            );
        }

        return $points;
    }

    /**
     * @param array<int, RentalAsset> $assets
     * @return AttentionPoint[]
     */
    private function expiredCompliance(array $assets): array
    {
        $yesterday = AppClock::now()->setTime(0, 0)->modify('-1 day')->format('Y-m-d');

        $points = [];
        foreach ($this->compliance->findExpiringBy($yesterday) as $item) {
            $asset = $assets[$item->assetId] ?? null;
            if ($asset === null) {
                continue; // archived: nobody lets it any more
            }
            $expired = $item->expiresOn !== null ? DateInput::iso($item->expiresOn) : null;
            $points[] = new AttentionPoint(
                title: '« ' . $item->label . ' » de « ' . $asset->name . ' » a expiré',
                // Worded around « cette entrée » rather than a pronoun: the
                // label is free text, masculine as often as feminine
                // (« Label Atouts Camps », « Numéro d'enregistrement »).
                why: 'Selon le registre de conformité de ce bien, cette entrée était valable jusqu\'au '
                    . ($expired !== null ? $expired->format('d/m/Y') : (string) $item->expiresOn)
                    . '. Renouvelez le document et joignez le nouveau, ou corrigez la date si elle est fausse.',
                actionLabel: 'Voir la conformité',
                actionUrl: '/mes-locations/' . rawurlencode($asset->slug) . '/conformite',
                dueDate: $expired,
                severity: AttentionPoint::SEVERITY_ATTENTION
            );
        }

        return $points;
    }
}
