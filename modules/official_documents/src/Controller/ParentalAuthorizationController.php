<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Calendar\Api\EventSummary;
use Modules\OfficialDocuments\Api\OfficialDocumentsException;
use Modules\OfficialDocuments\Service\ParentalAuthorizationFilling;
use Modules\OfficialDocuments\Service\ParentalAuthorizationInput;
use Modules\OfficialDocuments\Service\ParentalAuthorizationPdfService;
use Modules\OfficialDocuments\Service\ParentalAuthorizationService;
use Modules\OfficialDocuments\Service\SignatoryCapacity;
use Twig\Environment;

/**
 * The screen a parent fills in, and the PDF it produces.
 *
 * **`role_min: identified` is never the real protection here.** It only says
 * the caller is signed in; what decides is whether the account asking is
 * linked to THIS member, re-checked on both actions through
 * `requireOwnMemberProfile()`. Strict self-service, per the chantier: no
 * chief and no administrator bypass, whatever their role — the only way
 * staff reach it at all is the temporary member override (ARCHITECTURE.md
 * §8.42), which `MemberService::canAccess()` honours precisely so an admin
 * can act on somebody's behalf and which shows on screen while it lasts.
 *
 * The trick that makes it strict is the literal `'identified'` handed to
 * `canAccess()` instead of the caller's real role: that method's
 * chief-or-admin branch can then never fire. Same shape as
 * `Core\Http\Controller\MemberEmailAddressController::requireOwnMemberId()`,
 * which is the pattern this module was asked to copy.
 */
class ParentalAuthorizationController extends AbstractController
{
    private const SELF_ONLY = 'Ce document ne peut être établi que par une personne liée à ce membre.';

    public function __construct(
        protected Environment $twig,
        private MemberService $memberService,
        private UserAccountRepository $userAccounts,
        private ParentalAuthorizationService $authorizationService,
        private ParentalAuthorizationPdfService $pdfService,
        /**
         * The event picker's source, optional per ARCHITECTURE.md §7.5:
         * `calendar` disabled, the picker disappears and the two date fields
         * stay. Deliberately `CalendarEventLookupInterface` and not
         * `SectionEventLookupInterface` — the latter folds supplementary
         * calendars away, so a unit camp carried by a non-sectional calendar
         * would be offered by one and invisible to the other.
         */
        private ?CalendarEventLookupInterface $calendarEvents = null
    ) {
    }

    /**
     * GET /members/{id}/autorisation-parentale
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $member = $this->requireOwnMemberProfile($memberYearId);
        if ($member === null) {
            return $this->forbidden(self::SELF_ONLY, $request);
        }

        return $this->renderForm($member, [
            'signatory_name' => $this->defaultSignatoryName(),
            'capacity' => SignatoryCapacity::Father->value,
            'start_date' => '',
            'end_date' => '',
            'place' => $this->authorizationService->defaultPlaceFor($member),
            'abroad' => '',
        ], null);
    }

    /**
     * POST /members/{id}/autorisation-parentale — answer the filled-in PDF.
     *
     * A POST rather than a GET with a query string, and that is not a
     * formality: what the parent typed is their own name and the place they
     * sign, and a query string is what a proxy log, a browser history and a
     * `Referer` header keep.
     *
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $member = $this->requireOwnMemberProfile($memberYearId);
        if ($member === null) {
            return $this->forbidden(self::SELF_ONLY, $request);
        }

        if (($guard = $this->guardCsrf($request, '/members/' . $memberYearId . '/autorisation-parentale')) !== null) {
            return $guard;
        }

        $body = $request->getBodyAll();

        try {
            $input = ParentalAuthorizationInput::fromBody($body);
            $scoutYearId = $this->memberService->getScoutYearIdForMemberYear($member->memberYearId) ?? 0;

            $result = $this->pdfService->render(
                $member,
                $this->authorizationService->responsableFor($member, $scoutYearId),
                $this->authorizationService->unitLabel(),
                $input,
                new \DateTimeImmutable('today')
            );
        } catch (OfficialDocumentsException $e) {
            // Re-rendered rather than redirected: a redirect loses every
            // field the parent already filled in, and this form is exactly
            // the kind nobody wants to type twice.
            return $this->renderForm($member, $this->submitted($body), $e->getMessage());
        }

        // Only an overflow the parent can DO something about stops the
        // download. The same overflow-checked path writes the member's own
        // name, the responsable's address and the unit line, none of which
        // this form exposes — telling a parent to shorten one of those
        // would be telling them to shorten somebody else's address, and the
        // document would stay out of reach for that member for good.
        //
        // The value is written either way (`OverlayPdf::writeText()` draws
        // it cramped rather than dropping it), so a site-derived overflow
        // costs a tight line on a form that is otherwise correct — which is
        // a great deal better than no form at all. What a chef would need
        // to change, they change in the module's settings or in Desk.
        $theirs = array_values(array_intersect(
            $result['overflowing'],
            ParentalAuthorizationFilling::PARENT_EDITABLE
        ));

        if ($theirs !== []) {
            return $this->renderForm(
                $member,
                $this->submitted($body),
                'Une des valeurs saisies est trop longue pour la ligne du formulaire officiel. '
                    . 'Raccourcissez-la avant de télécharger le document.'
            );
        }

        return (new Response($result['pdf']))
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader(
                'Content-Disposition',
                'attachment; filename="autorisation-parentale-' . $member->memberYearId . '.pdf"'
            )
            // Nothing about this document is cacheable: it carries a
            // family's names and the date it was produced.
            ->setHeader('Cache-Control', 'private, no-store');
    }

    /**
     * What the « Je soussigné(e) » field starts on: the signed-in account's
     * own first and last name.
     *
     * Those two are mandatory since IT-01 (`Core\Security\
     * ProfileCompletionGate`), which is why this screen can propose a name
     * at all — and why the fallback below is a belt rather than a case: a
     * session reaching this page has been through that gate.
     */
    private function defaultSignatoryName(): string
    {
        $accountId = AuthSession::getUserAccountId();
        $account = $accountId === null ? null : $this->userAccounts->findById($accountId);
        if ($account === null) {
            return '';
        }

        return trim(($account->firstName ?? '') . ' ' . ($account->lastName ?? ''));
    }

