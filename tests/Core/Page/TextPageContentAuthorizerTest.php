<?php

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Page\TextPageContentAuthorizer;
use Core\Page\TextPageRepository;
use Core\Page\TextPageService;
use Core\Security\Role;
use Core\View\EditableContentRepository;
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
        $this->assertSame('', $row['content_value']);
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
