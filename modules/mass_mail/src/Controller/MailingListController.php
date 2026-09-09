<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Modules\MassMail\Repository\ListAddress;
use Modules\MassMail\Repository\MailingList;
use Modules\MassMail\Service\ListAddressService;
use Modules\MassMail\Service\MailingListException;
use Modules\MassMail\Service\MailingListService;
use Twig\Environment;

/**
 * The unit's mailing lists — « Espace chefs d'U > Listes de diffusion »,
 * `role_min: admin`.
 *
 * It used to be a Configuration page at `superadmin`
 * (`/config/mass-mail`, class `ConfigController`), which put the decision
 * of WHO the unit writes to one role above the people who make it: a chef
 * d'unité decides that, not whoever installs the site. The `espace_admin`
 * menu's own floor is `admin` (`Core\Module\ModuleManifest::
 * MENU_MIN_ROLES`), which is what makes the lower floor declarable at all
 * — the `configuration` menu imposes `superadmin`, and the manifest
 * refuses at load time any route more permissive than its menu.
 *
 * The sending-speed form moved out rather than moved along: `batch_size`
 * and `batch_interval_minutes` are ordinary `SettingService` rows, so
 * Configuration > Réglages already renders and validates them, and a
 * second editor for the same two values is a second thing to keep in
 * step.
 */
