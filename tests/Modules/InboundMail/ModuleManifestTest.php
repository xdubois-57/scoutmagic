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
     */
    public function testTheVersionIsBumpedWheneverTheSchemaChanges(): void
    {
        $this->assertSame('1.4.0', $this->manifest->version);
    }

    /**
     * **The whole configuration surface is superadmin** (§7.4).
     *
     * A Staff d'U or a manager may *use* a configured mailbox through a
     * consuming module's workflow, but must never see the host, the
     * account or anything else that would let them reach it directly.
     * `role_min: admin` on any of these would be exactly that leak, which
     * is why every route is pinned rather than only the index.
     */
    public function testEveryRouteIsSuperadminOnly(): void
    {
        $this->assertNotSame([], $this->manifest->routes);

        foreach ($this->manifest->routes as $route) {
            $this->assertSame(
                'superadmin',
                $route['role_min'] ?? null,
                'Route ' . ($route['path'] ?? '?') . ' must be superadmin-only.'
            );
        }
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
