<?php

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Page\TextPageContentAuthorizer;
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
 * the write endpoint's (SECURITY.md §3, ARCHITECTURE.md §8.115).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TextPageContentAuthorizerTest extends TestCase
{
    private TextPageService $service;
    private TextPageContentAuthorizer $authorizer;
    private EditableContentRepository $content;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
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
