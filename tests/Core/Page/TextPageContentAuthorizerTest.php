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
    public function testAKeyInAnotherCaseIsStillGuarded(): void
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
    public function testAnAccentedSpellingIsStillGuarded(): void
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
     * **A fullwidth digit, which is the spelling that broke the second
     * attempt.**
     *
     * `utf8mb4_unicode_ci` equates `７` (U+FF17) with `7`, so the write
     * lands on the real row. A rule built on folding missed it for a
     * reason worth remembering: `Normalizer::FORM_D` is canonical
     * decomposition, not compatibility decomposition, so the fullwidth
     * digit survives unchanged and is then **deleted** rather than
     * folded — the key stopped looking like a page's altogether and the
     * authorizer abstained.
     *
     * The rule is inverted now: anything that reduces into the page
     * namespace is refused, whatever id it appears to name.
     */
    public function testAFullwidthDigitDoesNotEscapeTheNamespace(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');
        self::assertSame(1, $page->id, 'this test spells the id out by hand');

        $this->assertSame('superadmin', $this->authorizer->roleMinForKey("page_content_\u{FF17}"));
        $this->assertSame('superadmin', $this->authorizer->roleMinForKey("page_content_\u{FF11}"));
    }

    /**
     * A primary-ignorable character dropped between the digits of an id
     * — a soft hyphen here — which the database ignores outright.
     */
    public function testAnIgnorableCharacterInsideTheIdDoesNotEscapeEither(): void
    {
        $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $this->assertSame('superadmin', $this->authorizer->roleMinForKey("page_content_1\u{00AD}2"));
        $this->assertSame('superadmin', $this->authorizer->roleMinForKey("page\u{00AD}_content_1"));
    }

    /**
     * A malformed key that still opens the namespace is refused, not
     * ignored — `page_content_` with no id, or with something that is
     * not one.
     *
     * Under the inverted rule these need no special case: they claim the
     * namespace, so they are refused like any other non-canonical
     * spelling.
     */
    public function testAMalformedKeyInTheNamespaceIsRefused(): void
    {
        foreach (['page_content_', 'page_content_x', 'page_content_1a'] as $key) {
            $this->assertSame('superadmin', $this->authorizer->roleMinForKey($key), $key);
        }
    }

    /**
     * Only the canonical spelling resolves a page; every other spelling
     * is refused outright rather than resolved to an id.
     *
     * That is the whole inversion. Two attempts at modelling the
     * collation were both too narrow, and each miss reopened the
     * escalation. Nothing in this site ever writes a non-canonical
     * spelling, so refusing them all costs nothing legitimate.
     */
    public function testOnlyTheCanonicalSpellingEverResolvesAPage(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        // « Notre unité » is read by everyone, so the canonical key
        // resolves to `public` — the page's own floor.
        $this->assertSame('public', $this->authorizer->roleMinForKey($page->contentKey()));

        // The same page, spelled otherwise: refused, not resolved.
        foreach (['Page_Content_', 'pagé_content_', 'PAGE_CONTENT_'] as $spelling) {
            $this->assertSame(
                'superadmin',
                $this->authorizer->roleMinForKey($spelling . $page->id),
                $spelling
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
    public function testATrailingSpaceIsStillGuarded(): void
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
            // A key that merely CONTAINS the words elsewhere is not
            // claiming the namespace: the reduction has to OPEN with it.
            'xpage_content_1',
            'my_page_contents',
        ];

        foreach ($untouched as $key) {
            $this->assertNull($this->authorizer->roleMinForKey($key), $key);
        }
    }
}
