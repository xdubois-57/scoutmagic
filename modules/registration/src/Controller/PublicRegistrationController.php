<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\HumanCheck\HumanCheckService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Service\RegistrationService;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;
use Twig\Environment;

class PublicRegistrationController extends AbstractController
{
    /**
     * Core\Security\HumanCheck form key.
     */
    private const HUMAN_CHECK_FORM_KEY = 'registration_form';

    public function __construct(
        protected Environment $twig,
        private RegistrationService $registrationService,
        private SlotService $slotService,
        private SectionService $sectionService,
        private AgeBracketRepository $ageBracketRepository,
        private ScoutYearResolver $scoutYearResolver,
        private MemberService $memberService,
        private SettingService $settingService,
        private ?HumanCheckService $humanCheck = null
    ) {
    }

    /**
     * GET /inscriptions
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@registration/public.html.twig', $this->buildPageContext(null, []));
    }

    /**
     * POST /inscriptions
     *
     * @param array<string, string> $params
     */
    public function submit(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        if (!$this->registrationService->isFormOpen()) {
            return new Response('Not Found', 404);
        }

        $humanCheckResult = $this->humanCheck?->verify(
            self::HUMAN_CHECK_FORM_KEY,
            AuthSession::isAuthenticated(),
            $request->getBodyAll(),
            (string) $request->getServer('REMOTE_ADDR', '')
        );
        if ($humanCheckResult !== null && !$humanCheckResult->accepted) {
            return $this->render(
                '@registration/public.html.twig',
                $this->buildPageContext('Une erreur est survenue. Veuillez réessayer.', $request->getBodyAll())
            )->setStatusCode(422);
        }

        $fields = $this->extractFields($request);
        $errors = $this->validate($fields, $request);
        if ($errors !== []) {
            return $this->render(
                '@registration/public.html.twig',
                $this->buildPageContext(implode(' ', $errors), $request->getBodyAll())
            )->setStatusCode(422);
        }

        $target = $this->registrationService->resolveTargetYear((string) $request->getBody('year_code', ''));
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel((string) $target['label']),
            $this->slotService->referenceMonthDay()
        );
        $brackets = $this->ageBracketRepository->findAllOrdered();
        $slot = SlotMath::slotForBirthDate($brackets, $fields['birth_date'], $referenceYear);

        $desiredSectionId = $this->resolveDesiredSectionId($request, $slot);
        $siblingMemberIds = AuthSession::isAuthenticated() ? $this->extractSiblingIds($request) : [];

        $slotLabel = $this->slotLabel($brackets, $slot);

        $requestId = $this->registrationService->submit(
            (int) $target['id'],
            (string) $target['label'],
            $fields,
            $desiredSectionId,
            $siblingMemberIds,
            $slotLabel
        );

