<?php

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Page\TextPage;
use Core\Page\TextPageException;
use Core\Page\TextPageRepository;
use Core\Page\TextPageService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The rules of a free-text page (issue #368).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TextPageServiceTest extends TestCase
{
    private \PDO $pdo;
    private TextPageService $service;
    private TextPageRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new TextPageRepository($this->pdo);
        $this->service = new TextPageService(
            $this->repository,
            new EditableContentRepository($this->pdo)
        );
    }

    public function testATitleBecomesAnAddressWithoutAccentsOrPunctuation(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL — Règles & statuts', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->assertSame('notre-asbl-regles-statuts', $page->slug);
        $this->assertSame('/pages/notre-asbl-regles-statuts', $page->path());
    }

    /**
     * The whole reason the slug exists as a stored column rather than as
     * something derived on the fly.
     *
     * An address is shared the moment a page is published — in an e-mail
     * to the families, on a federation site, in somebody's bookmarks.
     * Correcting a typo in the title must not break it.
     */
    public function testRenamingAPageLeavesItsAddressAlone(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASLB', MenuBuilder::MENU_NOTRE_UNITE, null);
        $this->assertSame('notre-aslb', $page->slug);

        $renamed = $this->service->update(
            $page->id,
            'ASBL',
            'Notre ASBL',
            MenuBuilder::MENU_NOTRE_UNITE,
            null
        );

        $this->assertSame('Notre ASBL', $renamed->title);
        $this->assertSame('notre-aslb', $renamed->slug, 'the address must survive a corrected title');
    }

    public function testASecondPageWithTheSameTitleGetsItsOwnAddress(): void
    {
        $first = $this->service->create('Safe', 'Bulle Safe', MenuBuilder::MENU_NOTRE_UNITE, null);
        $second = $this->service->create('Safe', 'Bulle Safe', MenuBuilder::MENU_NOTRE_UNITE, null);
        $third = $this->service->create('Safe', 'Bulle Safe', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->assertSame('bulle-safe', $first->slug);
        $this->assertSame('bulle-safe-2', $second->slug);
        $this->assertSame('bulle-safe-3', $third->slug);
    }

    /**
     * A title carrying nothing the address can keep still has to produce
     * an address — `/pages/` on its own is not one.
     */
    public function testATitleThatSlugifiesToNothingStillGetsAnAddress(): void
    {
        $page = $this->service->create('Nom', '?!…', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->assertSame('page', $page->slug);
        $this->assertSame('/pages/page', $page->path());
    }

    /**
     * The decision the chantier locks: choosing the section IS choosing
     * who reads the page. Asserted against `MenuBuilder` itself rather
     * than against five literals, so this test fails if the menus' own
     * floors ever move and the two stop agreeing.
     */
    public function testTheRoleFloorIsTheFloorOfTheMenuThePageWasFiledIn(): void
    {
        $expected = [
            MenuBuilder::MENU_NOTRE_UNITE => 'public',
            MenuBuilder::MENU_ESPACE_ANIMES => 'identified',
            MenuBuilder::MENU_ESPACE_CHEFS => 'intendant',
            MenuBuilder::MENU_ESPACE_ADMIN => 'admin',
            MenuBuilder::MENU_CONFIGURATION => 'superadmin',
        ];

        foreach ($expected as $menuId => $roleMin) {
            $page = $this->service->create(
                'Page',
                'Page ' . $menuId,
                $menuId,
                $this->service->defaultGroupFor($menuId)
            );

            $this->assertSame($roleMin, $page->roleMin(), "menu {$menuId}");
            $this->assertSame(MenuBuilder::roleMinFor($menuId), $page->roleMin());
        }
    }

    /**
     * Trap three of the chantier, and the one with the widest blast
     * radius: `MenuBuilder::addPage()` throws when a column is not
     * declared for its menu, and that call happens while building the
     * navigation of EVERY page of the site. A hand-edited value reaching
     * the table would not break this page — it would break the menu
     * everywhere, for everyone, on the next request.
     */
    public function testAColumnThatDoesNotBelongToTheSectionIsRefusedBeforeItIsWritten(): void
    {
        $this->expectException(TextPageException::class);
        $this->expectExceptionMessage("Cette colonne n'existe pas dans cette section.");

        // 'argent' is a column of Espace animateurs, never of Configuration.
        $this->service->create('Page', 'Page', MenuBuilder::MENU_CONFIGURATION, 'argent');
    }

    public function testTheOneSectionWithoutColumnsRefusesAColumn(): void
    {
        $this->expectException(TextPageException::class);
        $this->expectExceptionMessage("Cette section de menu n'a pas de colonnes.");

        $this->service->create('Page', 'Page', MenuBuilder::MENU_NOTRE_UNITE, 'site');
    }

    public function testASectionWithColumnsRefusesAPageWithNone(): void
    {
        $this->expectException(TextPageException::class);
        $this->expectExceptionMessage('Choisissez une colonne pour cette section.');

        $this->service->create('Page', 'Page', MenuBuilder::MENU_CONFIGURATION, null);
    }

    public function testAnUnknownSectionIsRefused(): void
    {
        $this->expectException(TextPageException::class);
        $this->expectExceptionMessage("Cette section de menu n'existe pas.");

        $this->service->create('Page', 'Page', 'menu_bricole', null);
    }

    /**
     * Every column this service can propose is one `addPage()` accepts.
     *
     * The form preselects a column; the point of checking every menu
     * here is that the preselection can never be the thing that takes
     * the site's menu down.
     */
    public function testEveryProposedDefaultColumnIsOneTheMenuDeclares(): void
    {
        foreach (MenuBuilder::menuIds() as $menuId) {
            $default = $this->service->defaultGroupFor($menuId);
            $declared = MenuBuilder::groupIdsFor($menuId);

            if ($declared === []) {
                $this->assertNull($default, "menu {$menuId} has no columns");
                continue;
            }

            $this->assertContains($default, $declared, "menu {$menuId}");
            // And the pair survives the real validator.
            $this->service->assertMenuPlacement($menuId, $default);
        }
    }

    public function testBothNamesAreRequired(): void
    {
        try {
            $this->service->create('   ', 'Un titre', MenuBuilder::MENU_NOTRE_UNITE, null);
            $this->fail('an empty menu name should be refused');
        } catch (TextPageException $e) {
            $this->assertSame('Le nom dans le menu est obligatoire.', $e->getMessage());
        }

        try {
            $this->service->create('Un nom', '   ', MenuBuilder::MENU_NOTRE_UNITE, null);
            $this->fail('an empty title should be refused');
        } catch (TextPageException $e) {
            $this->assertSame('Le titre de la page est obligatoire.', $e->getMessage());
        }
    }

    /**
     * The content key is the page's id, never its slug — so a page that
     * is ever renamed cannot orphan its own text.
     */
    public function testTheContentKeyIsBuiltFromTheIdAndNotFromTheSlug(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->assertSame('page_content_' . $page->id, $page->contentKey());
        $this->assertStringNotContainsString($page->slug, $page->contentKey());
    }

    /**
     * Deleting a page deletes the text that belonged to it — and the
     * service does not lift a finger.
     *
     * `editable_contents.text_page_id` is a foreign key with
     * `ON DELETE CASCADE`, so the database removes the row in the same
     * statement. A second delete issued from the service could fail on
     * its own — a key spelled differently, a connection lost between the
     * two — and leave rich text nobody can name, read or erase behind.
     * The constraint cannot.
     *
     * SQLite does not enforce foreign keys unless asked, so this test
     * asks; MySQL enforces them always.
     */
    public function testDeletingAPageTakesItsTextWithIt(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $content = new EditableContentService(new EditableContentRepository($this->pdo));
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);
        $content->set($page->contentKey(), '<p>Le texte de la page.</p>', 'rich_text', 1);

        $this->assertNotSame('', $content->get($page->contentKey()));

        $this->service->delete($page->id);

        $this->assertNull($this->repository->findById($page->id));
        $this->assertNull(
            (new EditableContentService(new EditableContentRepository($this->pdo)))->get($page->contentKey()),
            'the text must not outlive the page it belonged to'
        );
    }

    public function testAHiddenPageIsNotResolvedBySlug(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);
        $this->assertInstanceOf(TextPage::class, $this->service->findActiveBySlug($page->slug));

        $this->service->setActive($page->id, false);

        $this->assertNull($this->service->findActiveBySlug($page->slug));
        $this->assertCount(0, $this->service->listActive());
        $this->assertCount(1, $this->service->listAll(), 'it is hidden, not deleted');
    }

    public function testANewPageLandsLastInItsOwnMenu(): void
    {
        $first = $this->service->create('A', 'A', MenuBuilder::MENU_NOTRE_UNITE, null);
        $second = $this->service->create('B', 'B', MenuBuilder::MENU_NOTRE_UNITE, null);
        // A different menu counts its own order from scratch.
        $other = $this->service->create('C', 'C', MenuBuilder::MENU_ESPACE_ANIMES, 'unite');

        $this->assertSame(0, $first->sortOrder);
        $this->assertSame(1, $second->sortOrder);
        $this->assertSame(0, $other->sortOrder);
    }

    public function testReorderingWritesThePositionsInTheOrderGiven(): void
    {
        $a = $this->service->create('A', 'A', MenuBuilder::MENU_NOTRE_UNITE, null);
        $b = $this->service->create('B', 'B', MenuBuilder::MENU_NOTRE_UNITE, null);
        $c = $this->service->create('C', 'C', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->service->reorder([$c->id, $a->id, $b->id]);

        $this->assertSame(0, $this->repository->findById($c->id)?->sortOrder);
        $this->assertSame(1, $this->repository->findById($a->id)?->sortOrder);
        $this->assertSame(2, $this->repository->findById($b->id)?->sortOrder);
    }
}
