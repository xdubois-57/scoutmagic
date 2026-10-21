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
use Modules\Rental\Booking\BookingPage;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Document\Landlord;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalComplianceRepository;
use Modules\Rental\Repository\RentalStayRepository;

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
 *   thing twice;
 * - **a damage still assessed more than a calendar month after the
 *   departure** (#980, chantier §2.4): the settlement does not validate
 *   and the security deposit stays held meanwhile, while the standard
 *   contract promises it back within the month. The point says the
 *   booking's reference, the asset, how long and what the assessment
 *   waits for — never the renter's name.
 *
 * Registered only inside the module's enabled block of public/index.php,
 * so a site without Locations never runs it.
 */
class RentalAttentionProvider implements AttentionPointProvider
{
    public function __construct(
        private RentalAssetRepository $assets,
        private RentalComplianceRepository $compliance,
        private SettingService $settingService,
        /** Null leaves the damages out (a caller without the stay features). */
        private ?RentalStayRepository $stay = null
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
            $this->expiredCompliance($assets),
            $this->longAssessments($assets)
        );
    }

    /**
     * The incidents still assessed more than a calendar month after their
     * booking's departure, one point each.
     *
     * Bounded: the repository reads only the pending incidents of bookings
     * gone at least 28 days — no calendar month is shorter — and the exact
     * calendar month is decided here, by PHP, whatever the engine.
     *
     * @param array<int, RentalAsset> $assets
     * @return AttentionPoint[]
     */
    private function longAssessments(array $assets): array
    {
        if ($this->stay === null) {
            return [];
        }

        $today = AppClock::now()->setTime(0, 0);
        $abandoned = array_values(array_map(
            static fn(BookingStatus $status): string => $status->value,
            array_filter(BookingStatus::cases(), static fn(BookingStatus $status): bool => $status->isAbandoned())
        ));

        $candidates = $this->stay->findPendingIncidentsDepartedBy(
            $today->modify('-28 days')->format('Y-m-d'),
            $abandoned
        );

        $points = [];
        foreach ($candidates as $row) {
            $asset = $assets[$row['asset_id']] ?? null;
            $departure = DateInput::iso($row['departure_date']);
            if ($asset === null || $departure === null) {
                continue; // archived: nobody lets it any more
            }
            $days = self::daysHeldPastAMonth($departure, $today);
            if ($days === null) {
                continue;
            }

            $points[] = new AttentionPoint(
                title: 'Caution retenue depuis ' . $days . ' jours : ' . self::damageWaiting($row['awaiting']),
                why: 'Réservation ' . $row['reference'] . ' de « ' . $asset->name . ' », partie le '
                    . $departure->format('d/m/Y')
                    . ($row['file_reference'] !== null ? ' — référence du dossier : ' . $row['file_reference'] : '')
                    . ". Tant que l'incident est en cours d'évaluation, le décompte ne se valide pas et la caution "
                    . "n'est pas rendue. Tranchez-le dès que vous savez ce qu'il coûte.",
                actionLabel: "Trancher l'incident",
                actionUrl: '/mes-locations/' . rawurlencode($asset->slug) . '/reservations/' . $row['booking_id']
                    . BookingPage::INVENTORY->pathSuffix() . '#incident-' . $row['incident_id'],
                severity: AttentionPoint::SEVERITY_ATTENTION
            );
        }

        return $points;
    }

    /**
     * How many days since the departure, when today is past one calendar
     * month after it (chantier §2.4, Q8) — null before. A month ends on the
     * same day of the next month, or on its last day when it has none: a
     * departure on 31 January is a month old on 28 (or 29) February, and
     * the point appears the day after.
     */
    public static function daysHeldPastAMonth(\DateTimeImmutable $departure, \DateTimeImmutable $today): ?int
    {
        $departure = $departure->setTime(0, 0);
        $today = $today->setTime(0, 0);

        $nextMonth = $departure->modify('first day of next month');
        $oneMonthLater = $nextMonth->setDate(
            (int) $nextMonth->format('Y'),
            (int) $nextMonth->format('n'),
            min((int) $departure->format('j'), (int) $nextMonth->format('t'))
        );
        if ($today <= $oneMonthLater) {
            return null;
        }

        return (int) $departure->diff($today)->days;
    }

    /**
     * « dégât en attente du devis du menuisier » — what the manager typed
     * in « En attente de… », joined to the sentence the way French joins
     * it (« de le » is « du », « de un » is « d'un »), without the ellipsis
     * or full stop they may have added. Nothing typed: the state itself.
     */
    public static function damageWaiting(?string $awaiting): string
    {
        $text = (string) preg_replace('/[\s.…]+$/u', '', trim((string) $awaiting));
        if ($text === '') {
            return "dégât en cours d'évaluation";
        }

        $lower = mb_strtolower($text);
        foreach (['le ' => 'du ', 'les ' => 'des ', 'un ' => "d'un ", 'une ' => "d'une "] as $article => $joined) {
            if (str_starts_with($lower, $article)) {
                return 'dégât en attente ' . $joined . mb_substr($text, mb_strlen($article));
            }
        }

        $elided = preg_match('/^[aeiouyhàâéèêëîïôûü]/u', $lower) === 1;

        return 'dégât en attente ' . ($elided ? "d'" : 'de ') . $text;
    }

    /**
     * @param array<int, RentalAsset> $assets
     * @return AttentionPoint[]
     */
    private function missingLandlordAddresses(array $assets): array
    {
        $points = [];
        foreach ($assets as $asset) {
            $landlord = Landlord::forAsset($this->settingService, $asset);
            if ($landlord->address !== null) {
                continue;
            }
            $points[] = new AttentionPoint(
                title: "L'adresse du bailleur de « " . $asset->name . ' » est vide',
                why: 'Le contrat et la facture de ce bien imprimeront « — » à sa place. '
                    . self::landlordRemedy($landlord->source),
                actionLabel: 'Renseigner le bailleur',
                actionUrl: '/mes-locations/' . rawurlencode($asset->slug) . '/reglages',
                severity: AttentionPoint::SEVERITY_URGENT
            );
        }

        return $points;
    }

    /**
     * What will actually fill the address, which depends on the level the
     * landlord was resolved at: `Landlord` takes a level whole as soon as
     * its name OR its address is written, so a level holding a name and no
     * address is never completed by the level below it. Telling the
     * manager of an asset whose own landlord is named to fill the unit's
     * address would leave this point standing with no reason given.
     */
    private static function landlordRemedy(string $source): string
    {
        return match ($source) {
            Landlord::SOURCE_ASSET => 'Ce bien a son propre bailleur, sans adresse : renseignez-la dans les '
                . 'réglages du bien.',
            Landlord::SOURCE_MODULE => 'Le bailleur commun à toutes les locations a un nom mais pas '
                . "d'adresse : demandez à un administrateur de la renseigner dans Configuration › Paramètres, "
                . 'section Locations, ou donnez à ce bien un bailleur propre.',
            default => 'Renseignez le bailleur de ce bien, ou demandez à un administrateur de renseigner '
                . "l'adresse postale de l'unité dans les paramètres du site.",
        };
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
