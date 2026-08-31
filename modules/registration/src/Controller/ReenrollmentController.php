<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Registration\Repository\ReenrollmentAnswer;
use Modules\Registration\Service\ReenrollmentCampaignService;
use Modules\Registration\Service\ReenrollmentFormService;
use Modules\Registration\Service\ReenrollmentService;
use Twig\Environment;

/**
 * « Réinscription {année} » in the Espace des animés — where a family says
 * whether their child comes back next year (spec §11).
 *
 * **No member id in the URL, and none trusted from the body either.** The
 * page's cards come from the signed-in address's own linked members, and
 * a save re-derives that list before writing anything: a forged request
 * naming somebody else's child fails on the list, not on a hidden field.
 * `role_min: identified` is the floor and never the whole answer
 * (ARCHITECTURE.md §3).
 *
 * Anchored on the PUBLIC year + 1, exactly like Controller\PassageController
 * and Controller\ForecastController and for the same reason: a family is
 * answering about a specific real upcoming year, not about whatever year
 * an administrator happens to be previewing.
 *
 * **A family may change their mind as often as they like** while the
 * campaign is open. There is one row per child and per year, so answering
 * again replaces the answer rather than adding one.
 */
class ReenrollmentController extends AbstractController
{
    private const PAGE_URL = '/reinscription';

    public function __construct(
        protected Environment $twig,
        private ReenrollmentFormService $formService,
        private ReenrollmentService $reenrollmentService,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService,
        private ReenrollmentCampaignService $campaign
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        [$publicYear, $targetYear] = $this->resolveYears();
        $email = AuthSession::getEmail() ?? '';

        return $this->render('@registration/reenrollment.html.twig', [
            'target_year_label' => $targetYear['label'],
            'current_year_label' => $publicYear['label'],
            'cards' => $this->formService->cardsFor(
                $email,
                (int) $publicYear['id'],
                (string) $publicYear['label'],
                (int) $targetYear['id']
            ),
            // Closed: the page stays, read-only. Making it disappear would
            // tell a family who had answered that their answer went with
            // it (roadmap IT-15).
            'is_open' => $this->campaign->isOpen(),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /reinscription — one child's answer.
     *
     * An ordinary form post with a redirect and a flash, not JSON: a
     * family fills this in once, on a phone, and a page that answered in
     * place would have to re-render the whole card anyway.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_URL)) !== null) {
            return $guard;
        }

        // The window is enforced on the server, not by the absence of a
        // button: a form left open in a tab across the closing date must
        // not still write.
        if (!$this->campaign->isOpen()) {
            FlashMessage::set(
                'error',
                "La campagne de réinscription est clôturée. Contactez le Staff d'U pour toute modification."
            );

            return $this->redirect(self::PAGE_URL);
        }

        [$publicYear, $targetYear] = $this->resolveYears();
        $email = AuthSession::getEmail() ?? '';
        $memberId = (int) $request->getBody('member_id', 0);

        // The one check that matters, and it is a re-derivation rather
        // than a comparison with anything the request supplied.
        if ($memberId <= 0 || !$this->formService->mayAnswerFor($email, $memberId, (int) $publicYear['id'])) {
            return (new Response(
                "Cette réponse ne concerne aucun de vos animés.",
                403
            ))->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        }

        $decision = (string) $request->getBody('decision', '');
        $preferred = (int) $request->getBody('preferred_section_id', 0);

        /** @var array<int, string> $friends */
        $friends = [];
        $submitted = $request->getBody('friend_names', []);
        if (is_array($submitted)) {
            foreach ($submitted as $friend) {
                if (is_scalar($friend)) {
                    $friends[] = (string) $friend;
                }
            }
        }

        $this->reenrollmentService->recordAnswer(
            $memberId,
            (int) $targetYear['id'],
            $decision,
            // 0 is « Peu importe » — a real answer, and the same "no
            // section" the field's absence would mean.
            $preferred > 0 ? $preferred : null,
            (string) $request->getBody('family_comment', ''),
            $friends,
            (int) $publicYear['id'],
            AuthSession::getUserAccountId()
        );

        FlashMessage::set(
            'success',
            $decision === ReenrollmentAnswer::DECISION_LEAVING
                ? 'Réponse enregistrée. Merci de nous avoir prévenus.'
                : 'Réponse enregistrée. Merci !'
        );

        return $this->redirect(self::PAGE_URL);
    }

    /**
     * @return array{0: array{id: int, label: string}, 1: array{id: int, label: string}}
     */
    private function resolveYears(): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $targetLabel = ScoutYearService::nextLabel((string) $publicYear['label']);
        $targetYearId = $this->scoutYearService->ensureYear($targetLabel);
        $targetYear = $this->scoutYearService->findById($targetYearId) ?? $publicYear;

        return [
            ['id' => (int) $publicYear['id'], 'label' => (string) $publicYear['label']],
            ['id' => (int) $targetYear['id'], 'label' => (string) $targetYear['label']],
        ];
    }
}
