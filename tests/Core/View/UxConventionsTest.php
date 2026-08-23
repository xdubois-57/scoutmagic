<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * Mechanical guards for the UI conventions written down in design.md §7.
 *
 * Every rule here exists because the codebase diverged once already and a
 * review had to count the damage by hand (114 inline touch-target patches,
 * five ways to render an empty state, seven dead confirmations...). A
 * convention no test defends drifts back into divergence within months —
 * the first version of this very file was delivered on a review branch
 * that was deleted without being merged, and the drift resumed.
 *
 * Each rule works the same way: scan the real templates/manifests/sources,
 * compare the violations found against an explicit allowlist of the
 * violations that existed when the rule landed. The assertion cuts BOTH
 * ways — a new violation fails ("follow design.md §7"), and a fixed one
 * still listed fails too ("remove it from the allowlist"), so the lists
 * only ever shrink. An empty list means the rule is fully enforced.
 */
final class UxConventionsTest extends TestCase
{
    /**
     * design.md §7.5 — inline `on*=` handlers are dead code under this
     * site's CSP (`script-src 'self' 'nonce-…'`; a nonce never covers an
     * attribute handler). They fail silently: the confirmation or submit
     * hook they promise simply never runs. base.html.twig documents the
     * trap; `data-confirm` (delegated) is the site's mechanism.
     *
     * @var array<string, int> template path (repo-relative) => count
     */
    private const INLINE_HANDLER_ALLOWLIST = [];

    /**
     * design.md §7.5 — `data-confirm` works ONLY on the `<form>` element:
     * the delegated handler listens on `submit` and reads
     * `e.target.closest('form[data-confirm]')`. On a button (or anything
     * else) the attribute is silently inert and the "are you sure?" text
     * someone carefully wrote is never shown.
     *
     * @var array<string, int> template path => count of non-form carriers
     */
    private const DATA_CONFIRM_ALLOWLIST = [];

    /**
     * design.md §7.3 — the breadcrumb bar is the site's only back
     * affordance; « Retour » controls duplicate it (two stacked back
     * affordances on mobile) or, worse, exist only on pages whose
     * breadcrumb was never declared.
     *
     * Permanent exceptions, each with a reason:
     * - errors/403, errors/404: error pages render outside any navigable
     *   hierarchy; « Retour à l'accueil » is the recovery action.
     * - pwa/offline: the offline interstitial's Retour is history.back(),
     *   there is no server-side trail to stand in for it.
     * - mass_mail/_compose_dialog: « Retour au brouillon » is a wizard
     *   step control inside a dialog, not page navigation.
     *
     * @var list<string> template paths allowed to keep a Retour control
     */
    private const BACK_BUTTON_EXCEPTIONS = [
        'core/View/templates/errors/403.html.twig',
        'core/View/templates/errors/404.html.twig',
        'core/View/templates/pwa/offline.html.twig',
        'modules/mass_mail/views/_compose_dialog.html.twig',
    ];

    /**
     * Violations that existed when the rule landed have all been migrated
     * into the breadcrumb trail (or reworded into a next-step label where
     * the link is forward-flow, e.g. « Se connecter » after a password
     * reset) — the list is empty and must stay so.
     *
     * @var array<string, int> template path => count
     */
    private const BACK_BUTTON_ALLOWLIST = [];

    /**
     * design.md §7.5 — FlashMessage's contract is success|error|warning.
     * 'danger' only renders by accident of the pass-through in
     * base.html.twig; any rendering evolution keyed on the documented
     * types (icons, ARIA roles) will silently miss these messages.
     *
     * @var array<string, int> PHP file => count of off-contract types
     */
    private const FLASH_TYPE_ALLOWLIST = [];

