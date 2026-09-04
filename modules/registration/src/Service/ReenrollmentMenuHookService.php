<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Module\MenuEntry;
use Core\Module\MenuEntryProvider;
use Core\ScoutYear\ScoutYearResolver;
use Core\View\MenuBuilder;

/**
 * The « Réinscription {année} » entry of the Espace des animés.
 *
 * A second `MenuEntryProvider` rather than another branch inside
 * `RegistrationMenuHookService`: that one answers for a family's own
 * registration REQUESTS, one entry each; this one is a single page and
 * asks a different question of the same visitor. Two small providers read
 * better than one that does both, and the registrar takes a list.
 *
 * **Shown only to somebody who actually has an animé.** A visitor with no
 * child in this year's roster — an animateur, a former parent — has
 * nothing to answer, and an entry leading to an empty page is worse than
 * no entry. That is also the cheapest possible check: the page's own
 * cards decide, so the menu and the page can never disagree.
 *
 * **Before the campaign opens, the entry is not there.** Asking a family
 * to answer a question the unit has not asked yet is noise, and the
 * opening e-mail is what tells them the question exists.
 *
 * **After it closes, the entry stays.** The page turns read-only rather
 * than disappearing: a family who answered and then finds the page gone
 * would reasonably conclude their answer went with it.
 */
class ReenrollmentMenuHookService implements MenuEntryProvider
{
    public function __construct(
        private ReenrollmentFormService $formService,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService,
        private ReenrollmentCampaignService $campaign
    ) {
    }

    /**
     * @return array<int, MenuEntry>
     */
    public function getMenuEntries(?string $email): array
    {
        if ($email === null) {
            return [];
        }

        // Never opened yet — nothing to point at. Once a campaign has run,
        // the closed page is still worth reaching (see the class docblock).
        if (!$this->campaign->isOpen() && $this->campaign->currentCampaignKey() === null) {
            return [];
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $targetLabel = ScoutYearService::nextLabel((string) $publicYear['label']);
        $targetYearId = $this->scoutYearService->ensureYear($targetLabel);

        $cards = $this->formService->cardsFor(
            $email,
            (int) $publicYear['id'],
            (string) $publicYear['label'],
            $targetYearId
        );
        if ($cards === []) {
            return [];
        }

        $unanswered = 0;
        foreach ($cards as $card) {
            if ($card['answer'] === null) {
                $unanswered++;
            }
        }

        return [
            new MenuEntry(
                MenuBuilder::MENU_ESPACE_ANIMES,
                'Réinscription ' . $targetLabel,
                '/reinscription',
                'identified',
                // After the per-member entries and after the registration
                // requests, which use 1000 and up: this is a page about
                // next year, not one of the visitor's own members.
                2000,
                true,
                $this->campaign->isOpen()
                    ? ($unanswered > 0
                        ? $unanswered
                            . ' réponse'
                            . ($unanswered > 1 ? 's' : '')
                            . ' attendue'
                            . ($unanswered > 1 ? 's' : '')
                        : 'Réponse enregistrée')
                    : 'Campagne clôturée',
                MenuBuilder::SORT_GROUP_DYNAMIC,
                'bi-arrow-repeat',
                'mes_membres'
            ),
        ];
    }
}
