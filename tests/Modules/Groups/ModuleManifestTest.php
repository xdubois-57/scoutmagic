<?php

declare(strict_types=1);

namespace Tests\Modules\Groups;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest is validated at load time by Core\Module\ModuleManager, so
 * a mistake here takes the module out of every menu with only a badge on
 * the Modules page to show for it. Same precedent as
 * Tests\Modules\Retro\ModuleManifestLabelTest.
 */
class ModuleManifestTest extends TestCase
{
    private ModuleManifest $manifest;

    protected function setUp(): void
    {
        $this->manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/groups/module.json');
    }

    public function testTheManifestParsesAndValidates(): void
    {
        $this->assertSame('groups', $this->manifest->id);
        $this->assertFalse($this->manifest->enabledByDefault);
    }

    /**
     * Pinned deliberately: ModuleManager only re-applies schema.sql when
     * the manifest version is greater than the installed one, so a schema
     * change without a bump is silently a no-op on every already-enabled
     * install (AGENTS.md). Editing schema.sql should break this test — the
     * fix is to bump module.json, which is the whole point.
     *
     * The pin has since been widened in practice to the manifest's whole
     * declared surface (routes, settings, tasks, notification types), not
     * only schema.sql: those are re-registered on every load and so do not
     * strictly need a bump, but a module whose surface changed without its
     * version moving is one nobody can tell apart from the previous
     * release.
     */
    public function testTheVersionIsBumpedWheneverTheSchemaChanges(): void
    {
        $this->assertSame('1.17.1', $this->manifest->version);
    }

    /**
     * Every route that CHANGES something is POST — never GET, which a
     * crawler, a prefetch or an <img src> could trigger without the
     * visitor ever meaning to, and which carries no CSRF token.
     *
     * Read-only endpoints under the same prefixes are legitimately GET
     * (the feed's own "Charger plus", and a post's replies listing), so
     * this asserts the write paths specifically rather than "anything
     * containing /posts", which would have to be relaxed every time a
     * read endpoint is added — exactly the kind of assertion that gets
     * weakened rather than fixed.
     */
    public function testEveryStateChangingRouteIsDeclaredAsPostOnly(): void
    {
        $writePaths = [
            '/groups',
            '/groups/{id}/invite-member',
            '/groups/{id}/invite-section',
            '/groups/{id}/moderator',
            '/groups/{id}/remove-member',
            '/groups/{id}/posts',
            '/groups/{id}/posts/{postId}/edit',
            '/groups/{id}/posts/{postId}/delete',
            '/groups/{id}/posts/{postId}/pin',
            '/groups/{id}/posts/{postId}/unpin',
            '/groups/{id}/posts/{postId}/replies',
            '/groups/{id}/posts/{postId}/react',
            '/groups/{id}/replies/{replyId}/edit',
            '/groups/{id}/replies/{replyId}/delete',
            '/groups/{id}/replies/{replyId}/react',
        ];

        $declared = [];
        foreach ($this->manifest->routes as $route) {
            $declared[$route['method'] . ' ' . $route['path']] = true;
        }

        foreach ($writePaths as $path) {
            $this->assertArrayHasKey('POST ' . $path, $declared, $path . ' must be declared as a POST route');
        }
    }

    /**
     * The two read-only companions of those write routes, which ARE GET:
     * pinned here so neither can quietly become a POST-only route and
     * break the "Charger plus" buttons that fetch them.
     */
    public function testTheTwoPaginationEndpointsAreGet(): void
    {
        $declared = array_map(fn(array $r) => $r['method'] . ' ' . $r['path'], $this->manifest->routes);

        $this->assertContains('GET /groups/{id}/feed', $declared);
        $this->assertContains('GET /groups/{id}/posts/{postId}/replies', $declared);
    }

    public function testItHardDependsOnGallery(): void
    {
        // Group media live in a gallery album — without it the module has
        // nowhere to put them, so this is a hard dependency (§7.1's
        // `requires`), not the optional §7.5 kind.
        $this->assertSame(['gallery'], $this->manifest->requires);
    }

    public function testEveryRouteIsIdentifiedOrStricterAndInEspaceAnimes(): void
    {
        foreach ($this->manifest->routes as $route) {
            $this->assertSame('espace_animes', $route['menu'], $route['path']);
            $this->assertContains($route['role_min'], ['identified', 'chief'], $route['path']);
        }
    }

    public function testOnlyTheListPageAppearsInTheMenu(): void
    {
        $labelled = array_values(array_filter($this->manifest->routes, fn(array $r) => $r['label'] !== ''));

        $this->assertCount(1, $labelled);
        $this->assertSame('/groups', $labelled[0]['path']);
        $this->assertSame('Groupes', $labelled[0]['label']);
    }