    /**
     * design.md §7.6 — every data `<table>` scrolls inside its own
     * wrapper; without one, the page body scrolls sideways on a phone
     * (the mass-mail list's « Destin… » column, cut at the viewport edge,
     * is the canonical screenshot). Email templates are exempt — mail
     * clients need plain tables.
     *
     * @var array<string, int> template path => unwrapped table count
     */
    private const UNWRAPPED_TABLE_ALLOWLIST = [
        'core/View/templates/cookies/preferences.html.twig' => 1,
        'modules/rental/views/management/_pricing.html.twig' => 1,
        'modules/rental/views/public/_estimate.html.twig' => 1,
        'modules/rental/views/public/tracking.html.twig' => 1,
    ];

    /**
     * design.md §7.6 — base.html.twig already renders
     * `<main class="container py-3">`; a view that opens another
     * `.container` doubles the gutters (≈24px lost on a 360px screen) and
     * gives its module a page width of its own.
     *
     * @var list<string> template paths still nesting a container
     */
    private const NESTED_CONTAINER_ALLOWLIST = [
        'modules/registration/views/config.html.twig',
        'modules/registration/views/departures.html.twig',
        'modules/registration/views/email_confirmed.html.twig',
        'modules/registration/views/forecast.html.twig',
        'modules/registration/views/passage.html.twig',
        'modules/registration/views/public.html.twig',
        'modules/registration/views/request_detail.html.twig',
        'modules/registration/views/submitted.html.twig',
        'modules/registration/views/tracking_full.html.twig',
        'modules/registration/views/tracking_minimal.html.twig',
        'modules/rental/views/config/index.html.twig',
        'modules/rental/views/management/booking.html.twig',
        'modules/rental/views/management/bookings.html.twig',
        'modules/rental/views/management/calendar.html.twig',
        'modules/rental/views/management/document_editor.html.twig',
        'modules/rental/views/management/my_rentals.html.twig',
        'modules/rental/views/management/no_access.html.twig',
        'modules/rental/views/management/overview.html.twig',
        'modules/rental/views/management/settings.html.twig',
        'modules/rental/views/management/stay.html.twig',
        'modules/rental/views/management/templates.html.twig',
        'modules/rental/views/public/index.html.twig',
        'modules/rental/views/public/request.html.twig',
        'modules/rental/views/public/show.html.twig',
        'modules/rental/views/public/tracking.html.twig',
        'modules/retro/views/board.html.twig',
        'modules/retro/views/config.html.twig',
        'modules/retro/views/list.html.twig',
    ];

    /**
     * design.md §7.2 — touch sizing lives in app.css's `pointer: coarse`
     * block, never in an inline style: an inline `min-height:44px` beats
     * every stylesheet rule including the desktop restore, which is how
     * the site ended up with 44px buttons on desktop in exactly one
     * module. These patches exist because the coarse block misses `.btn`
     * (non-sm); they all go away when that is fixed.
     *
     * @var array<string, int> template path => inline 44px patch count
     */
    private const INLINE_TOUCH_PATCH_ALLOWLIST = [
        'core/View/templates/account/index.html.twig' => 1,
        'core/View/templates/admin/members/_detail_card.html.twig' => 5,
        'core/View/templates/admin/members/_result_row.html.twig' => 2,
        'core/View/templates/admin/members/search.html.twig' => 1,
        'core/View/templates/base.html.twig' => 1,
        'core/View/templates/chefs/section_roster.html.twig' => 1,
        'core/View/templates/partials/chip_picker.html.twig' => 5,
        'core/View/templates/partials/nav.html.twig' => 3,
        'core/View/templates/partials/notification_dropdown.html.twig' => 1,
        'modules/groups/views/list.html.twig' => 9,
        'modules/groups/views/members.html.twig' => 9,
        'modules/groups/views/partials/feed_page.html.twig' => 1,
        'modules/groups/views/partials/group_edit_form.html.twig' => 7,
        'modules/groups/views/partials/group_header.html.twig' => 5,
        'modules/groups/views/partials/poll.html.twig' => 2,
        'modules/groups/views/partials/post_card.html.twig' => 13,
        'modules/groups/views/partials/reply_card.html.twig' => 8,
        'modules/groups/views/partials/reply_page.html.twig' => 1,
        'modules/groups/views/partials/search_form.html.twig' => 3,
        'modules/groups/views/show.html.twig' => 10,
        'modules/member_stats/views/index.html.twig' => 1,
        'modules/sos_staff/views/admin.html.twig' => 1,
        'modules/sos_staff/views/partials/planned_transitions.html.twig' => 1,
        'modules/test_tools/views/mail_detail.html.twig' => 7,
        'modules/test_tools/views/mail_sandbox.html.twig' => 12,
    ];

