<?php

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Page\TextPageContentAuthorizer;
use Core\Page\TextPageException;
use Core\Page\TextPageRepository;
use Core\Page\TextPageService;
use Core\Security\Role;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * A free-text page's body is written at the page's own role, never at
 * the write endpoint's (SECURITY.md §3, ARCHITECTURE.md §8.116).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TextPageContentAuthorizerTest extends TestCase
{
    private TextPageService $service;
    private TextPageContentAuthorizer $authorizer;
    private EditableContentRepository $content;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $pdo = $this->pdo = DatabaseTestHelper::createTestDatabase();
        $repository = new TextPageRepository($pdo);
        $this->content = new EditableContentRepository($pdo);
        $this->service = new TextPageService($repository, $this->content);
        $this->authorizer = new TextPageContentAuthorizer($repository);
    }

    /**
     * **The escalation this class exists to close.**
     *
     * `POST /api/editable-content` is `role_min: admin`. A page filed in
     * the Configuration menu is READ at `superadmin`. Before this, an
     * admin refused `GET /pages/{slug}` could still write that page's
     * body — the first content on this site whose read floor exceeds its
     * write floor.
     */
    public function testAnAdminCannotWriteThePageOnlyASuperadminMayRead(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $required = $this->authorizer->roleMinForOwner(TextPageContentAuthorizer::OWNER_KIND, $page->id);

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
                $this->authorizer->roleMinForOwner(TextPageContentAuthorizer::OWNER_KIND, $page->id),
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

        $this->assertSame(
            'superadmin',
            $this->authorizer->roleMinForOwner(TextPageContentAuthorizer::OWNER_KIND, $page->id)
        );
    }

    /**
     * An owner this authorizer does not speak for is left to whoever
     * does — so adding one can only ever narrow, never widen.
     */
    public function testAnotherKindOfOwnerIsNoneOfItsBusiness(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $this->assertNull($this->authorizer->roleMinForOwner('banner', $page->id));
        $this->assertNull($this->authorizer->roleMinForOwner('', $page->id));
    }

    /**
     * A row claiming a page that is gone is refused, not abstained on.
     * The foreign key should make it impossible; if it ever happens, the
     * narrowest answer is the only safe one.
     */
    public function testARowClaimingAPageThatIsGoneIsRefused(): void
    {
        $this->assertSame(
            'superadmin',
            $this->authorizer->roleMinForOwner(TextPageContentAuthorizer::OWNER_KIND, 4242)
        );
    }

    /**
     * **The row is created with the page, empty and owned.**
     *
     * Not an optimisation: it closes the window in which the content key
     * exists but belongs to nobody. Authorization is read off the row's
     * owner, so a key with no row yet would have no owner to answer for
     * it — and whoever wrote first would decide what a page they may not
     * even read says.
     */
    public function testAPagesContentRowExistsAndIsOwnedFromTheMomentItIsCreated(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $this->assertSame($page->id, $this->content->ownerPageIdForKey($page->contentKey()));

        $row = $this->content->findByKey($page->contentKey());
        $this->assertNotNull($row);
        $this->assertNull(
            $row['content_value'],
            'The claimed row must hold NULL, not an empty string: an empty string is a value, '
            . 'and EditableContentService::get() would serve it instead of the page template\'s default.'
        );
    }

    /**
     * **A key planted before the page existed is taken over, not tripped
     * over.**
     *
     * `POST /api/editable-content` is `role_min: admin` and accepts any
     * key the client sends, and `text_pages.id` is a predictable
     * auto-increment — so an admin can write `page_content_{next id}`
     * while no page owns it, which the guard allows precisely because
     * nothing owns it. `content_key` is UNIQUE, so a blind INSERT at
     * creation time would fail *after* the page's own row is committed:
     * a live, routed page whose body stays unowned, and therefore stays
     * writable by that admin however narrow the page's section is.
     */
    public function testAKeyPlantedBeforeThePageExistsIsClaimedByIt(): void
    {
        // The next page will be id 1, so this is its key, written before it.
        $this->content->upsert('page_content_1', 'rich_text', '<p>Planté.</p>', null, 1);

        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');
        $this->assertSame('page_content_1', $page->contentKey(), 'the premise of this test');

        $this->assertSame(
            $page->id,
            $this->content->ownerPageIdForKey($page->contentKey()),
            'the planted row must end up owned by the page, not left unowned beside it'
        );
    }

    /**
     * And the planted text does not become the page's text. A row written
     * under a page's key before that page existed cannot be its content by
     * any legitimate route, so claiming it blanks it rather than
     * publishing a stranger's HTML under a title the unit chose.
     */
    public function testThePlantedTextDoesNotBecomeThePagesText(): void
    {
        $this->content->upsert('page_content_1', 'rich_text', '<p>Planté.</p>', null, 1);

        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $row = $this->content->findByKey($page->contentKey());
        $this->assertNotNull($row);
        $this->assertNull($row['content_value']);
    }

    /**
     * The page itself is refused rather than shipped unowned when the
     * claim cannot be made at all. A page with a route, a menu entry and
     * a body governed by the write endpoint's floor instead of its own
     * section's is exactly what the owner column exists to prevent.
     */
    public function testAPageWhoseBodyCannotBeClaimedIsNotCreatedAtAll(): void
    {
        // The claim runs against a second database, whose `text_pages` is
        // empty — so its foreign key refuses the row the page needs. SQLite
        // does not enforce foreign keys unless asked, and a test that
        // forgets to ask proves the opposite of what it thinks.
        $elsewhere = DatabaseTestHelper::createTestDatabase();
        $elsewhere->exec('PRAGMA foreign_keys = ON');

        $service = new TextPageService(
            new TextPageRepository($this->pdo),
            new EditableContentRepository($elsewhere),
        );

        try {
            $service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');
            $this->fail('A page whose body could not be claimed must not be created.');
        } catch (TextPageException $e) {
            $this->assertStringContainsString('pas pu être créée', $e->getMessage());
        }

        $this->assertSame([], (new TextPageRepository($this->pdo))->findAll());
    }

    /**
     * **A page created through the production wiring still shows its
     * placeholder.** The row now always exists, so « no row » stopped being
     * what makes the default text render — what makes it render is that
     * the row holds NULL. Claiming the key with an empty string would
     * hand every new page a blank screen, and no test that builds the
     * service without its content repository would ever notice, because
     * that wiring never claims the key at all.
     */
    public function testAFreshPageStillRendersItsDefaultText(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $content = new EditableContentService($this->content);

        $this->assertSame(
            '<p>Cette page n\'a pas encore de contenu.</p>',
            $content->get($page->contentKey(), '<p>Cette page n\'a pas encore de contenu.</p>')
        );
    }

    /**
     * Every key that existed before free-text pages owns nothing, so
     * nothing narrows it and the endpoint's own floor stands.
     */
    public function testAnOrdinaryKeyHasNoOwner(): void
    {
        $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        foreach (['home.intro', 'contact.text', 'section.BALA.text', 'banner_content_3'] as $key) {
            $this->assertNull($this->content->ownerPageIdForKey($key), $key);
        }
    }
}