    public function testTheLiteralArchivesRouteIsDeclaredBeforeTheIdWildcard(): void
    {
        // ModuleManifest::validateNoShadowedRoutes() would reject the
        // reverse order outright; this pins the intent so a reorder can't
        // quietly turn /groups/archives into id="archives".
        $paths = array_map(fn(array $r) => $r['method'] . ' ' . $r['path'], $this->manifest->routes);

        $this->assertLessThan(
            array_search('GET /groups/{id}', $paths, true),
            array_search('GET /groups/archives', $paths, true)
        );
    }

    /**
     * Regression guard: every PostController route once declared its
     * controller as "Modules\\\\Groups\\\\Controller\\\\PostController"
     * in the JSON source — valid JSON, but the doubled escaping decodes
     * to a class name containing literal double backslashes, which never
     * matches Core\Http\FrontController's registered-instance lookup
     * (keyed by the real, single-backslash ::class constant) nor
     * class_exists(). ModuleManifest never validates this itself (a
     * malformed but syntactically valid string parses fine), so the
     * break only ever surfaced as a fatal "Class ... not found" the
     * moment a visitor actually hit one of those routes in production —
     * this asserts every declared controller is the genuine, loadable
     * class instead.
     */
    public function testEveryRoutesControllerClassActuallyExists(): void
    {
        foreach ($this->manifest->routes as $route) {
            $this->assertTrue(
                class_exists($route['controller']),
                "{$route['method']} {$route['path']} declares a controller that does not exist: {$route['controller']}"
            );
            $this->assertTrue(
                is_subclass_of($route['controller'], \Core\Http\Controller\AbstractController::class),
                "{$route['controller']} must extend AbstractController"
            );
        }
    }

    /**
     * The group LIST is offline-cacheable; a group's CONVERSATION is
     * deliberately not, and this test is the guard on that line.
     *
     * The list carries group names and activity times — enough to open the
     * installed app on a train and see which groups exist and which have
     * been busy. A group page carries the messages themselves, written in
     * a space where minors write, plus their photos; a full copy of that
     * on the device is a different privacy proposition entirely, and
     * SECURITY.md §10 / docs/module-development.md ("if in doubt, don't
     * declare it") both point the same way. Adding a `/groups/` child
     * entry here should break this test — that is the point.
     */
    public function testItCachesTheGroupListOfflineButNeverAGroupsConversation(): void
    {
        $this->assertSame(
            [['path' => '/groups', 'label' => 'Groupes', 'match' => 'exact', 'role_min' => 'identified']],
            $this->manifest->offline
        );

        $paths = array_column($this->manifest->offline, 'path');
        $this->assertNotContains('/groups/', $paths, 'a group conversation must never be cached on the device');

        // Unchanged: the module sets no cookie of its own, and stores no
        // file outside gallery's delegated album.
        $this->assertSame([], $this->manifest->cookies);
        $this->assertSame([], $this->manifest->storage);
    }

    /**
     * Both settings are the module's own, and both have to keep working
     * with the AI module absent — which is exactly what the AI one's
     * description says out loud, since a switch that silently does
     * nothing is worse than no switch.
     */
    public function testItDeclaresTheTwoModerationSettings(): void
    {
        $keys = array_column($this->manifest->settings, 'key');
        $this->assertSame(
            [
                'groups_report_hide_threshold',
                'groups_ai_moderation_enabled',
                'groups_reaction_notification_window_minutes',
                'groups_inactivity_close_months',
                'groups_post_retention_months',
                'groups_closed_purge_months',
                'groups_max_created_per_member',
                'groups_draft_ttl_minutes',
            ],
            $keys
        );

        $byKey = array_column($this->manifest->settings, null, 'key');
        $this->assertSame('2', $byKey['groups_report_hide_threshold']['default_value']);
        $this->assertSame('number', $byKey['groups_report_hide_threshold']['type']);

        // Default ON, and harmless when llm_connector is disabled:
        // Service\ModerationService then simply reports itself
        // unavailable and every message is published unchecked.
        $this->assertSame('1', $byKey['groups_ai_moderation_enabled']['default_value']);
        $this->assertSame('boolean', $byKey['groups_ai_moderation_enabled']['type']);
        $this->assertStringContainsString(
            'Connecteur IA',
            $byKey['groups_ai_moderation_enabled']['description']
        );
    }

    /**
     * Every type this module can send, exactly as the preferences page
     * will list them (grouped by `group`, labelled in French).
     */
    public function testItDeclaresItsNotificationTypes(): void
    {
        $byId = array_column($this->manifest->notifications, null, 'id');

        $this->assertSame(
            [
                'groups.post_published',
                'groups.reply_received',
                'groups.reaction_received',
                'groups.mentioned',
                'groups.invited',
                'groups.item_reported',
            ],
            array_keys($byId)
        );

        foreach ($byId as $id => $type) {
            $this->assertSame('Groupes', $type['group'], "{$id} must sit in the Groupes preferences group");
            $this->assertSame('identified', $type['role_min'], "{$id} audience is membership, so its floor is identified");
            $this->assertNotSame('', trim($type['label']));
            $this->assertNotSame('', trim($type['description']));
        }
    }