    /**
     * design.md §7.3 — module GET routes that render a page must declare
     * a breadcrumb; without one the bar collapses to a lone home icon,
     * which on mobile reads as a rendering defect. Endpoints that never
     * render a page (JSON, fragments, files, feeds, redirects) are listed
     * here permanently instead.
     *
     * @var list<string> route paths (module.json) that render no page
     */
    private const NON_PAGE_MODULE_ROUTES = [
        '/admin/locations/gestionnaire-recherche',
        '/admin/sos/transitions',
        '/calendar/feed/personal/{token}.ics',
        '/calendar/feed/unit/{token}.ics',
        '/calendar/feed/{token}.ics',
        '/finance/movements/export',
        '/finance/movements/search',
        '/finance/movements/{id}/attachments',
        '/finance/receipts/search',
        '/finance/receipts/{id}/movements',
        '/gallery/media/{media_id}/download',
        '/gallery/media/{media_id}/{size}',
        '/gallery/{id}/download',
        // JSON payload for the create/edit dialog (MassMailController::
        // show()), not the tracking page — never renders a page.
        '/mass-mail/{id}',
        '/groups/{id}/feed',
        '/groups/{id}/media-status',
        '/groups/{id}/member-search',
        '/groups/{id}/mention-search',
        '/groups/{id}/posts/{postId}/reactions',
        '/groups/{id}/posts/{postId}/replies',
        '/groups/{id}/posts/{postId}/seen-by',
        '/groups/{id}/replies/{replyId}/reactions',
        '/locations/suivi/{id}/{token}/calendrier.ics',
        '/locations/{slug}/apercu',
        '/mass-mail/audiences/{id}',
        '/mass-mail/{id}/merge-preview',
        '/news/{id}/form/responses/export',
        '/news/{id}/poster',
        '/r/{token}/poll',
        '/r/{token}/qr',
        '/support-dashboard/export',
        // Dialog-body fragment fetched on click (views/partials/
        // detail.html.twig does not extend base) — never a full page.
        '/support-dashboard/installations/{id}',
    ];

    /**
     * design.md §7.3 — a breadcrumb `parents` entry only renders as a
     * live control when it exactly matches a menu label; anything else is
     * inert grey text. Dynamic menu entries (MenuEntryProvider) are legal
     * targets too.
     *
     * @var list<string> parent labels accepted besides MenuBuilder's
     */
    private const EXTRA_ALLOWED_PARENT_LABELS = [
        // Dynamic entries contributed by modules (MenuEntryProvider).
        'Locations',
        'Mes locations',
    ];

