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
     * A hidden `_csrf_token` field must be written by the `csrf_field()`
     * Twig function, never by interpolating a `csrf_token` *variable*.
     *
     * `csrf_token` is registered as a Twig *function* (TwigFactory), so
     * `{{ csrf_token }}` is not a call — it is a lookup for a variable of
     * that name. When the controller happens to put one in the render
     * context it works; when it forgets, `strict_variables` is off and the
     * field renders `value=""`. Nothing warns: the page looks perfect, and
     * every POST from it dies on the CSRF guard with « Votre session a
     * expiré. » The whole camps module shipped that way — twenty-four
     * fields, twelve templates, not one controller passing the variable,
     * so adding a camp was impossible.
     *
     * `{{ csrf_field() }}` is a real call that emits the entire input, so
     * it cannot depend on a context nothing enforces. The entries below
     * are the pre-existing variable-based fields; they work only because
     * their controller passes `csrf_token` today, which is exactly the
     * coupling this rule exists to stop spreading.
     *
     * @var array<string, int> template path => count of variable-based fields
     */
    private const CSRF_VARIABLE_ALLOWLIST = [
        'core/View/templates/auth/password_reset.html.twig' => 1,
        'core/View/templates/setup/index.html.twig' => 1,
        'core/View/templates/setup/token_gate.html.twig' => 1,
        'modules/gallery/views/album_form.html.twig' => 1,
        'modules/gallery/views/config.html.twig' => 1,
        'modules/gallery/views/location_form.html.twig' => 1,
        'modules/news/views/detail.html.twig' => 1,
        'modules/news/views/editor.html.twig' => 1,
        'modules/news/views/response_edit.html.twig' => 1,
        'modules/registration/views/public.html.twig' => 2,
        'modules/retro/views/settings.html.twig' => 1,
    ];

    /**
     * design.md §7.5 — `alert()`, `confirm()` and `prompt()` are the one
     * surface of this site that is not this site. The native box renders
     * the origin above the sentence (« 127.0.0.1:8000 dit : »), labels its
     * buttons in the browser's language rather than the page's, and gives
     * « Supprimer définitivement cet album » exactly the same two grey
     * buttons as « Recréer les catégories par défaut ». On iOS it is a
     * system sheet that takes the whole screen.
     *
     * The site has one of each instead — `window.ScoutMagicToast.show()`,
     * `window.ScoutMagicConfirm.ask()` and `.prompt()`, all loaded on every
     * page by base.html.twig.
     *
     * There were 91 native calls when this rule landed. The list is now
     * EMPTY — not one alert(), confirm() or prompt() is left anywhere in
     * `public/assets/js/` or in a template — and it must stay so. The last
     * entries went as their templates' inline `<script>` blocks moved to
     * real files, which is also the only way they became testable at all.
     *
     * @var array<string, int> file path (repo-relative) => count
     */
    private const NATIVE_DIALOG_ALLOWLIST = [];

    /**
     * design.md §7.3 — the breadcrumb bar is the site's only back
     * affordance; « Retour » controls duplicate it (two stacked back
     * affordances on mobile) or, worse, exist only on pages whose
     * breadcrumb was never declared.
     *
     * Permanent exceptions, each with a reason:
     * - errors/403, errors/404, errors/constraint: error pages render
     *   outside any navigable hierarchy; « Retour à l'accueil » is the
     *   recovery action. (errors/constraint is the 409/400 a write the
     *   database refused produces — SECURITY.md § 35.)
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
        'core/View/templates/errors/constraint.html.twig',
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
    private const UNWRAPPED_TABLE_ALLOWLIST = [];

    /**
     * design.md §7.6 — base.html.twig already renders
     * `<main class="container py-3">`; a view that opens another
     * `.container` doubles the gutters (≈24px lost on a 360px screen) and
     * gives its module a page width of its own.
     *
     * @var list<string> template paths still nesting a container
     */
    private const NESTED_CONTAINER_ALLOWLIST = [];

    /**
     * design.md §7.2 — touch sizing lives in app.css's `pointer: coarse`
     * block, never in an inline style: an inline `min-height:44px` beats
     * every stylesheet rule including the desktop restore, which is how
     * the site ended up with 44px buttons on desktop in exactly one
     * module. These patches exist because the coarse block misses `.btn`
     * (non-sm); they all go away when that is fixed.
     *
     * This list is a ratchet: it only ever shrinks. The five the old
     * selection component carried went with the file itself — its
     * replacements, select_bar and nav_rail, carry none, taking their
     * height from `.tap-target` in that same coarse block instead.
     *
     * @var array<string, int> template path => inline 44px patch count
     */
    private const INLINE_TOUCH_PATCH_ALLOWLIST = [
        'core/View/templates/base.html.twig' => 1,
        'core/View/templates/partials/nav.html.twig' => 3,
        'core/View/templates/partials/notification_dropdown.html.twig' => 1,
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
        // XLSX download of the fee-accuracy screen, never a page.
        '/admin/fees/tarifs/export',
        // XLSX download of one invoice's verification report, likewise.
        '/admin/fees/factures/{id}/export',
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
        // How many people the send would reach, asked from the send
        // dialog just before the confirmation — JSON, no page.
        '/mass-mail/{id}/recipient-count',
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
    private const EXTRA_ALLOWED_PARENT_LABELS = [];

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

    public function testCsrfFieldsComeFromTheFunctionNotAVariable(): void
    {
        $found = [];
        foreach (self::templates() as $rel) {
            $count = preg_match_all(
                '/name="_csrf_token"[^>]*value="\{\{\s*csrf_token\s*\}\}"/',
                self::templateSource($rel)
            );
            if ($count > 0) {
                $found[$rel] = $count;
            }
        }
        self::assertMatchesAllowlist(
            $found,
            self::CSRF_VARIABLE_ALLOWLIST,
            'A _csrf_token field must come from csrf_field(); {{ csrf_token }} is a variable lookup that renders empty '
                . 'whenever the controller forgets it, and every POST then fails the CSRF guard'
        );
    }

    /**
     * One spelling of the call, everywhere: `{{ csrf_field()|raw }}`.
     *
     * The two forms are functionally identical — the function is
     * registered with `['is_safe' => ['html']]` (Core\View\TwigFactory), so
     * `|raw` is a no-op — and that is exactly why this is worth pinning
     * rather than leaving to taste: nothing breaks when the two drift
     * apart, so they drift. The filter is the documented form
     * (docs/module-development.md § CSRF token in a form) for a reason that
     * survives the no-op: someone auditing which templates emit raw HTML
     * greps for `|raw`, and a call site without it is one the grep misses.
     */
    public function testCsrfFieldIsAlwaysWrittenWithTheRawFilter(): void
    {
        $found = [];
        foreach (self::templates() as $rel) {
            $count = preg_match_all(
                '/csrf_field\(\)\s*(?!\|\s*raw)/',
                self::templateSource($rel)
            );
            if ($count > 0) {
                $found[$rel] = $count;
            }
        }
        self::assertMatchesAllowlist(
            $found,
            [],
            'csrf_field() is written {{ csrf_field()|raw }} — one form everywhere, so a |raw audit finds every '
                . 'template that emits raw HTML, including the ones that are safe by construction'
        );
    }

    public function testNoNativeBrowserDialogs(): void
    {
        $found = [];

        foreach (self::templates() as $rel) {
            $inline = '';
            preg_match_all('~<script[^>]*>(.*?)</script>~s', self::templateSource($rel), $blocks);
            foreach ($blocks[1] as $block) {
                $inline .= "\n" . $block;
            }
            $count = self::countNativeDialogCalls($inline);
            if ($count > 0) {
                $found[$rel] = $count;
            }
        }

        foreach (glob(self::repoRoot() . '/public/assets/js/*.js') ?: [] as $path) {
            $count = self::countNativeDialogCalls((string) file_get_contents($path));
            if ($count > 0) {
                $found[str_replace(self::repoRoot() . '/', '', $path)] = $count;
            }
        }

        self::assertMatchesAllowlist(
            $found,
            self::NATIVE_DIALOG_ALLOWLIST,
            'Use ScoutMagicToast.show() / ScoutMagicConfirm.ask() / .prompt() — never the browser\'s own box'
        );
    }

    /**
     * design.md §7.5 — behaviour lives in `public/assets/js/`, never in a
     * template's own `<script>` block.
     *
     * A template is the one place JavaScript cannot be tested: Vitest
     * imports files, not Twig output, so an inline block is invisible to
     * the suite by construction — and every copy of a shared behaviour
     * this chantier found (the `data-confirm` handler, three PDF
     * thumbnail fallbacks, three « click a row to see its detail »
     * handlers) was living in one. Inline blocks also carry a CSP nonce
     * and interpolate server values into a script body, where a value
     * containing `</script` ends the block mid-statement.
     *
     * Two shapes stay legitimate and are NOT counted:
     * - `<script src=…>`, which is the point;
     * - `<script type="application/json">` data islands, read with
     *   `ScoutMagicApi.pageData()` — data to the parser, not code.
     *
     * There were 26 templates with real inline blocks when this rule
     * landed. The list below is what is left, each with a reason no file
     * could serve.
     *
     * @var array<string, int> template path => count of inline blocks
     */
    private const INLINE_SCRIPT_ALLOWLIST = [
        // The three blocks base.html.twig cannot move out:
        //  - the anti-FOUC theme bootstrap, which MUST run synchronously
        //    before the first paint (a deferred file paints light, then
        //    flips to dark in front of the visitor);
        //  - the service-worker registration, whose URL carries the Twig
        //    `app_version` and `pwa_icon_version` cache busters;
        //  - `offline-config-data` is a JSON island and is not counted.
        'core/View/templates/base.html.twig' => 2,
    ];

    public function testBehaviourLivesInFilesNotInTemplates(): void
    {
        $found = [];

        foreach (self::templates() as $rel) {
            $count = 0;
            preg_match_all('~<script\b([^>]*)>(.*?)</script~s', self::templateSource($rel), $blocks, PREG_SET_ORDER);
            foreach ($blocks as $block) {
                if (str_contains($block[1], 'src=') || str_contains($block[1], 'application/json')) {
                    continue;
                }
                if (trim($block[2]) === '') {
                    continue;
                }
                $count++;
            }
            if ($count > 0) {
                $found[$rel] = $count;
            }
        }

        self::assertMatchesAllowlist(
            $found,
            self::INLINE_SCRIPT_ALLOWLIST,
            'Behaviour belongs in public/assets/js/ (a spec can import a file, never a Twig block);'
            . ' server data belongs in a <script type="application/json"> island'
        );
    }

    /**
     * Counts real `alert(...)`/`confirm(...)`/`prompt(...)` CALLS in a
     * chunk of JavaScript.
     *
     * Comments are stripped first: half the files that use the shared
     * dialogs explain in prose which native box they replaced, and
     * counting that documentation as a violation would punish exactly the
     * files that fixed the problem. The `//` strip skips `://` so a URL in
     * a string does not swallow the rest of its line. A `function
     * prompt(...)` DECLARATION is not a call — confirm.js defines one.
     */
    private static function countNativeDialogCalls(string $js): int
    {
        $js = (string) preg_replace('~/\*.*?\*/~s', '', $js);
        $js = (string) preg_replace('~(?<!:)//[^\n]*~', '', $js);

        return preg_match_all(
            '~(?<![\w.$])(?<!function\s)(?:window\.)?(?:alert|confirm|prompt)\s*\(~',
            $js
        );
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

    /**
     * Every Bootstrap Icons class the site names must exist in the
     * VENDORED stylesheet. An icon class that does not exist renders
     * nothing at all — no box, no fallback, no console error — so the
     * menu entry simply has no icon and nobody notices until a user says
     * so. That is exactly how the Camps module shipped with `bi-tent`,
     * an icon added upstream after the version vendored here.
     *
     * Scans both the manifests' `menu_icon` and every `bi-…` class written
     * in a template, so an icon picked from the current bootstrap-icons
     * website while the site ships an older release fails here rather
     * than in production. Upgrading the vendored library (AGENTS.md
     * § CSS / frontend) is what widens the set.
     */
    public function testEveryIconClassExistsInTheVendoredStylesheet(): void
    {
        $root = self::repoRoot();
        $css = (string) file_get_contents($root . '/public/assets/vendor/bootstrap-icons/bootstrap-icons.min.css');
        preg_match_all('/\.(bi-[a-z0-9-]+)/', $css, $cssMatches);
        $available = array_flip($cssMatches[1]);

        /** @var array<string, string> $used icon class => where it was found */
        $used = [];
        foreach (glob($root . '/modules/*/module.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);
            self::assertIsArray($data, $manifest);
            foreach ($data['routes'] ?? [] as $route) {
                if (($route['menu_icon'] ?? '') !== '') {
                    $used[$route['menu_icon']] = basename(dirname($manifest)) . '/module.json';
                }
            }
        }
        foreach (self::templates() as $rel) {
            preg_match_all('/\b(bi-[a-z0-9-]+)/', self::templateSource($rel), $matches);
            foreach ($matches[1] as $icon) {
                $used[$icon] = $used[$icon] ?? $rel;
            }
        }

        $missing = [];
        foreach ($used as $icon => $where) {
            // `bi` alone is the font's own base class, never an icon.
            if ($icon === 'bi-' || isset($available[$icon])) {
                continue;
            }
            $missing[$where . ': ' . $icon] = 1;
        }

        self::assertMatchesAllowlist($missing, [], 'This icon does not exist in the vendored Bootstrap Icons release — it renders as nothing at all');
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

    /**
     * A Twig comment must not contain a bare `<html>`, `<head>` or
     * `<body>` in its prose.
     *
     * Static analysers read a .html.twig file as HTML, and none of them
     * knows `{# … #}`. A comment in base.html.twig explaining that
     * Bootstrap themes off `data-bs-theme` on `<html>` therefore reads as
     * a SECOND <html> element, opened inside <head> — one with no `lang`
     * and no <title>. SonarQube reported exactly that: two bugs on the
     * comment line, plus a third blaming the real <head> for the title it
     * does have, because the stray tag had broken the tree. Three
     * reliability bugs, a failed quality gate, and nothing wrong with the
     * page.
     *
     * The fix is free — write "the root element", or `html` without the
     * angle brackets — so this keeps it from coming back. Only the three
     * document-level tags are checked: they are the ones that make a
     * parser think a new page started.
     */
    public function testNoTwigCommentContainsABarePageTag(): void
    {
        $found = [];

        foreach (self::templates() as $rel) {
            // The RAW source: templateSource() strips comments, which is
            // exactly what this one needs to read.
            $source = (string) file_get_contents(self::repoRoot() . '/' . $rel);
            preg_match_all('/\{#.*?#\}/s', $source, $comments);
            foreach ($comments[0] as $comment) {
                if (preg_match('~</?(html|head|body)\b[^>]*>~i', $comment, $tag) === 1) {
                    $line = substr_count(substr($source, 0, (int) strpos($source, $comment)), "\n") + 1;
                    $found[$rel . ':' . $line] = $tag[0];
                }
            }
        }

        self::assertSame(
            [],
            $found,
            "An HTML analyser reads this file as HTML and does not know {# … #}: a bare page tag\n"
            . "in prose reads as a real element and breaks the parse. Write it without the angle\n"
            . "brackets.\n  " . implode("\n  ", array_map(
                static fn (string $k, string $v): string => "{$k} — {$v}",
                array_keys($found),
                $found
            ))
        );
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
