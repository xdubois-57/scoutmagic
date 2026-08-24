<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Modules\Leadership\FormationStep;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Twig\Environment;

/**
 * The module's only write: attaching a raw Desk formation level to a
 * normalised step, from the collapsible block at the bottom of the
 * Formations page.
 *
 * There is no configuration page for this, deliberately. The mapping only
 * matters because of the numbers it changes, so it is edited where those
 * numbers are — a chief who has just read "le calcul peut être incomplet"
 * scrolls down and fixes it, instead of being sent to a settings screen
 * where the sentence that sent them there is no longer visible.
 */
class FormationMappingController extends AbstractController
{
    private const REDIRECT_TO = '/admin/leadership/training';

    public function __construct(
        protected Environment $twig,
        private FormationLevelMappingRepository $repository,
        private JournalService $journalService
    ) {
    }

    /**
     * POST /admin/leadership/training/mapping
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::REDIRECT_TO)) !== null) {
            return $guard;
        }

        $rawValue = trim((string) $request->getBody('raw_value', ''));
        $stepValue = (string) $request->getBody('step', '');

        if ($rawValue === '') {
            FlashMessage::set('error', 'Aucune valeur à rattacher.');

            return $this->redirect(self::REDIRECT_TO);
        }

        // An empty step is "forget this decision", which is how a mistake is
        // undone: the value goes back to whatever the built-in heuristic
        // makes of it, unrecognised included.
        if ($stepValue === '') {
            $this->repository->delete($rawValue);
            $this->journal('leadership_formation_mapping_removed');
            FlashMessage::set('success', 'Rattachement supprimé.');

            return $this->redirect(self::REDIRECT_TO);
        }

        $step = FormationStep::tryFrom($stepValue);

        // 'unknown' is a valid FormationStep but not an assignable one: it
        // is what the site says when nobody has decided, never a decision.
        // Checking membership of assignable() rather than trusting
        // tryFrom() is what keeps a hand-crafted POST from storing it.
        if ($step === null || !in_array($step, FormationStep::assignable(), true)) {
            FlashMessage::set('error', 'Étape de formation inconnue.');

            return $this->redirect(self::REDIRECT_TO);
        }

        $this->repository->save($rawValue, $step);
        $this->journal('leadership_formation_mapping_saved');
        FlashMessage::set('success', 'Niveau de formation rattaché.');

        return $this->redirect(self::REDIRECT_TO);
    }

    /**
     * Journalled without the raw value or the step.
     *
     * A Desk formation level is not itself personal data, but the entry
     * would sit in a chief-readable journal next to a timestamp, and the
     * page it was made from lists exactly who holds that value — which
     * makes the pair a good deal more identifying than either half. The
     * count of such edits is what an audit needs; the content is on the
     * page for anyone entitled to see it (SECURITY.md §11).
     */
    private function journal(string $type): void
    {
        $this->journalService->log(
            'leadership',
            $type,
            'info',
            'Rattachement de niveau de formation modifié',
            [],
            AuthSession::getUserAccountId()
        );
    }
}