    /**
     * Re-verify that the requesting account is linked to this exact member,
     * and answer their profile.
     *
     * @return ?MemberProfile null when access must be refused
     */
    private function requireOwnMemberProfile(int $memberYearId): ?MemberProfile
    {
        $email = AuthSession::getEmail() ?? '';

        // The literal 'identified' is what makes this self-only: passing the
        // caller's real role would let canAccess()'s chief/admin branch
        // answer true for somebody else's child.
        if (!$this->memberService->canAccess($email, $memberYearId, 'identified')) {
            return null;
        }

        try {
            return $this->memberService->getMemberProfile($memberYearId);
        } catch (MemberNotFoundException) {
            return null;
        }
    }

    /**
     * @param array<string, string|null> $values
     */
    private function renderForm(MemberProfile $member, array $values, ?string $error): Response
    {
        return $this->render('@official_documents/parental_authorization.html.twig', [
            'member' => $member,
            'values' => $values,
            'capacities' => array_map(
                static fn(SignatoryCapacity $capacity): array => [
                    'value' => $capacity->value,
                    'label' => $capacity->label(),
                ],
                SignatoryCapacity::all()
            ),
            'events' => $this->overnightEvents($member),
            'submit_error' => $error,
            'breadcrumb_trail' => [
                ['label' => $member->getDisplayName(), 'url' => '/members/' . $member->memberYearId],
            ],
        ]);
    }

    /**
     * What the parent had typed, so a refusal does not empty the form.
     *
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function submitted(array $body): array
    {
        $values = [];
        foreach (['signatory_name', 'capacity', 'start_date', 'end_date', 'place', 'abroad'] as $key) {
            $values[$key] = trim((string) ($body[$key] ?? ''));
        }

        return $values;
    }

    /**
     * The section's events that are current or to come and cover at least
     * one night.
     *
     * Overnight because that is what a parental authorization is for: an
     * ordinary Saturday afternoon needs none, and offering fifty of them
     * would bury the two dates a parent is actually looking for.
     *
     * The picker only fills the two date fields in the browser. Nothing
     * about an event is posted back, so there is nothing here for the server
     * to re-resolve and no identifier to trust.
     *
     * @return list<array{label: string, start: string, end: string}>
     */
    private function overnightEvents(MemberProfile $member): array
    {
        if ($this->calendarEvents === null) {
            return [];
        }

        $sectionCode = $member->getMainFunction()?->sectionCode;
        $section = $sectionCode === null ? null : $this->authorizationService->sectionIdFor($sectionCode);

        $today = new \DateTimeImmutable('today');
        $events = $this->calendarEvents->findEventsInWindow(
            $today,
            $today->add(new \DateInterval(self::EVENT_WINDOW)),
            $section,
            Role::fromString(AuthSession::getRole())
        );

        $offered = [];
        foreach ($events as $event) {
            if (!self::coversANight($event)) {
                continue;
            }
            $offered[] = [
                'label' => $event->title,
                'start' => $event->startDate,
                'end' => $event->endDate,
            ];
        }

        return $offered;
    }

    private static function coversANight(EventSummary $event): bool
    {
        return $event->endDate > $event->startDate;
    }

    /**
     * How far ahead the picker looks. A year, because a camp is announced
     * in the spring for July and a parent signs the authorization when the
     * site tells them to, not when the camp starts.
     */
    private const EVENT_WINDOW = 'P1Y';
}
