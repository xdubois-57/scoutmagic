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
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\MassMail\Repository\MailingList;
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
        private MailingListService $mailingListService
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
                ]],
                []
            ),
            'all_functions' => $this->mailingListService->getAllFunctions(),
            'all_sections' => $this->mailingListService->getAllSections(),
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
                $this->toIntArray($data['section_ids'] ?? [])
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
