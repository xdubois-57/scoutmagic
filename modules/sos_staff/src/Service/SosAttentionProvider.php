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
 * Read from stored facts only — the credentials row and the last outcome —
 * never by asking the provider where the number points, which the
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

        $failedAt = $this->settings->lastRedirectFailureAt();
        if ($failedAt === null) {
            return [];
        }

        return [new AttentionPoint(
            title: 'Le dernier changement de redirection du numéro SOS a échoué',
            why: 'Le ' . $failedAt->format('d/m/Y à H:i') . ", le site n'a pas pu rediriger le numéro vers la "
                . "personne de garde, et aucun changement n'a réussi depuis : les appels arrivent peut-être "
                . "encore chez la précédente. Le journal du module donne l'erreur de l'opérateur.",
            actionLabel: "Voir le téléphone d'urgence",
            actionUrl: '/admin/sos',
            severity: AttentionPoint::SEVERITY_URGENT
        )];
    }
}
