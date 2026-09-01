<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest is validated at load time by Core\Module\ModuleManager, so a
 * mistake here takes the module out of every menu with only a badge on the
 * Modules page to show for it. Same precedent as
 * Tests\Modules\Rental\ModuleManifestTest.
 */
class ModuleManifestTest extends TestCase
{
    private ModuleManifest $manifest;

    protected function setUp(): void
    {
        $this->manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/inbound_mail/module.json');
    }

    public function testTheManifestParsesAndValidates(): void
    {
        $this->assertSame('inbound_mail', $this->manifest->id);
        $this->assertSame('Courrier entrant', $this->manifest->name);
        $this->assertFalse(
            $this->manifest->enabledByDefault,
            'Connecting to somebody\'s mailbox is never something to switch on for them.'
        );
    }

    /**
     * Pinned deliberately: ModuleManager only re-applies schema.sql when the
     * manifest version is greater than the installed one, so a schema change
     * without a bump is silently a no-op on every already-enabled install
     * (AGENTS.md). Editing schema.sql should break this test — the fix is to
     * bump module.json, which is the whole point.
     *
     * 1.5.1 to 1.7.0 are bumps with no schema change behind them: RFC 2047
     * subjects arriving undecoded, an image-only body rendering as a blank
     * card, the relève interval becoming a setting, a candidate carrying
     * its mailbox's purpose, and a consumer now naming its own business
     * references are all things a unit sees, and AGENTS.md asks for a bump
     * whenever the module changes in a way its users should see.
     */
    public function testTheVersionIsBumpedWheneverTheSchemaChanges(): void
    {
        $this->assertSame('1.7.0', $this->manifest->version);
    }

    /**
     * **The whole CONFIGURATION surface is superadmin** (§7.4).
     *
     * A Staff d'U or a manager may *use* a configured mailbox through a
     * consuming module's workflow, but must never see the host, the
     * account or anything else that would let them reach it directly.
     * `role_min: admin` on any `/config/` route would be exactly that
     * leak, which is why every one of them is pinned rather than only the
     * index.
     */
    public function testEveryConfigurationRouteIsSuperadminOnly(): void
    {
        $this->assertNotSame([], $this->manifest->routes);

        foreach ($this->manifest->routes as $route) {
            if (!str_starts_with((string) ($route['path'] ?? ''), '/config/')) {
                continue;
            }

            $this->assertSame(
                'superadmin',
                $route['role_min'] ?? null,
                'Route ' . ($route['path'] ?? '?') . ' must be superadmin-only.'
            );
        }
    }

    /**
     * **`/courrier` is admin, and nothing lower** (§8.58).
     *
     * The general mailbox shows every message the unit ever received,
     * associated or not. It is one of the three things that make storing
     * everything defensible, and the third of those three is that exactly
     * ONE role answers for the archive — which is this `role_min` and
     * nothing else. A route here at `intendant` or `chief` would hand a
     * section leader the parents' questions, the medical documents and the
     * applications, and would do it without anybody noticing.
     */
    public function testTheGeneralMailboxIsAdminAndNothingLower(): void
    {
        $mailboxRoutes = array_filter(
            $this->manifest->routes,
            static fn(array $route) => str_starts_with((string) ($route['path'] ?? ''), '/courrier')
        );

        $this->assertNotSame([], $mailboxRoutes, 'the general mailbox must exist');

        foreach ($mailboxRoutes as $route) {
            $this->assertSame(
                'admin',
                $route['role_min'] ?? null,
                'Route ' . ($route['path'] ?? '?') . ' must be admin-only.'
            );
        }
    }

    /**
     * And it declares nothing offline.
     *
     * « Lisible hors ligne » is opt-in per module, so a page like this one
     * is excluded by saying nothing — which is easy to undo by accident on
     * the day somebody adds an `offline` section for another page. A page
     * listing every message the unit ever received has no business sitting
     * in the cache of a phone that may be lost.
     */
    public function testTheGeneralMailboxIsNeverCachedForOfflineReading(): void
    {
        $paths = array_map(
            static fn(array $entry) => (string) ($entry['path'] ?? ''),
            $this->manifest->offline
        );

        // Asserted as a list rather than in a loop: an empty `offline`
        // section is the passing case today, and a loop over nothing is a
        // test that asserts nothing.
        $this->assertSame(
            [],
            array_values(array_filter($paths, static fn(string $path) => str_starts_with($path, '/courrier'))),
            'the unit\'s whole mail must never be held offline'
        );
    }

    /**
     * Every route that CHANGES something is POST — never GET, which a
     * crawler, a prefetch or an <img src> could trigger without the visitor
     * ever meaning to, and which carries no CSRF token.
     */
    public function testEveryStateChangingRouteIsPostOnly(): void
    {
        $writePaths = [
            '/config/courrier-entrant/boites',
            '/config/courrier-entrant/boites/{id}/test',
            '/config/courrier-entrant/boites/{id}/activation',
            '/config/courrier-entrant/boites/{id}/suppression',
        ];

        $methodsByPath = [];
        foreach ($this->manifest->routes as $route) {
            $methodsByPath[$route['path']] = $route['method'];
        }

        foreach ($writePaths as $path) {
            $this->assertArrayHasKey($path, $methodsByPath);
            $this->assertSame('POST', $methodsByPath[$path], $path . ' changes state and must be POST.');
        }
    }

    public function testTheSyncTaskIsDeclared(): void
    {
        $keys = array_column($this->manifest->scheduledTasks, 'key');

        $this->assertContains('sync_mailboxes', $keys);
    }

    /**
     * Attachments are stored behind FileAccessGuard, never under public/,
     * and never at a role a renter or an anonymous visitor could reach
     * (§7.9).
     */
    public function testAttachmentStorageIsDeclaredAndNeverPublic(): void
    {
        $this->assertArrayHasKey('inbound_mail/attachments', $this->manifest->storage);
        $this->assertSame('intendant', $this->manifest->storage['inbound_mail/attachments']['role_min']);
    }

    /**
     * No offline whitelist at all. These are configuration and write pages;
     * the offline layer caches reads, and a cached mailbox form would be
     * both useless and a place for a credential to sit in a browser cache.
     */
    public function testNothingIsWhitelistedForOfflineUse(): void
    {
        $this->assertSame([], $this->manifest->offline);
    }
}
