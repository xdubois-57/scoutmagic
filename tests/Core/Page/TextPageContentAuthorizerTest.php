<?php

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Page\TextPageContentAuthorizer;
use Core\Page\TextPageRepository;
use Core\Page\TextPageService;
use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * A free-text page's body is written at the page's own role, never at
 * the write endpoint's (SECURITY.md §3, ARCHITECTURE.md §8.115).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TextPageContentAuthorizerTest extends TestCase
{
    private TextPageService $service;
    private TextPageContentAuthorizer $authorizer;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $repository = new TextPageRepository($pdo);
        $this->service = new TextPageService($repository);
        $this->authorizer = new TextPageContentAuthorizer($repository);
    }

    /**
     * **The escalation this class exists to close.**
     *
     * `POST /api/editable-content` is `role_min: admin`. A page filed in
     * the Configuration menu is READ at `superadmin`. Before this, an
     * admin refused `GET /pages/{slug}` could still name the key
     * `page_content_{id}` — small, sequential, guessable — and rewrite
     * what a superadmin reads. It is the first content on this site
     * whose read floor exceeds its write floor.
     */
    public function testAnAdminCannotWriteThePageOnlyASuperadminMayRead(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $required = $this->authorizer->roleMinForKey($page->contentKey());

        $this->assertSame('superadmin', $required);
        $this->assertFalse(
            Role::ADMIN->hasAccess(Role::fromString((string) $required)),
            'an admin must not be able to write a page they may not read'
        );
        $this->assertTrue(Role::SUPERADMIN->hasAccess(Role::fromString((string) $required)));
    }

    /**
     * The write role and the read role are the same value, for every
     * section — so the two halves cannot drift apart.
     */
    public function testTheWriteRoleIsAlwaysThePagesOwnReadRole(): void
    {
        foreach (MenuBuilder::menuIds() as $menuId) {
            $page = $this->service->create(
                'Page',
                'Page ' . $menuId,
                $menuId,
                $this->service->defaultGroupFor($menuId)
            );

            $this->assertSame(
                $page->roleMin(),
                $this->authorizer->roleMinForKey($page->contentKey()),
                "menu {$menuId}"
            );
        }
    }

    /**
     * Switching a page off must not widen who may write its body. The
     * text is still that page's text.
     */
    public function testHidingAPageDoesNotHandItsBodyToAWiderAudience(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');
        $this->service->setActive($page->id, false);

        $this->assertSame('superadmin', $this->authorizer->roleMinForKey($page->contentKey()));
    }

    /**
     * A key of the right shape naming no page is refused, not abstained
     * on: answering null would hand it back to the endpoint's own floor
     * and let an admin create a content row nothing can ever name again.
     */
    public function testAKeyNamingNoPageIsRefusedRatherThanIgnored(): void
    {
        $this->assertSame('superadmin', $this->authorizer->roleMinForKey('page_content_4242'));
    }

    /**
     * **The escalation reopened by changing the case of one letter.**
     *
     * `editable_contents` is `utf8mb4_unicode_ci` and
     * `EditableContentRepository` compares with a plain
     * `WHERE content_key = ?`, so the database considers `Page_Content_7`
     * and `page_content_7` the same row. A case-sensitive authorizer
     * would abstain on the first spelling, hand it back to the
     * endpoint's own `admin` floor, and let the write land on the real
     * row anyway.
     */
    public function testAKeyInAnotherCaseIsStillThePagesKey(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');
        $id = $page->id;

        foreach (["Page_Content_{$id}", "PAGE_CONTENT_{$id}", "pAgE_cOnTeNt_{$id}"] as $spelling) {
            $this->assertSame('superadmin', $this->authorizer->roleMinForKey($spelling), $spelling);
        }
    }

    /**
     * The same, with accents — `utf8mb4_unicode_ci` is accent-insensitive
     * too, so `/i` alone would not have been enough.
     */
    public function testAnAccentedSpellingIsStillThePagesKey(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        foreach (['pagé_content_', 'page_cöntent_', 'pàgè_cóntént_'] as $prefix) {
            $this->assertSame(
                'superadmin',
                $this->authorizer->roleMinForKey($prefix . $page->id),
                $prefix
            );
        }
    }

    /**
     * Trailing whitespace is guarded too, and for a reason beyond case
     * and accents: `utf8mb4_unicode_ci` is a PAD SPACE collation, so
     * MySQL's `=` ignores trailing spaces entirely —
     * `'page_content_1 '` and `'page_content_1'` are the same row to the
     * database.
     */
    public function testATrailingSpaceIsStillThePagesKey(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $this->assertSame('superadmin', $this->authorizer->roleMinForKey($page->contentKey() . '  '));
    }

    /**
     * Every other key on the site is none of this authorizer's business,
     * so it can only ever narrow and never widen.
     */
    public function testEveryOtherKeyIsLeftToTheEndpointsOwnFloor(): void
    {
        $untouched = [
            'home.intro',
            'contact.text',
            'section.BALA.text',
            'banner_content_3',
            'registration_intro',
            // Near misses: the shape has to match exactly.
            'page_content_',
            'page_content_x',
            'page_content_1a',
            'xpage_content_1',
        ];

        foreach ($untouched as $key) {
            $this->assertNull($this->authorizer->roleMinForKey($key), $key);
        }
    }
}
