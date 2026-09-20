<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Page\TextPage;
use Core\Page\TextPageException;
use Core\Page\TextPageService;
use Core\Security\AuthSession;
use Core\View\ConfigurationMode;
use Core\View\MenuBuilder;
use Twig\Environment;

/**
 * Configuration › Site › Pages de texte — where a superadmin creates the
 * free-text pages of ARCHITECTURE.md §8.116.
 *
 * **This controller checks no role.** Every one of its routes is declared
 * `superadmin` in `public/index.php`, and the RBAC guard runs before any
 * of these methods (SECURITY.md §3). A re-check here would be a second
 * answer to a question the router already answered, and the one that
 * drifts.
 *
 * The screen is deliberately thin: the pages' rules — the frozen slug,
 * the unique address, the section + column pair, the content row claimed
 * with the page — all live in {@see TextPageService}, which IT-01
 * delivered and tested. What is new here is the way in.
 */
class TextPageConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private TextPageService $pages,
        private JournalService $journal
    ) {
    }

    /**
     * GET /config/pages-de-texte
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        // Grouped by section, because the order shown is a rank INSIDE a
        // section: one flat sortable list would let a drag across a
        // boundary post an order that reload cannot honour.
        $bySection = [];
        foreach (MenuBuilder::menuIds() as $menuId) {
            $bySection[$menuId] = [
                'id' => $menuId,
                'label' => MenuBuilder::labelFor($menuId),
                'pages' => [],
            ];
        }

        foreach ($this->pages->listAll() as $page) {
            if (!isset($bySection[$page->menuId])) {
                continue;
            }
            $bySection[$page->menuId]['pages'][] = $this->describe($page);
        }

        return $this->render('config/text_pages/index.html.twig', [
            'sections' => array_values($bySection),
        ]);
    }

    /**
     * GET /config/pages-de-texte/nouveau
     *
     * @param array<string, string> $params
     */
    public function createForm(Request $request, array $params): Response
    {
        return $this->render('config/text_pages/form.html.twig', $this->formContext(null));
    }

    /**
     * GET /config/pages-de-texte/{id}
     *
     * @param array<string, string> $params
     */
    public function editForm(Request $request, array $params): Response
    {
        $page = $this->pages->findById((int) ($params['id'] ?? 0));
        if ($page === null) {
            return $this->notFound();
        }

        return $this->render('config/text_pages/form.html.twig', $this->formContext($page));
    }

    /**
     * POST /config/pages-de-texte — create, then open the page to write it.
     *
     * « Créer et ouvrir » is one button because the two halves are one
     * intention: a page was just created empty, and the only sensible
     * next thing is to go and fill it. So this saves, switches the
     * session into configuration mode, and lands on the page itself.
     *
     * The mode grants nothing — every save re-checks the role server-side
     * (ARCHITECTURE.md §8.2) — and it stays on for the session like
     * everywhere else on the site, with no exception carved out here.
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/pages-de-texte/nouveau')) !== null) {
            return $guard;
        }

        try {
            $page = $this->pages->create(
                (string) $request->getBody('menu_label', ''),
                (string) $request->getBody('title', ''),
                (string) $request->getBody('menu_id', ''),
                self::submittedGroup($request),
            );
        } catch (TextPageException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/config/pages-de-texte/nouveau');
        }

        $this->journal->log(
            'core',
            'text_page_created',
            'info',
            'Page de texte créée',
            ['text_page_id' => $page->id],
            AuthSession::getUserAccountId()
        );

        ConfigurationMode::activate(AuthSession::getRole());
        FlashMessage::set('success', 'Page créée. Le mode configuration est activé : écrivez son texte ici.');

        return $this->redirect($page->path());
    }

    /**
     * POST /config/pages-de-texte/{id}
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if (($guard = $this->guardCsrf($request, '/config/pages-de-texte/' . $id)) !== null) {
            return $guard;
        }

        $before = $this->pages->findById($id);

        try {
            $this->pages->update(
                $id,
                (string) $request->getBody('menu_label', ''),
                (string) $request->getBody('title', ''),
                (string) $request->getBody('menu_id', ''),
                self::submittedGroup($request),
            );
        } catch (TextPageException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/config/pages-de-texte/' . $id);
        }

        // **A section change is an audience change, and it is journaled.**
        // Renaming a page changes neither who reads it, nor its address,
        // nor its existence — which is why a rename writes nothing. But
        // this same form can move a page out of Configuration and into
        // « Notre unité », and that is the whole access-control decision
        // for it: `TextPage::roleMin()` derives the route's floor from
        // the section. Hiding a page is already journaled twice over; a
        // move that publishes one to the open internet cannot be the
        // silent operation.
        $after = $this->pages->findById($id);
        if ($before !== null && $after !== null && $before->menuId !== $after->menuId) {
            $this->journal->log(
                'core',
                'text_page_moved',
                'security',
                'Page de texte déplacée de section',
                ['text_page_id' => $id, 'from_menu' => $before->menuId, 'to_menu' => $after->menuId],
                AuthSession::getUserAccountId()
            );
        }

        FlashMessage::set('success', 'Page modifiée.');

        return $this->redirect('/config/pages-de-texte');
    }

    /**
     * POST /config/pages-de-texte/ordre — list_editor's drag and drop.
     *
     * @param array<string, string> $params
     */
    public function reorder(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $ids = array_map('intval', (array) ($data['ids'] ?? []));
        $this->pages->reorder($ids);

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/pages-de-texte/activation — list_editor's toggle.
     *
     * Activation lives on the list's row and nowhere else: the form has
     * no field for it, because a page is activated or hidden from where
     * you can see all of them at once.
     *
     * @param array<string, string> $params
     */
    public function toggleActive(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $id = (int) ($data['id'] ?? 0);
        $active = (bool) ($data['active'] ?? false);

        try {
            $this->pages->setActive($id, $active);
        } catch (TextPageException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journal->log(
            'core',
            $active ? 'text_page_activated' : 'text_page_deactivated',
            'info',
            $active ? 'Page de texte réactivée' : 'Page de texte masquée',
            ['text_page_id' => $id],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/pages-de-texte/suppression — list_editor's trash icon.
     *
     * The page's text goes with it, by `ON DELETE CASCADE` rather than by
     * a second statement issued here (ARCHITECTURE.md §8.116).
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $id = (int) ($data['id'] ?? 0);

        try {
            $this->pages->delete($id);
        } catch (TextPageException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journal->log(
            'core',
            'text_page_deleted',
            'info',
            'Page de texte supprimée',
            ['text_page_id' => $id],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * An empty column is null, not `''`.
     *
     * « Notre unité » declares no columns, and its `<select>` is not
     * rendered at all — but a browser that submits the field anyway, or a
     * hand-made request, would otherwise hand `TextPageService` an empty
     * string where it expects null and be refused for the wrong reason.
     */
    private static function submittedGroup(Request $request): ?string
    {
        $group = trim((string) $request->getBody('menu_group', ''));

        return $group === '' ? null : $group;
    }

    /**
     * @return array<string, mixed>
     */
    private function formContext(?TextPage $page): array
    {
        $menuId = $page !== null ? $page->menuId : MenuBuilder::MENU_NOTRE_UNITE;

        $sections = [];
        foreach (MenuBuilder::menuIds() as $id) {
            $sections[$id] = MenuBuilder::labelFor($id);
        }

        // Every section's columns, so the browser can swap the second
        // picker without a round trip. The server validates the pair
        // again on save whatever the browser did — hiding the picker is
        // the convenience, assertMenuPlacement() is the rule.
        $groupsBySection = [];
        foreach (MenuBuilder::menuIds() as $id) {
            $labels = [];
            foreach (MenuBuilder::MENU_GROUPS[$id] ?? [] as $group) {
                $labels[(string) $group['id']] = (string) $group['label'];
            }
            $groupsBySection[$id] = $labels;
        }

        return [
            'page' => $page,
            'sections' => $this->options($sections, $menuId),
            'groups_by_section' => $groupsBySection,
            'selected_group' => $page !== null ? $page->menuGroup : $this->pages->defaultGroupFor($menuId),
        ];
    }

    /**
     * One row of the list, with its placement spelled out in the words
     * the menus themselves use.
     *
     * @return array<string, mixed>
     */
    private function describe(TextPage $page): array
    {
        $groupLabel = null;
        foreach (MenuBuilder::MENU_GROUPS[$page->menuId] ?? [] as $group) {
            if ($group['id'] === $page->menuGroup) {
                $groupLabel = (string) $group['label'];
                break;
            }
        }

        return [
            'id' => $page->id,
            'is_active' => $page->isActive,
            'menu_label' => $page->menuLabel,
            'title' => $page->title,
            'section_label' => MenuBuilder::labelFor($page->menuId),
            'group_label' => $groupLabel,
            'path' => $page->path(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(Request $request): ?array
    {
        $data = json_decode($request->getRawBody(), true);

        return is_array($data) ? $data : null;
    }
}