        return $this->render('@registration/submitted.html.twig', [
            'child_first_name' => $fields['child_first_name'],
            'request_id' => $requestId,
        ]);
    }

    /**
     * @param array<string, mixed>|null $stickyValues values to redisplay in
     *   the form after a rejected submission (module spec: never lose the
     *   parent's entered data on an anti-robot rejection or validation error)
     * @return array<string, mixed>
     */
    private function buildPageContext(?string $submitError, array $stickyValues): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $target = $this->registrationService->resolveTargetYear(null);
        $targetLabel = (string) $target['label'];

        $waitlistEnabled = (bool) $this->settingService->get('registration_waitlist_enabled', 'registration', '0');
        $formOpen = $this->registrationService->isFormOpen();

        $sections = $this->sectionService->getAllWithBranches();

        $identified = AuthSession::isAuthenticated();
        $siblingCandidates = [];
        if ($identified) {
            $email = AuthSession::getEmail();
            if ($email !== null) {
                foreach ($this->memberService->getLinkedMembers($email, (int) $publicYear['id']) as $member) {
                    $siblingCandidates[] = [
                        'member_id' => $member->memberId,
                        'name' => trim($member->firstName . ' ' . $member->lastName),
                    ];
                }
            }
        }

        return [
            'parcours_image_file_id' => (int) $this->settingService->get('registration_parcours_image_file_id', 'registration', '0'),
            'target_year_label' => $targetLabel,
            'birth_years_by_branch' => $this->slotService->birthYearsByBranch($targetLabel),
            'waitlist_enabled' => $waitlistEnabled,
            'waitlist_tiers' => $waitlistEnabled
                ? $this->slotService->waitlistTiersByBirthYear(
                    (int) $target['id'],
                    $targetLabel,
                    (int) $publicYear['id']
                )
                : [],
            'form_open' => $formOpen,
            'sections' => $sections,
            'is_identified' => $identified,
            'sibling_candidates' => $siblingCandidates,
            'csrf_token' => CsrfGuard::generateToken(),
            'human_check' => $this->humanCheck !== null && !$identified
                ? $this->humanCheck->generateChallenge(self::HUMAN_CHECK_FORM_KEY)
                : null,
            'submit_error' => $submitError,
            'sticky' => $stickyValues,
        ];
    }

    /**
     * @return array{
     *   parent_name: string, child_last_name: string, child_first_name: string,
     *   gender: string, birth_date: string, street: string, number: string,
     *   postal_code: string, city: string, email: string, phone1: string,
     *   phone2: ?string, remarks: ?string
     * }
     */
    private function extractFields(Request $request): array
    {
        $phone2 = trim((string) $request->getBody('phone2', ''));
        $remarks = trim((string) $request->getBody('remarks', ''));

        return [
            'parent_name' => trim((string) $request->getBody('parent_name', '')),
            'child_last_name' => trim((string) $request->getBody('child_last_name', '')),
            'child_first_name' => trim((string) $request->getBody('child_first_name', '')),
            'gender' => trim((string) $request->getBody('gender', '')),
            'birth_date' => trim((string) $request->getBody('birth_date', '')),
            'street' => trim((string) $request->getBody('street', '')),
            'number' => trim((string) $request->getBody('number', '')),
            'postal_code' => trim((string) $request->getBody('postal_code', '')),
            'city' => trim((string) $request->getBody('city', '')),
            'email' => trim((string) $request->getBody('email', '')),
            'phone1' => trim((string) $request->getBody('phone1', '')),
            'phone2' => $phone2 !== '' ? $phone2 : null,
            'remarks' => $remarks !== '' ? $remarks : null,
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string>
     */
    private function validate(array $fields, Request $request): array
    {
        $errors = [];

        foreach (['parent_name', 'child_last_name', 'child_first_name', 'street', 'number', 'postal_code', 'city', 'phone1'] as $key) {
            if ($fields[$key] === '') {
                $errors[] = 'Merci de compléter tous les champs obligatoires.';
                break;
            }
        }

        if (!in_array($fields['gender'], ['M', 'F', 'X'], true)) {
            $errors[] = 'Genre invalide.';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['birth_date'])
            || \DateTimeImmutable::createFromFormat('Y-m-d', $fields['birth_date']) === false
        ) {
            $errors[] = 'Date de naissance invalide.';
        }

        if (filter_var($fields['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Adresse email invalide.';
        }

        if ($request->getBody('rgpd_accepted') === null) {
            $errors[] = 'Merci d\'accepter la politique de confidentialité.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * Never trusts the submitted section id blindly — it must belong to
     * the branch the birth date actually falls into, or "no preference"
     * (null) is used instead.
     *
     * @param array{age_branch_id: int, year_in_branch: int}|null $slot
     */
    private function resolveDesiredSectionId(Request $request, ?array $slot): ?int
    {
        $submitted = (int) $request->getBody('desired_section_id', '0');
        if ($submitted <= 0 || $slot === null) {
            return null;
        }

        foreach ($this->sectionService->getAllWithBranches() as $section) {
            if ($section['id'] === $submitted && $section['age_branch_id'] === $slot['age_branch_id']) {
                return $submitted;
            }
        }

        return null;
    }

    /**
     * @return array<int>
     */
    private function extractSiblingIds(Request $request): array
    {
        $raw = $request->getBody('sibling_member_ids', []);
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $raw), static fn(int $id) => $id > 0));
    }

    /**
     * @param array<\Modules\Registration\Repository\AgeBracket> $brackets
     * @param array{age_branch_id: int, year_in_branch: int}|null $slot
     */
    private function slotLabel(array $brackets, ?array $slot): string
    {
        if ($slot === null) {
            return 'Non déterminé';
        }
        foreach ($brackets as $bracket) {
            if ($bracket->ageBranchId === $slot['age_branch_id']) {
                return $bracket->branchLabel . ' — ' . $slot['year_in_branch'] . 'ᵉ année';
            }
        }

        return 'Non déterminé';
    }
}
