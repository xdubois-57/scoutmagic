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
use Modules\Registration\Service\RegistrationYearCodeSession;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;
use Twig\Environment;

class PublicRegistrationController extends AbstractController
{
    /**
     * Core\Security\HumanCheck form key.
     */
    private const HUMAN_CHECK_FORM_KEY = 'registration_form';

    /**
     * Server-side length ceilings, matching the form's own `maxlength`
     * attributes. The columns are encrypted BLOBs, so nothing truncates on
     * the SQL side either: without this, a POST carrying hundreds of
     * kilobytes of "remarques" was accepted and encrypted as-is. The
     * browser attribute is a convenience, never the boundary.
     *
     * @var array<string, int>
     */
    private const MAX_LENGTHS = [
        'parent_name' => 150,
        'child_last_name' => 100,
        'child_first_name' => 100,
        'street' => 200,
        'number' => 20,
        'postal_code' => 20,
        'city' => 100,
        'email' => 254, // RFC 5321's maximum path length
        'phone1' => 30,
        'phone2' => 30,
        'remarks' => 2000,
    ];

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
        return $this->render('@registration/public.html.twig', $this->buildPageContext(null, [], null));
    }

    /**
     * POST /inscriptions/code — the closed-form gate: validates an in-year
     * code and, only on success, unlocks the full form for the CURRENT
     * public year for this browser session (Service\
     * RegistrationYearCodeSession) — never globally, and never for the
     * next scout year (that still requires the form to genuinely be
     * open). A wrong code re-renders the same closed state with an error,
     * never the form itself.
     *
     * @param array<string, string> $params
     */
    public function verifyCode(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/inscriptions')) !== null) {
            return $guard;
        }

        $code = (string) $request->getBody('year_code', '');
        if (!$this->registrationService->isYearCodeValid($code)) {
            return $this->render(
                '@registration/public.html.twig',
                $this->buildPageContext(null, [], 'Le code saisi n\'est pas valide.')
            )->setStatusCode(422);
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        RegistrationYearCodeSession::unlock((int) $publicYear['id']);

        return $this->render('@registration/public.html.twig', $this->buildPageContext(null, [], null));
    }

    /**
     * POST /inscriptions
     *
     * @param array<string, string> $params
     */
    public function submit(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/inscriptions')) !== null) {
            return $guard;
        }

        $availability = $this->resolveAvailability((string) $request->getBody('year_code', ''));
        if (!$availability['form_available']) {
            return $this->render(
                '@registration/public.html.twig',
                $this->buildPageContext('Les inscriptions ne sont pas ouvertes pour le moment.', $request->getBodyAll(), null)
            )->setStatusCode(422);
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
                $this->buildPageContext('Une erreur est survenue. Veuillez réessayer.', $request->getBodyAll(), null)
            )->setStatusCode(422);
        }

        $fields = $this->extractFields($request);
        $errors = $this->validate($fields, $request);
        if ($errors !== []) {
            return $this->render(
                '@registration/public.html.twig',
                $this->buildPageContext(implode(' ', $errors), $request->getBodyAll(), null)
            )->setStatusCode(422);
        }

        $target = $availability['target'];
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
     * Whether the form is actually reachable right now, and which scout
     * year a submission would target — the one question both submit() and
     * buildPageContext() need answered the same way, so it isn't computed
     * twice with a risk of drifting apart.
     *
     * Genuinely open (`registration_form_open`) always wins and targets
     * the *next* year, with the optional inline in-year code (still
     * collapsed behind its own toggle in that case) able to redirect a
     * single submission to the current year instead — the pre-existing
     * behavior. A *closed* form is reachable only via Service\
     * RegistrationYearCodeSession's per-session unlock, which — because
     * it only exists at all once a code for the CURRENT public year was
     * verified — always targets that current year, never the next one.
     *
     * @return array{form_open: bool, session_unlocked: bool, form_available: bool, target: array{id: int, label: string, start_date: string, end_date: string, used_code: bool}}
     */
    private function resolveAvailability(?string $submittedCode): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $formOpen = $this->registrationService->isFormOpen();
        $sessionUnlocked = RegistrationYearCodeSession::isUnlockedFor((int) $publicYear['id']);

        $target = ($sessionUnlocked && !$formOpen)
            ? $publicYear + ['used_code' => true]
            : $this->registrationService->resolveTargetYear($submittedCode);

        return [
            'form_open' => $formOpen,
            'session_unlocked' => $sessionUnlocked,
            'form_available' => $formOpen || $sessionUnlocked,
            'target' => $target,
        ];
    }

    /**
     * @param array<string, mixed>|null $stickyValues values to redisplay in
     *   the form after a rejected submission (module spec: never lose the
     *   parent's entered data on an anti-robot rejection or validation error)
     * @param string|null $codeError set only when the closed-form code
     *   entry itself was just rejected (Controller::verifyCode())
     * @return array<string, mixed>
     */
    private function buildPageContext(?string $submitError, array $stickyValues, ?string $codeError): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $availability = $this->resolveAvailability(null);
        $target = $availability['target'];
        $targetLabel = (string) $target['label'];

        $waitlistEnabled = (bool) $this->settingService->get('registration_waitlist_enabled', 'registration', '0');

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
            'birth_year_slots' => $this->slotService->birthYearSlotsForPublic(
                (int) $target['id'],
                $targetLabel,
                (int) $publicYear['id'],
                $waitlistEnabled
            ),
            'form_open' => $availability['form_open'],
            'session_unlocked' => $availability['session_unlocked'],
            'form_available' => $availability['form_available'],
            'sections' => $sections,
            'is_identified' => $identified,
            'sibling_candidates' => $siblingCandidates,
            'csrf_token' => CsrfGuard::generateToken(),
            'human_check' => $this->humanCheck !== null && !$identified
                ? $this->humanCheck->generateChallenge(self::HUMAN_CHECK_FORM_KEY)
                : null,
            'submit_error' => $submitError,
            'code_error' => $codeError,
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

        foreach (self::MAX_LENGTHS as $key => $max) {
            if ($fields[$key] !== null && mb_strlen((string) $fields[$key]) > $max) {
                // One generic message, same style as the errors above — the
                // form marks the limits, so naming the field adds nothing a
                // legitimate visitor needs.
                $errors[] = 'Un des champs dépasse la longueur autorisée.';
                break;
            }
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
     * The declared siblings, restricted to members the submitting session is
     * ACTUALLY linked to — the very same list buildPageContext() offered as
     * checkboxes (Core\Member\MemberService::getLinkedMembers() against the
     * session's own email), never the raw submitted ids.
     *
     * Without this the ids went straight into registration_request_siblings:
     * an id that doesn't exist violated fk_rrs_member and surfaced as a 500
     * *after* the request row had already been inserted, and a real id
     * belonging to someone else let an identified visitor attach any member
     * of the unit as a "sibling" — a fabricated parental declaration that
     * then showed up, name and section included, on the Passage page and on
     * the staff fiche.
     *
     * An unauthorized id is dropped silently rather than failing the whole
     * submission: it can only come from a forged or stale POST, and losing a
     * parent's entire form over it would be worse than ignoring the link.
     *
     * @return array<int>
     */
    private function extractSiblingIds(Request $request): array
    {
        $raw = $request->getBody('sibling_member_ids', []);
        if (!is_array($raw)) {
            return [];
        }

        $submitted = array_values(array_filter(array_map('intval', $raw), static fn(int $id) => $id > 0));
        if ($submitted === []) {
            return [];
        }

        $email = AuthSession::getEmail();
        if ($email === null) {
            return [];
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $allowed = [];
        foreach ($this->memberService->getLinkedMembers($email, (int) $publicYear['id']) as $member) {
            $allowed[] = $member->memberId;
        }

        return array_values(array_intersect($submitted, $allowed));
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
