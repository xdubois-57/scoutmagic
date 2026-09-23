<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Exception\UserFacingMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Module\ModuleException;
use Core\Module\ModuleInfo;
use Core\Module\ModuleManifest;
use Core\Module\ModuleManager;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

/**
 * Configuration > Modules — module registry (activation/deactivation).
 * Split out of the former ConfigGeneralController
 * (which also carried badges and the configuration-mode toggle) so each
 * page has its own single-concern controller (AGENTS.md).
 */
class ConfigModulesController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ModuleManager $moduleManager,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /config/modules — render the module registry page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $modules = $this->moduleManager->discoverModules();

        $moduleItems = array_map(
            fn($mod) => [
                'id' => $mod->manifest->id,
                'info' => $mod,
                // Whether this module's hard dependencies (manifest "requires")
                // are all present, valid and enabled — resolved here rather
                // than in Twig, which has no access to the discovered set.
                'requirements_met' => $this->moduleManager->areRequirementsSatisfied($mod->manifest->id, $modules),
            ],
            $modules
        );

        return $this->render('config/modules.html.twig', [
            'modules' => $moduleItems,
            // The shelves, each with the modules that declared it, sorted
            // by name inside. Built here rather than in Twig: sorting
            // French names needs a collation Twig's `sort` does not offer,
            // and « Éclaireurs » has to land under E.
            'categories' => self::shelve($moduleItems),
            'active_count' => count(array_filter($moduleItems, static fn(array $i): bool => $i['info']->enabled)),
            // Hard dependencies are declared as ids; the page shows
            // names. Core never knows any module's name, so the map is
            // the manifests' own answer — an id with no module on disk
            // keeps the id as its own label (the template's |default).
            // Passing the already-discovered set rather than letting it
            // re-scan: this page has it in hand.
            'module_names' => $this->moduleManager->moduleNames($modules),
        ]);
    }

    /**
     * Group the modules onto the shelves `ModuleManifest::CATEGORIES`
     * declares, in that order, sorted by name inside each.
     *
     * **A shelf nobody is on is not drawn.** An installation that has no
     * money module should not be shown an empty « Argent » heading — a
     * titled section with nothing under it reads as something broken
     * rather than as something absent. Same rule the mega-menu follows
     * for a column whose every entry is filtered out.
     *
     * The sort is `Collator`-based where intl is available and falls back
     * to a natural, case-insensitive comparison otherwise: « Éclaireurs »
     * belongs under E, and `sort()` alone would file it after Z.
     *
     * @param list<array{id: string, info: ModuleInfo, requirements_met: bool}> $items
     * @return list<array{id: string, label: string, modules: list<array{id: string, info: ModuleInfo, requirements_met: bool}>}>
     */
    private static function shelve(array $items): array
    {
        $byCategory = [];
        foreach ($items as $item) {
            $byCategory[$item['info']->manifest->category][] = $item;
        }

        $collator = class_exists(\Collator::class) ? new \Collator('fr_BE') : null;

        $shelves = [];
        foreach (ModuleManifest::CATEGORIES as $id => $label) {
            $modules = $byCategory[$id] ?? [];
            if ($modules === []) {
                continue;
            }

            usort($modules, static function (array $a, array $b) use ($collator): int {
                $left = $a['info']->manifest->name;
                $right = $b['info']->manifest->name;

                if ($collator !== null) {
                    $compared = $collator->compare($left, $right);
                    if (is_int($compared)) {
                        return $compared;
                    }
                }

                return strnatcasecmp($left, $right);
            });

            $shelves[] = ['id' => $id, 'label' => $label, 'modules' => $modules];
        }

        return $shelves;
    }

    /**
     * POST /config/modules/toggle — activate/deactivate a module (AJAX).
     *
     * @param array<string, string> $params
     */
    public function toggleModule(Request $request, array $params): Response
    {
        $rawBody = $request->getRawBody();
        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
        }

        $moduleId = (string) ($data['module_id'] ?? '');
        $enabled = (bool) ($data['enabled'] ?? false);

        if ($moduleId === '') {
            return $this->json(['success' => false, 'error' => 'Identifiant de module manquant.'], 400);
        }

        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 403);
        }

        try {
            if ($enabled) {
                $this->moduleManager->activate($moduleId, $userId);
            } else {
                $this->moduleManager->deactivate($moduleId, $userId);
            }
        } catch (ModuleException $e) {
            // ModuleException is NOT a UserFacingException: its ~55 messages
            // are developer notes, several of which name a filesystem path
            // ("Module manifest not found: /var/www/…"). The real text goes
            // to the journal; the visitor gets the sentence below.
            $this->journalService->log(
                'core',
                $enabled ? 'module_activation_failed' : 'module_deactivation_failed',
                'info',
                ($enabled ? 'Échec de l\'activation' : 'Échec de la désactivation') . " du module « {$moduleId} »",
                ['module_id' => $moduleId, 'error' => $e->getMessage()],
                $userId
            );

            return $this->json(['success' => false, 'error' => UserFacingMessage::from(
                $e,
                $enabled
                    ? 'Ce module n\'a pas pu être activé — vérifiez qu\'il est complet et que les modules dont il '
                        . 'dépend sont activés.'
                    : 'Ce module n\'a pas pu être désactivé — vérifiez qu\'aucun autre module actif n\'en dépend.'
            )], 400);
        }

        return $this->json(['success' => true]);
    }

}
