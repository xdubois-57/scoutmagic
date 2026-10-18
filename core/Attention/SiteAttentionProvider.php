<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Attention;

use Core\Config\AppClock;
use Core\Config\SettingService;
use Core\Config\UnitAddresses;
use Core\Import\ImportJournalRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Service\DateInput;

/**
 * What the site itself is missing, as opposed to the unit's roster
 * (CoreAttentionProvider): the points of issue #718 § C that depend on no
 * module, and the ones a new installation shows first.
 *
 * - the public scout year has ended and nobody has switched to the next;
 * - no Desk import on record, or none for too long;
 * - the unit's postal address is empty.
 *
 * A separate contributor from CoreAttentionProvider on purpose: that one
 * describes the members of a year, this one the site's configuration, and
 * a test of « a healthy unit raises nothing » should not have to set up a
 * whole configured site to stay true.
 *
 * Everything here reads stored facts — a setting, a row, a date. Nothing
 * is fetched, and the page stays as cheap as AttentionPointProvider asks.
 */
class SiteAttentionProvider implements AttentionPointProvider
{
    /**
     * After how many days without a Desk import the site says so — a
     * setting of Configuration › Paramètres, issue #718 § C point 4.
     */
    public const STALE_IMPORT_SETTING_KEY = 'desk_import_stale_days';
    public const DEFAULT_STALE_IMPORT_DAYS = 60;

    public function __construct(
        private ScoutYearResolver $scoutYearResolver,
        private ImportJournalRepository $importJournal,
        private SettingService $settingService
    ) {
    }

    public static function register(SettingService $settingService): void
    {
        $settingService->register(
            self::STALE_IMPORT_SETTING_KEY,
            (string) self::DEFAULT_STALE_IMPORT_DAYS,
            'number',
            'Délai avant de signaler un import Desk ancien (jours)',
            'Nombre de jours sans import Desk après lequel la page Points d\'attention le signale. Le site ne '
                . 'connaît que ce que Desk lui a donné au dernier import : arrivées, départs et changements de '
                . 'fonction postérieurs lui échappent.',
            null,
            '^[1-9][0-9]{0,3}$',
            null,
            true,
            262
        );
    }

    public function sourceLabel(): string
    {
        return 'Site';
    }

    public function collect(int $scoutYearId): array
    {
        $today = AppClock::now()->setTime(0, 0);

        return array_merge(
            $this->endedPublicYear($today),
            $this->deskImport($today),
            $this->missingPostalAddress()
        );
    }

    /** @return AttentionPoint[] */
    private function endedPublicYear(\DateTimeImmutable $today): array
    {
        $year = $this->scoutYearResolver->getCurrentPublicYear();
        // Same comparison as the warning on /admin/scout-year
        // (ScoutYearController::index()): dates as Y-m-d strings.
        if ($year['end_date'] >= $today->format('Y-m-d')) {
            return [];
        }

        $ended = DateInput::iso($year['end_date']);

        return [new AttentionPoint(
            title: "L'année publique " . $year['label'] . ' est terminée',
            why: 'Elle a pris fin le ' . ($ended !== null ? $ended->format('d/m/Y') : $year['end_date'])
                . ", mais le site continue à l'afficher tant que personne n'a basculé vers l'année suivante.",
            actionLabel: "Basculer vers l'année suivante",
            actionUrl: '/admin/scout-year',
            severity: AttentionPoint::SEVERITY_URGENT
        )];
    }

    /** @return AttentionPoint[] */
    private function deskImport(\DateTimeImmutable $today): array
    {
        $latest = $this->importJournal->findLatestImportedAt();
        if ($latest === null) {
            return [new AttentionPoint(
                title: "Aucun import Desk n'est enregistré",
                why: "Le site tire de Desk ses membres, ses sections et ses fonctions. Sans import, il n'en "
                    . "connaît aucun, et les pages qui en dépendent restent vides.",
                actionLabel: 'Importer depuis Desk',
                actionUrl: '/admin/import',
                severity: AttentionPoint::SEVERITY_URGENT
            )];
        }

        $days = (int) $latest->setTime(0, 0)->diff($today)->format('%r%a');
        if ($days < $this->staleImportDays()) {
            return [];
        }

        return [new AttentionPoint(
            title: 'Le dernier import Desk date de ' . $days . ' jours',
            why: 'Le site ne connaît que ce que Desk lui a donné le ' . $latest->format('d/m/Y')
                . ' : les arrivées, les départs et les changements de fonction depuis lui échappent.',
            actionLabel: 'Importer depuis Desk',
            actionUrl: '/admin/import',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }

    /** @return AttentionPoint[] */
    private function missingPostalAddress(): array
    {
        if (UnitAddresses::postalAddress($this->settingService) !== null) {
            return [];
        }

        return [new AttentionPoint(
            title: "L'adresse postale de l'unité n'est pas renseignée",
            why: "C'est l'adresse que les documents officiels impriment sous le nom de l'unité, par exemple "
                . "le contrat et la facture d'une location quand le bien n'a pas de bailleur propre. Tant "
                . "qu'elle est vide, ils impriment « — » à sa place.",
            actionLabel: "Renseigner l'adresse",
            actionUrl: '/config/settings',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }

    private function staleImportDays(): int
    {
        $days = (int) $this->settingService->get(self::STALE_IMPORT_SETTING_KEY);

        return $days > 0 ? $days : self::DEFAULT_STALE_IMPORT_DAYS;
    }
}
