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
use Core\View\DateFilterExtension;
use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Repository\MailingList;
use Modules\MassMail\Service\ListBlockService;
use Modules\MassMail\Service\MailingListException;
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\SendableScoutYears;
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
        private ListBlockService $listBlockService,
        private SendableScoutYears $sendableScoutYears
    ) {
    }

    /**
     * GET /admin/listes-de-diffusion
     *
     * One card per custom list, leading to its page: what it is made of —
     * how many member groups and contact groups, counted in one query for
     * the whole page — and how many people it reaches for the effective
     * year, the send's own number. The page modifies nothing about a list
     * but its state: it creates one, activates or deactivates it, deletes
     * it; what a list holds is decided on its own page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $year = $this->effectiveYear();
        $customLists = $this->mailingListService->getAllCustomLists();
        $blockCounts = $this->listBlockService->countKindsByList();

        $recipientCounts = [];
        foreach ($customLists as $list) {
            // A list without a block designates nobody, and its card says
            // so in words: nothing to resolve.
            if (isset($blockCounts[$list->id])) {
                $recipientCounts[$list->id] = $this->mailingListService->countRecipients($list->id, $year->id);
            }
        }

        return $this->render('@mass_mail/mailing_lists.html.twig', [
            'default_lists' => $this->mailingListService->getDefaultLists(),
            'custom_lists' => $customLists,
            'block_counts' => $blockCounts,
            'recipient_counts' => $recipientCounts,
            'effective_year_label' => $year->label,
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
     * GET /admin/listes-de-diffusion/{id} — the page of one custom list:
     * its total for the effective year, its blocks by kind, and the
     * dialogs that change them.
     *
     * The blocks reach the page as the same payload GET …/lists/{id}/blocks
     * answers, in a JSON island: the script draws them once from it and
     * redraws them from the route after any dialog saved, so the page has
     * one renderer rather than a Twig one and a JavaScript one that would
     * have to agree.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $list = $this->mailingListService->getCustomListById((int) $params['id']);
        if ($list === null) {
            return $this->notFound();
        }

        $year = $this->effectiveYear();

        return $this->render('@mass_mail/mailing_list.html.twig', [
            'list' => $list,
            'breadcrumb_current' => $list->name,
            'blocks_payload' => $this->blocksPayload($list, $year->id, $year->label),
            'recipient_years' => $this->recipientYears($year),
            'effective_year_id' => $year->id,
            'all_functions' => $this->mailingListService->getAllFunctions(),
            'all_sections' => $this->mailingListService->getAllSections(),
            'all_badges' => $this->mailingListService->getAllBadges(),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /admin/listes-de-diffusion/lists — create a custom mailing
     * list: a name and a description. It starts with no block.
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
     * PATCH /admin/listes-de-diffusion/lists/{id} — the name and the
     * description, the two things a list is besides its blocks (the
     * pencil on the page of a list).
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
                (string) ($data['description'] ?? '')
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
     * GET /admin/listes-de-diffusion/lists/{id}/blocks — the list's blocks
     * and its total for the effective year: what the page of a list
     * redraws after a dialog saved.
     *
     * @param array<string, string> $params
     */
    public function blocks(Request $request, array $params): Response
    {
        $list = $this->mailingListService->getCustomListById((int) $params['id']);
        if ($list === null) {
            return $this->json(['success' => false, 'error' => 'Liste introuvable.'], 404);
        }

        $year = $this->effectiveYear();

        return $this->json($this->blocksPayload($list, $year->id, $year->label));
    }

    /**
     * POST /admin/listes-de-diffusion/lists/{id}/member-groups — adds a
     * member group: three axes, at least one choice.
     *
     * @param array<string, string> $params
     */
    public function createMemberGroup(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $block = $this->listBlockService->createMemberGroup(
                (int) $params['id'],
                $this->toIntArray($data['function_ids'] ?? []),
                $this->toIntArray($data['section_ids'] ?? []),
                $this->toIntArray($data['badge_ids'] ?? []),
                AuthSession::getUserAccountId()
            );
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'block' => ['id' => $block->id, 'kind' => $block->kind]]);
    }

    /**
     * PATCH /admin/listes-de-diffusion/member-groups/{id} — replaces a
     * member group's three axes. A contact group is refused: it changes
     * by its file only.
     *
     * @param array<string, string> $params
     */
    public function updateMemberGroup(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $block = $this->listBlockService->updateMemberGroup(
                (int) $params['id'],
                $this->toIntArray($data['function_ids'] ?? []),
                $this->toIntArray($data['section_ids'] ?? []),
                $this->toIntArray($data['badge_ids'] ?? []),
                AuthSession::getUserAccountId()
            );
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'block' => ['id' => $block->id, 'kind' => $block->kind]]);
    }

    /**
     * DELETE /admin/listes-de-diffusion/blocks/{id} — removes a block of
     * either kind, from the page's trash icon after its confirmation.
     * A contact group's unsubscribes outlive it (Service\ListBlockService::
     * delete()).
     *
     * @param array<string, string> $params
     */
    public function deleteBlock(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null || !$this->checkCsrf($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $this->listBlockService->delete((int) $params['id'], AuthSession::getUserAccountId());
        } catch (MailingListException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * GET /admin/listes-de-diffusion/lists/{id}/recipients?year={id} —
     * « Voir les destinataires »: the people the list writes to for one
     * year, resolved and deduplicated as the send resolves it, each with
     * the blocks that bring them.
     *
     * The year is one an email can target, or the effective year the
     * page counts for; anything else is refused rather than resolved, so
     * the dialog never previews an audience no send could reach.
     *
     * A GET: it changes nothing, and what it carries — two ids — is no
     * secret for a proxy log to keep.
     *
     * @param array<string, string> $params
     */
    public function recipients(Request $request, array $params): Response
    {
        $list = $this->mailingListService->getCustomListById((int) $params['id']);
        if ($list === null) {
            return $this->json(['success' => false, 'error' => 'Liste introuvable.'], 404);
        }

        $effective = $this->effectiveYear();
        $years = array_column($this->recipientYears($effective), 'label', 'id');
        $yearId = (int) $request->getQuery('year', (string) $effective->id);
        if (!isset($years[$yearId])) {
            return $this->json(['success' => false, 'error' => 'Cette année ne peut pas être choisie.'], 422);
        }

        $blocks = [];
        foreach ($this->listBlockService->findForList($list->id) as $block) {
            $blocks[$block->id] = ['kind' => $block->kind, 'label' => $this->blockLabel($block)];
        }

        return $this->json([
            'success' => true,
            'scout_year_label' => $years[$yearId],
            'recipients' => array_map(
                static fn(array $r): array => [
                    'name' => $r['name'],
                    'display_name' => $r['display_name'],
                    'email' => $r['email'],
                    'block_ids' => $r['block_ids'],
                ],
                $this->mailingListService->listRecipients($list->id, $yearId)
            ),
            'blocks' => $blocks,
        ]);
    }

    /**
     * POST /admin/listes-de-diffusion/preview-count — how many members
     * the criteria CURRENTLY IN A MEMBER GROUP'S FORM resolve to, before
     * anything is saved.
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
        $functionIds = $this->toIntArray($data['function_ids'] ?? []);
        $sectionIds = $this->toIntArray($data['section_ids'] ?? []);
        $badgeIds = $this->toIntArray($data['badge_ids'] ?? []);

        return $this->json([
            'success' => true,
            'count' => $this->mailingListService->countMembersForCriteria(
                $functionIds,
                $sectionIds,
                $badgeIds,
                $year->id
            ),
            'scout_year_label' => $year->label,
            // The dialog's sentence, worded by the same method as the
            // page's lines, so a group reads the same before and after it
            // is saved.
            'sentence' => $this->mailingListService->criteriaSentence($functionIds, $sectionIds, $badgeIds),
        ]);
    }

    private function effectiveYear(): \Core\ScoutYear\EffectiveScoutYear
    {
        return $this->scoutYearResolver->getEffectiveYear(
            ScoutYearSession::getPreviewId(),
            Role::fromString(AuthSession::getRole())
        );
    }

    /**
     * What the page of a list draws: its total and split for one year,
     * then each block with its sentence or name and its own count —
     * member groups first, then contact groups, each by creation.
     *
     * @return array{
     *     success: true,
     *     scout_year_label: string,
     *     summary: array{total: int, members: int, contacts: int, contacts_unsubscribed: int},
     *     blocks: list<array{
     *         id: int,
     *         kind: string,
     *         name: ?string,
     *         sentence: list<array{text: string, strong: bool}>,
     *         count: int,
     *         unsubscribed: int,
     *         file_replaced_at: ?string,
     *         file_replaced_label: ?string,
     *         function_ids: int[],
     *         section_ids: int[],
     *         badge_ids: int[]
     *     }>
     * }
     */
    private function blocksPayload(MailingList $list, int $yearId, string $yearLabel): array
    {
        $summary = $this->mailingListService->summariseList($list->id, $yearId);

        $blocks = [];
        foreach ($this->listBlockService->findForList($list->id) as $block) {
            $blocks[] = [
                'id' => $block->id,
                'kind' => $block->kind,
                'name' => $block->name,
                'sentence' => $block->isMemberGroup()
                    ? $this->mailingListService->criteriaSentence(
                        $block->functionIds,
                        $block->sectionIds,
                        $block->badgeIds
                    )
                    : [],
                'count' => $summary['blocks'][$block->id]['count'] ?? 0,
                'unsubscribed' => $summary['blocks'][$block->id]['unsubscribed'] ?? 0,
                'file_replaced_at' => $block->fileReplacedAt,
                // « fichier remplacé le 12 septembre 2026 » — formatted
                // here, by the one French date formatter the templates use.
                'file_replaced_label' => $block->fileReplacedAt !== null
                    ? DateFilterExtension::frenchDate($block->fileReplacedAt)
                    : null,
                'function_ids' => $block->functionIds,
                'section_ids' => $block->sectionIds,
                'badge_ids' => $block->badgeIds,
            ];
        }

        return [
            'success' => true,
            'scout_year_label' => $yearLabel,
            'summary' => [
                'total' => $summary['total'],
                'members' => $summary['members'],
                'contacts' => $summary['contacts'],
                'contacts_unsubscribed' => $summary['contacts_unsubscribed'],
            ],
            'blocks' => $blocks,
        ];
    }

    /**
     * The years « Voir les destinataires » offers: those an email can
     * target (Service\SendableScoutYears), and the effective year the
     * page counts for when it is none of them — a superadmin previewing
     * another year reads the dialog for the year the page shows.
     *
     * @return list<array{id: int, label: string}>
     */
    private function recipientYears(\Core\ScoutYear\EffectiveScoutYear $effective): array
    {
        $years = $this->sendableScoutYears->available();
        if (!in_array($effective->id, array_column($years, 'id'), true)) {
            $years[] = ['id' => $effective->id, 'label' => $effective->label];
        }

        return $years;
    }

    private function blockLabel(ListBlock $block): string
    {
        return $block->isContactGroup()
            ? (string) $block->name
            : $this->mailingListService->criteriaLabel($block->functionIds, $block->sectionIds, $block->badgeIds);
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
