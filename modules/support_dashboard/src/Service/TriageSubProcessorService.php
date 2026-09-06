<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Module\SubProcessorProvider;
use Core\Module\SubProcessorView;

/**
 * The processors the triage extract crosses, declared for the receiving
 * installation's RGPD page (AGENTS.md § RGPD, ARCHITECTURE.md
 * §8.49sexies) — and declared only while the triage token is configured,
 * because a route that refuses every call processes nobody's data.
 *
 * Two processors in one view, since they are engaged by one act: the
 * GitHub Actions runner that fetches and unpacks the extract, and the AI
 * provider behind `anthropics/claude-code-action`, which reads it. Named
 * here rather than inspected, because the receiver cannot inspect the
 * repository's workflow; what it can inspect is whether it has issued a
 * token, which is the real precondition.
 */
final class TriageSubProcessorService implements SubProcessorProvider
{
    public function __construct(private TriageTokenService $tokens)
    {
    }

    /**
     * @return list<SubProcessorView>
     */
    public function getSubProcessors(): array
    {
        if (!$this->tokens->isConfigured()) {
            return [];
        }

        return [new SubProcessorView(
            SubProcessorView::CATEGORY_ISSUE_TRIAGE,
            "GitHub, Inc. (États-Unis, hors UE) et Anthropic, PBC (États-Unis, hors UE), fournisseur d'IA du triage "
                . 'automatique des signalements',
            "Lecture d'une copie réduite et anonymisée de l'archive de diagnostic d'un ticket de support, pour le "
                . "triage automatique d'un signalement public qui en cite la référence",
            'Jeton de triage actif : les extraits sont servis à la demande, jamais conservés, chaque envoi inscrit '
                . 'au journal'
        )];
    }
}
