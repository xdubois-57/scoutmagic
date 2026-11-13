<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;

/**
 * The telephony provider behind the SOS number, not configured or failing
 * (issue #718 § C point 9).
 *
 * - **Not configured**, while the module is on: no active provider with
 *   every field it needs (ProviderConfigService::getActiveProvider()).
 *   Attention — the duty planning works, the number simply never follows it.
 * - **In trouble**: the last redirect change failed and none has succeeded
 *   since (SosSettingsService::lastRedirectFailureAt()). Urgent: calls may
 *   still ring for whoever held the duty before.
 *
 * - **A default number that no longer holds**, or **Staff d'U GSMs that
 *   are no phone number** (issue #1125): read from the roster of the year,
 *   so a Desk import or a new scout year is taken into account at once.
 *
 * Read from stored facts only — the credentials row, the last outcome and
 * the roster — never by asking the provider where the number points, which the
 * administration page does live and this page must not.
 */
class SosAttentionProvider implements AttentionPointProvider
{
    public function __construct(
        private ProviderConfigService $providerConfig,
        private SosSettingsService $settings
    ) {
    }

    public function sourceLabel(): string
    {
        return "Téléphone d'urgence";
    }

    public function collect(int $scoutYearId): array
    {
        if ($this->providerConfig->getActiveProvider() === null) {
            return [new AttentionPoint(
                title: "Aucun opérateur téléphonique n'est configuré pour le numéro SOS",
                why: 'Le module est activé et la garde peut être planifiée, mais le site ne peut pas rediriger '
                    . "le numéro SOS : il sonne toujours au même endroit, quelle que soit la personne de garde.",
                actionLabel: "Configurer l'opérateur",
                actionUrl: '/config/sos',
                severity: AttentionPoint::SEVERITY_ATTENTION
            )];
        }

        $points = [];

        $failedAt = $this->settings->lastRedirectFailureAt();
        if ($failedAt !== null) {
            $points[] = new AttentionPoint(
                title: 'Le dernier changement de redirection du numéro SOS a échoué',
                why: 'Le ' . $failedAt->format('d/m/Y à H:i') . ", le site n'a pas pu rediriger le numéro vers la "
                    . "personne de garde, et aucun changement n'a réussi depuis : les appels arrivent peut-être "
                    . "encore chez la précédente. Le journal du module donne l'erreur de l'opérateur.",
                actionLabel: "Voir le téléphone d'urgence",
                actionUrl: '/admin/sos',
                severity: AttentionPoint::SEVERITY_URGENT
            );
        }

        $stale = $this->staleDefaultNumberPoint($scoutYearId);
        if ($stale !== null) {
            $points[] = $stale;
        }

        $unusable = $this->settings->getStaffWithUnusableMobile($scoutYearId);
        if ($unusable !== []) {
            $points[] = new AttentionPoint(
                title: count($unusable) === 1
                    ? "Un GSM du Staff d'U n'est pas un numéro de téléphone valide"
                    : count($unusable) . " GSM du Staff d'U ne sont pas des numéros de téléphone valides",
                why: 'Dans Desk : ' . implode(', ', array_column($unusable, 'label')) . '. Le numéro SOS ne '
                    . "peut pas être redirigé vers ces personnes : elles n'apparaissent pas dans le planning de "
                    . 'garde. Corrigez le GSM dans Desk, puis réimportez.',
                actionLabel: "Voir le téléphone d'urgence",
                actionUrl: '/admin/sos',
                severity: AttentionPoint::SEVERITY_ATTENTION
            );
        }

        return $points;
    }

    /**
     * The default number a chef d'unité chose no longer holds (issue
     * #1125): the line has fallen back on somebody else, and stays there
     * until somebody confirms or changes the choice.
     */
    private function staleDefaultNumberPoint(int $scoutYearId): ?AttentionPoint
    {
        $staleId = $this->settings->staleDefaultNumberMemberId($scoutYearId);
        if ($staleId === null) {
            return null;
        }

        $chosen = $this->settings->labelForMember($staleId, $scoutYearId) ?? 'La personne choisie';
        $usedId = $this->settings->resolveDefaultNumberMemberId($scoutYearId);
        $used = $usedId !== null ? $this->settings->labelForMember($usedId, $scoutYearId) : null;

        return new AttentionPoint(
            title: "Le numéro par défaut choisi pour le numéro SOS n'est plus valable",
            why: $chosen . " ne fait plus partie du Staff d'U de cette année avec un GSM utilisable. "
                . ($used !== null
                    ? "Quand personne n'est de garde, les appels arrivent désormais chez {$used}."
                    : "Quand personne n'est de garde, aucun numéro ne peut recevoir les appels.")
                . ' Confirmez ce choix ou changez-le dans les réglages.',
            actionLabel: 'Choisir le numéro par défaut',
            actionUrl: '/admin/sos/reglages',
            severity: $used !== null ? AttentionPoint::SEVERITY_ATTENTION : AttentionPoint::SEVERITY_URGENT
        );
    }
}
