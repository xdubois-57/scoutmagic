<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Modules\Leadership\FormationStep;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Twig\Environment;

/**
 * The module's only write: attaching a raw Desk formation level to a
 * normalised step, from the Configuration sub-page (#727).
 *
 * JSON, one select at a time: a change is saved the moment it is made and
 * the page says so with a toast, so there is no form, no redirect and no
 * flash message to land back on. An empty step removes the decision.
 */
class FormationMappingController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private FormationLevelMappingRepository $repository,
        private JournalService $journalService
    ) {
    }

    /**
     * POST /admin/leadership/configuration/mapping — {raw_value, step}.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $rawValue = trim((string) ($data['raw_value'] ?? ''));
        $stepValue = (string) ($data['step'] ?? '');

        if ($rawValue === '') {
            return $this->json(['success' => false, 'error' => 'Aucune valeur à rattacher.'], 422);
        }

        // An empty step is "forget this decision", which is how a mistake is
        // undone: the value goes back to whatever the built-in heuristic
        // makes of it, unrecognised included.
        if ($stepValue === '') {
            $this->repository->delete($rawValue);
            $this->journal('leadership_formation_mapping_removed');

            return $this->json(['success' => true]);
        }

        $step = FormationStep::tryFrom($stepValue);

        // 'unknown' is a valid FormationStep but not an assignable one: it
        // is what the site says when nobody has decided, never a decision.
        // Checking membership of assignable() rather than trusting
        // tryFrom() is what keeps a hand-crafted POST from storing it.
        if ($step === null || !in_array($step, FormationStep::assignable(), true)) {
            return $this->json(['success' => false, 'error' => 'Étape de formation inconnue.'], 422);
        }

        $this->repository->save($rawValue, $step);
        $this->journal('leadership_formation_mapping_saved');

        return $this->json(['success' => true]);
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