    /** Menu labels a `parents` entry can point at (MenuBuilder::MENUS). */
    private const MENU_LABELS = [
        'Notre unité',
        'Espace membres',
        'Espace animateurs',
        "Espace chefs d'U",
        'Configuration',
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> repo-relative template paths */
    private static function templates(): array
    {
        $root = self::repoRoot();
        $paths = [];
        foreach (['core/View/templates', 'modules'] as $base) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->getExtension() !== 'twig') {
                    continue;
                }
                $rel = str_replace($root . '/', '', $file->getPathname());
                if ($base === 'modules' && !str_contains($rel, '/views/')) {
                    continue;
                }
                $paths[] = $rel;
            }
        }
        sort($paths);

        return $paths;
    }

    private static function templateSource(string $rel): string
    {
        $source = file_get_contents(self::repoRoot() . '/' . $rel);
        self::assertIsString($source, $rel);

        // Twig comments regularly *document* the anti-patterns these rules
        // hunt (base.html.twig explains the on*= trap at length) — never
        // count documentation as a violation.
        return (string) preg_replace('/\{#.*?#\}/s', '', $source);
    }

    private static function isEmailTemplate(string $rel): bool
    {
        return str_contains($rel, '/email/') || str_contains($rel, '/emails/');
    }

    /**
     * Both-ways comparison: new violations fail, fixed-but-still-listed
     * entries fail too, so the allowlists only ever shrink.
     *
     * @param array<string, int> $found
     * @param array<string, int> $allowlist
     */
    private static function assertMatchesAllowlist(array $found, array $allowlist, string $rule): void
    {
        ksort($found);
        $messages = [];
        foreach ($found as $file => $count) {
            $allowed = $allowlist[$file] ?? 0;
            if ($count > $allowed) {
                $messages[] = "$file has $count (allowlist: $allowed)";
            }
        }
        foreach ($allowlist as $file => $allowed) {
            if (($found[$file] ?? 0) < $allowed) {
                $messages[] = "$file is listed for $allowed but now has " . ($found[$file] ?? 0)
                    . ' — shrink the allowlist to keep the ratchet honest';
            }
        }
        self::assertSame([], $messages, "$rule (see design.md §7):\n" . implode("\n", $messages));
    }

    public function testNoInlineEventHandlerAttributes(): void
    {
        $found = [];
        foreach (self::templates() as $rel) {
            $count = preg_match_all(
                '/\son(?:submit|click|change|load|input|keyup|keydown|focus|blur|mouseover)\s*=/i',
                self::templateSource($rel)
            );
            if ($count > 0) {
                $found[$rel] = $count;
            }
        }
        self::assertMatchesAllowlist($found, self::INLINE_HANDLER_ALLOWLIST, 'Inline on*= handlers are dead under the CSP — use data-confirm or a script file');
    }

    public function testDataConfirmOnlyOnForms(): void
    {
        $found = [];
        foreach (self::templates() as $rel) {
            $count = 0;
            preg_match_all('/<(\w+)[^>]*?\bdata-confirm\s*=/', self::templateSource($rel), $m);
            foreach ($m[1] as $tag) {
                if (strtolower($tag) !== 'form') {
                    $count++;
                }
            }
            if ($count > 0) {
                $found[$rel] = $count;
            }
        }
        self::assertMatchesAllowlist($found, self::DATA_CONFIRM_ALLOWLIST, 'data-confirm is read on the <form> only; anywhere else it is silently inert');
    }

    public function testBreadcrumbIsTheOnlyBackAffordance(): void
    {
        $found = [];
        foreach (self::templates() as $rel) {
            if (in_array($rel, self::BACK_BUTTON_EXCEPTIONS, true)) {
                continue;
            }
            $count = preg_match_all(
                '/<(?:a|button)\b[^>]*>\s*(?:<i\b[^>]*><\/i>\s*|&laquo;\s*|«\s*)*Retour\b/u',
                self::templateSource($rel)
            );
            if ($count > 0) {
                $found[$rel] = $count;
            }
        }
        self::assertMatchesAllowlist($found, self::BACK_BUTTON_ALLOWLIST, 'No « Retour » controls — the destination belongs in the breadcrumb (or reword a forward-flow link as its next step)');
    }

    public function testFlashTypesStayOnContract(): void
    {
        $root = self::repoRoot();
        $found = [];
        foreach (['core', 'modules', 'public'] as $base) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                preg_match_all("/FlashMessage::set\\(\\s*'(\\w+)'/", $source, $m);
                $count = 0;
                foreach ($m[1] as $type) {
                    if (!in_array($type, ['success', 'error', 'warning'], true)) {
                        $count++;
                    }
                }
                if ($count > 0) {
                    $found[str_replace($root . '/', '', $file->getPathname())] = $count;
                }
            }
        }
        self::assertMatchesAllowlist($found, self::FLASH_TYPE_ALLOWLIST, "FlashMessage types are success|error|warning — 'danger' only renders by accident");
    }

    public function testTablesAreWrappedAgainstHorizontalOverflow(): void
    {
        $found = [];
        foreach (self::templates() as $rel) {
            if (self::isEmailTemplate($rel)) {
                continue;
            }
            $source = self::templateSource($rel);
            $count = 0;
            foreach (self::pregOffsets('/<table\b/', $source) as $offset) {
                $before = substr($source, max(0, $offset - 300), min(300, $offset));
                if (
                    !str_contains($before, 'table-responsive')
                    && !str_contains($before, 'overflow-x')
                    && !str_contains($before, 'grid-wrapper')
                ) {
                    $count++;
                }
            }
            if ($count > 0) {
                $found[$rel] = $count;
            }
        }
        self::assertMatchesAllowlist($found, self::UNWRAPPED_TABLE_ALLOWLIST, 'Every <table> scrolls inside its own wrapper (.table-responsive)');
    }

    public function testViewsDoNotNestAContainer(): void
    {
        $found = [];
        foreach (self::templates() as $rel) {
            $source = self::templateSource($rel);
            if (!preg_match('/\{%\s*extends\s+[\'"](?:@\w+\/)?base\.html\.twig[\'"]/', $source)) {
                continue;
            }
            if (preg_match('/class="[^"]*(?<![-\w])container(?![-\w])/', $source)) {
                $found[$rel] = 1;
            }
        }
        $allow = array_fill_keys(self::NESTED_CONTAINER_ALLOWLIST, 1);
        self::assertMatchesAllowlist($found, $allow, 'base.html.twig already renders <main class="container"> — use a page width class, never a nested container');
    }

    public function testNoInlineTouchTargetPatches(): void
    {
        $found = [];
        foreach (self::templates() as $rel) {
            $count = preg_match_all('/min-height:\s*44px/', self::templateSource($rel));
            if ($count > 0) {
                $found[$rel] = $count;
            }
        }
        self::assertMatchesAllowlist($found, self::INLINE_TOUCH_PATCH_ALLOWLIST, "Touch sizing lives in app.css's pointer:coarse block, never inline");
    }

    public function testModulePageRoutesDeclareABreadcrumb(): void
    {
        $root = self::repoRoot();
        $missing = [];
        $declaredParents = [];
        foreach (glob($root . '/modules/*/module.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);
            self::assertIsArray($data, $manifest);
            foreach ($data['routes'] ?? [] as $route) {
                if (($route['method'] ?? '') !== 'GET') {
                    continue;
                }
                $path = $route['path'] ?? '';
                if (isset($route['breadcrumb'])) {
                    foreach ($route['breadcrumb']['parents'] ?? [] as $parent) {
                        $declaredParents[$parent][] = $path;
                    }
                    continue;
                }
                if (!in_array($path, self::NON_PAGE_MODULE_ROUTES, true)) {
                    $missing[$path] = 1;
                }
            }
        }

        self::assertMatchesAllowlist($missing, [], 'A GET route that renders a page declares a breadcrumb (or is listed as a non-page endpoint)');

        $allowedParents = array_merge(self::MENU_LABELS, self::EXTRA_ALLOWED_PARENT_LABELS);
        foreach ($declaredParents as $parent => $paths) {
            self::assertContains(
                $parent,
                $allowedParents,
                "Breadcrumb parent \"$parent\" (" . implode(', ', $paths) . ') matches no menu label — it will render as inert grey text. '
                . 'Use an exact MenuBuilder label, or a breadcrumb_trail for real ancestor pages.'
            );
        }
    }

    /** @return list<int> byte offsets of every match */
    private static function pregOffsets(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $m, PREG_OFFSET_CAPTURE);
        $offsets = [];
        foreach ($m[0] as [, $offset]) {
            $offsets[] = (int) $offset;
        }

        return $offsets;
    }
}