class MailingListController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MailingListService $mailingListService,
        private ScoutYearResolver $scoutYearResolver,
        private ListAddressService $listAddressService
    ) {
    }

    /**
     * GET /admin/listes-de-diffusion
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $customLists = $this->mailingListService->getAllCustomLists();

        return $this->render('@mass_mail/mailing_lists.html.twig', [
            'default_lists' => $this->mailingListService->getDefaultLists(),
            'custom_lists' => $customLists,
            'custom_list_criteria' => array_reduce(
                $customLists,
                fn(array $carry, MailingList $l) => $carry + [$l->id => [
                    'function_ids' => $this->mailingListService->getCustomListFunctionIds($l->id),
                    'section_ids' => $this->mailingListService->getCustomListSectionIds($l->id),
                    'badge_ids' => $this->mailingListService->getCustomListBadgeIds($l->id),
                    // A COUNT, never the addresses themselves: a list that
                    // is nothing but criteria must not pay the cost of
                    // decrypting a section nobody opened.
                    'address_counts' => $this->listAddressService->countForList($l->id),
                ]],
                []
            ),
            'all_functions' => $this->mailingListService->getAllFunctions(),
            'all_sections' => $this->mailingListService->getAllSections(),
            'all_badges' => $this->mailingListService->getAllBadges(),
            // The list itself carries no year (the page says so), but the
            // live count has to be counted against one — and naming it is
            // what stops « 12 destinataires » being read as a promise
            // about whichever year the email will later target.
            'effective_year_label' => $this->effectiveYear()->label,
            'max_addresses' => $this->listAddressService->maxAddresses(),
            // A list has no year of its own, so there is no per-list
            // warning to show here — but the page is where somebody decides
            // WHO a list holds, and next year's answer is a projection or
            // nothing at all depending on one module. Said once, in the
            // module's own words rather than the page's.
            'future_audience_notice' => $this->mailingListService->futureAudienceNotice(),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /admin/listes-de-diffusion/lists — create a custom mailing list.
     *
     * @param array<string, string> $params
     */
    public function createList(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $list = $this->mailingListService->createCustomList(
                (string) ($data['name'] ?? ''),
                (string) ($data['description'] ?? ''),
                $this->toIntArray($data['function_ids'] ?? []),
                $this->toIntArray($data['section_ids'] ?? []),
                $this->toIntArray($data['badge_ids'] ?? []),
                AuthSession::getUserAccountId()
            );
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json([
            'success' => true,
            'list' => ['id' => $list->id, 'name' => $list->name, 'is_active' => $list->isActive]
        ]);
    }

    /**
     * PATCH /admin/listes-de-diffusion/lists/{id}
     *
     * @param array<string, string> $params
     */
    public function updateList(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $list = $this->mailingListService->updateCustomList(
                (int) $params['id'],
                (string) ($data['name'] ?? ''),
                (string) ($data['description'] ?? ''),
                $this->toIntArray($data['function_ids'] ?? []),
                $this->toIntArray($data['section_ids'] ?? []),
                $this->toIntArray($data['badge_ids'] ?? [])
            );
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json([
            'success' => true,
            'list' => ['id' => $list->id, 'name' => $list->name, 'is_active' => $list->isActive]
        ]);
    }

    /**
     * POST /admin/listes-de-diffusion/lists/{id}/toggle — activate/deactivate.
     *
     * @param array<string, string> $params
     */
    public function toggleList(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $this->mailingListService->setActive((int) $params['id'], (bool) ($data['active'] ?? false));
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * DELETE /admin/listes-de-diffusion/lists/{id} — blocked (module spec, same
     * precedent as badges) when the list is used by at least one email.
     *
     * @param array<string, string> $params
     */
    public function deleteList(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $this->mailingListService->deleteCustomList((int) $params['id']);
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * POST /admin/listes-de-diffusion/preview-count — how many members
     * the criteria CURRENTLY IN THE FORM resolve to, before anything is
     * saved.
     *
     * The AND between the three axes is what makes this worth a round
     * trip: crossing a badge with an animés section gives zero every
     * time, because badges are only assignable to the Staff d'U and to
     * the chef/chef d'unité functions — a trap the sentence above the
     * counter states and the counter proves.
     *
     * A POST although it reads nothing but: it carries a set of ids and a
     * CSRF token, both of which belong in a body rather than in a query
     * string that proxies and access logs keep.
     *
     * @param array<string, string> $params
     */
    public function previewCount(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $year = $this->effectiveYear();

        return $this->json([
            'success' => true,
            'count' => $this->mailingListService->countMembersForCriteria(
                $this->toIntArray($data['function_ids'] ?? []),
                $this->toIntArray($data['section_ids'] ?? []),
                $this->toIntArray($data['badge_ids'] ?? []),
                $year->id
            ),
            'scout_year_label' => $year->label,
        ]);
    }

    /**
     * GET /admin/listes-de-diffusion/lists/{id}/addresses — the whole
     * decrypted set, in ONE call.
     *
     * There is no paging and no server-side search here, and that is the
     * documented consequence of the data being encrypted: there is no
     * `ORDER BY` and no `LIKE` to page or filter with, so filtering in SQL
     * would mean decrypting everything anyway and throwing most of it away
     * — the reasoning `Core\Member\Service\MemberSearchService` sets out
     * at length. The set is bounded instead, by
     * `mass_mail_list_addresses_max`, and everything the screen does
     * afterwards happens in the browser without another round trip.
     *
     * @param array<string, string> $params
     */
    public function addresses(Request $request, array $params): Response
    {
        $listId = (int) $params['id'];
        if ($this->mailingListService->getCustomListById($listId) === null) {
            return $this->json(['success' => false, 'error' => 'Liste introuvable.'], 404);
        }

        return $this->json([
            'success' => true,
            'addresses' => array_map(
                fn(ListAddress $a) => $this->presentAddress($a),
                $this->listAddressService->findForList($listId)
            ),
        ]);
    }

    /**
     * POST /admin/listes-de-diffusion/lists/{id}/addresses
     *
     * @param array<string, string> $params
     */
    public function addAddress(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $address = $this->listAddressService->add(
                (int) $params['id'],
                $this->optionalString($data['name'] ?? null),
                (string) ($data['email'] ?? '')
            );
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json([
            'success' => true,
            'address' => $this->presentAddress($address),
            'counts' => $this->listAddressService->countForList((int) $params['id']),
        ]);
    }

    /**
     * PATCH /admin/listes-de-diffusion/addresses/{id}
     *
     * @param array<string, string> $params
     */
    public function updateAddress(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $address = $this->listAddressService->edit(
                (int) $params['id'],
                $this->optionalString($data['name'] ?? null),
                (string) ($data['email'] ?? '')
            );
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'address' => $this->presentAddress($address)]);
    }

    /**
     * DELETE /admin/listes-de-diffusion/addresses/{id}
     *
     * @param array<string, string> $params
     */
    public function deleteAddress(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        // Read the row BEFORE deleting it: the answer carries the
        // list's new counts, and after the delete there is nothing left to
        // ask which list it belonged to.
        $address = $this->listAddressService->findById((int) $params['id']);
        if ($address === null) {
            return $this->json(['success' => false, 'error' => 'Adresse introuvable.'], 422);
        }

        try {
            $this->listAddressService->remove($address->id);
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json([
            'success' => true,
            'counts' => $this->listAddressService->countForList($address->listId),
        ]);
    }

    /**
     * @return array{id: int, name: ?string, email: string, unsubscribed_at: ?string}
     */
    private function presentAddress(ListAddress $address): array
    {
        return [
            'id' => $address->id,
            'name' => $address->name,
            'email' => $address->email,
            'unsubscribed_at' => $address->unsubscribedAt,
        ];
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function effectiveYear(): \Core\ScoutYear\EffectiveScoutYear
    {
        return $this->scoutYearResolver->getEffectiveYear(
            ScoutYearSession::getPreviewId(),
            Role::fromString(AuthSession::getRole())
        );
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    private function toIntArray(mixed $value): array
    {
        return is_array($value) ? array_map('intval', $value) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(Request $request): ?array
    {
        $data = json_decode($request->getRawBody(), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function checkCsrf(array $data): bool
    {
        return CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''));
    }
}