    /**
     * Email is off — and LOCKED off — on every one of them: none of these
     * is worth an email, and a member who switched it on would get one
     * per reaction (module spec, "do not send email for any of these").
     */
    public function testNoNotificationTypeCanEverSendEmail(): void
    {
        foreach ($this->manifest->notifications as $type) {
            $this->assertSame('default_off', $type['channels']['email'], "{$type['id']} must not default to email");
        }
    }

    public function testTheChannelDefaultsMatchHowNoisyEachTypeIs(): void
    {
        $byId = array_column($this->manifest->notifications, null, 'id');

        // A new post and a reply are worth a buzz.
        $this->assertSame('default_on', $byId['groups.post_published']['channels']['in_app']);
        $this->assertSame('default_on', $byId['groups.post_published']['channels']['push']);
        $this->assertSame('default_on', $byId['groups.reply_received']['channels']['push']);

        // A reaction is not: it is in the centre, but it does not
        // interrupt anyone by default.
        $this->assertSame('default_on', $byId['groups.reaction_received']['channels']['in_app']);
        $this->assertSame('default_off', $byId['groups.reaction_received']['channels']['push']);

        // Being named is: a mention is the type somebody keeps switched
        // on precisely so they can switch the every-message one off.
        $this->assertSame('default_on', $byId['groups.mentioned']['channels']['push']);
    }

    /**
     * The one locked channel in this module: a moderator must not be able
     * to switch off the only signal that something in their group needs
     * their attention, so the in-app channel is 'on' rather than
     * 'default_on' — NotificationService::channelEnabled() ignores any
     * preference row for a locked channel.
     */
    public function testAModeratorCannotSwitchOffTheReportNotificationInApp(): void
    {
        $byId = array_column($this->manifest->notifications, null, 'id');

        $this->assertSame('on', $byId['groups.item_reported']['channels']['in_app']);
        $this->assertSame('default_on', $byId['groups.item_reported']['channels']['push']);
    }

    /**
     * Every retention duration is a setting with a real French
     * description — and none of them is per group: a per-group override
     * would let a moderator quietly opt their own group out of the
     * retention bound.
     */
    public function testTheThreeLifecycleDurationsAreSettingsWithDescriptions(): void
    {
        $byKey = array_column($this->manifest->settings, null, 'key');

        foreach ([
            'groups_inactivity_close_months' => '12',
            'groups_post_retention_months' => '24',
            'groups_closed_purge_months' => '12',
        ] as $key => $default) {
            $this->assertArrayHasKey($key, $byKey);
            $this->assertSame('number', $byKey[$key]['type'], "{$key} is a duration in months");
            $this->assertSame($default, $byKey[$key]['default_value']);
            $this->assertGreaterThan(60, mb_strlen($byKey[$key]['description']), "{$key} needs a real description");
        }
    }

    /**
     * The four lifecycle tasks, each declared so Core\Scheduler can find
     * its handler. Every one of them reschedules itself — there is no
     * first-class recurring task to declare instead.
     */
    public function testItDeclaresTheFourLifecycleTasks(): void
    {
        $byKey = array_column($this->manifest->scheduledTasks, null, 'key');

        foreach ([
            'ensure_section_groups' => \Modules\Groups\Task\EnsureSectionGroupsHandler::class,
            'close_inactive_groups' => \Modules\Groups\Task\CloseInactiveGroupsHandler::class,
            'purge_posts' => \Modules\Groups\Task\PurgePostsHandler::class,
            'purge_closed_groups' => \Modules\Groups\Task\PurgeClosedGroupsHandler::class,
        ] as $key => $handler) {
            $this->assertArrayHasKey($key, $byKey);
            $this->assertSame($handler, $byKey[$key]['handler']);
            $this->assertTrue(class_exists($handler), "{$handler} must exist");
        }
    }

    /**
     * The purge that keeps discussion_group_rate_limits from growing
     * without bound — declared here, self-rescheduling once it runs
     * (Task\PurgeRateLimitHandler).
     */
    public function testItDeclaresTheRateLimitPurgeTask(): void
    {
        $byKey = array_column($this->manifest->scheduledTasks, null, 'key');
        $this->assertArrayHasKey('purge_rate_limits', $byKey);
        $this->assertSame(
            \Modules\Groups\Task\PurgeRateLimitHandler::class,
            $byKey['purge_rate_limits']['handler']
        );
    }
}
