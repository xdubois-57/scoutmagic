<?php

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Import\AgeBranchRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\CsrfGuard;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationYearCodeRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Twig\Environment;

/**
 * "Configuration > Inscriptions" — everything the module spec calls
 * un-hardcodable: age brackets and capacities per branch, and the current
 * scout year's in-year registration code. The boolean/text settings
 * declared in module.json (form open, waitlist thresholds, reference
 * date...) are already editable via the generic Core\Http\Controller\
 * SettingsController page and are deliberately NOT duplicated here.
 */
class RegistrationConfigController extends AbstractController
{
    /**
     * No federation branch spans more than this many years — bounds the
     * capacity grid so the template can render a fixed number of columns
     * without JS row add/remove (module spec: "minimal" admin screen).
     */
    private const MAX_YEARS_IN_BRANCH = 4;

    /**
     * The four "animés" branches this module configures brackets/capacities
     * for — age_branches also holds staff/route/IAMA rows (sort_order 50+),
     * which this module has nothing to do with.
     */
    private const MAX_ANIME_SORT_ORDER = 40;

    public function __construct(
        protected Environment $twig,
        private AgeBracketRepository $ageBracketRepository,
        private SlotCapacityRepository $slotCapacityRepository,
        private RegistrationYearCodeRepository $yearCodeRepository,
        private AgeBranchRepository $ageBranchRepository,
        private ScoutYearResolver $scoutYearResolver
    ) {
    }

    /**
     * GET /config/inscriptions
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@registration/config.html.twig', $this->buildPageContext());
    }

    /**
     * POST /config/inscriptions — saves both the age-bracket grid and the
     * capacity grid in one submission (module spec: minimal screen).
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/config/inscriptions');
        }

        $entryAges = $request->getBody('entry_age', []);
        $durations = $request->getBody('duration_years', []);
        $capacities = $request->getBody('capacity', []);

        foreach ($this->animeBranches() as $branch) {
            $branchId = $branch['id'];
            $entryAge = (int) ($entryAges[$branchId] ?? 0);
            $duration = (int) ($durations[$branchId] ?? 0);
            if ($entryAge <= 0 || $duration <= 0) {
                continue;
            }
            $duration = min($duration, self::MAX_YEARS_IN_BRANCH);

            $this->ageBracketRepository->upsert($branchId, $entryAge, $duration);

            for ($yearInBranch = 1; $yearInBranch <= $duration; $yearInBranch++) {
                $capacity = (int) ($capacities[$branchId][$yearInBranch] ?? 0);
                $this->slotCapacityRepository->upsert($branchId, $yearInBranch, max(0, $capacity));
            }
        }

        FlashMessage::set('success', 'Configuration enregistrée.');
        return $this->redirect('/config/inscriptions');
    }

    /**
     * POST /config/inscriptions/code/regenerate — new active code for the
     * current public scout year.
     *
     * @param array<string, string> $params
     */
    public function regenerateCode(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/config/inscriptions');
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $this->yearCodeRepository->regenerate((int) $publicYear['id']);

        FlashMessage::set('success', 'Nouveau code généré.');
        return $this->redirect('/config/inscriptions');
    }

    /**
     * POST /config/inscriptions/code/deactivate — closes in-year
     * registration for the current public scout year.
     *
     * @param array<string, string> $params
     */
    public function deactivateCode(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/config/inscriptions');
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $this->yearCodeRepository->deactivate((int) $publicYear['id']);

        FlashMessage::set('success', 'Code désactivé.');
        return $this->redirect('/config/inscriptions');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPageContext(): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $brackets = $this->ageBracketRepository->findAllOrdered();
        $bracketsByBranch = [];
        foreach ($brackets as $bracket) {
            $bracketsByBranch[$bracket->ageBranchId] = $bracket;
        }
        $capacities = $this->slotCapacityRepository->findAllAsMap();

        return [
            'branches' => $this->animeBranches(),
            'brackets_by_branch' => $bracketsByBranch,
            'capacities' => $capacities,
            'max_years_in_branch' => self::MAX_YEARS_IN_BRANCH,
            'csrf_token' => CsrfGuard::generateToken(),
            'public_year_label' => $publicYear['label'],
            'year_code' => $this->yearCodeRepository->findForYear((int) $publicYear['id']),
        ];
    }

    /**
     * @return array<int, array{id: int, desk_code: string, label: string, sort_order: int, logo_file_id: ?int, explanation_url: string}>
     */
    private function animeBranches(): array
    {
        return array_values(array_filter(
            $this->ageBranchRepository->findAllOrdered(),
            static fn(array $branch) => $branch['sort_order'] <= self::MAX_ANIME_SORT_ORDER
        ));
    }
}
