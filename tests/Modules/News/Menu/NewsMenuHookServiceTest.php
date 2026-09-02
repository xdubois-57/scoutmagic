<?php

declare(strict_types=1);

namespace Tests\Modules\News\Menu;

use Core\Security\EncryptionService;
use Core\View\MenuBuilder;
use Modules\News\Menu\NewsMenuHookService;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\NewsForm;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * The « Scanner un billet » shortcut, and the one condition it carries.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class NewsMenuHookServiceTest extends TestCase
{
    private \PDO $pdo;
    private ArticleRepository $articles;
    private FormRepository $forms;
    private NewsMenuHookService $hook;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->articles = new ArticleRepository($this->pdo);
        $this->forms = new FormRepository($this->pdo);
        $this->hook = new NewsMenuHookService($this->forms);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('chief@test.com', 'user_accounts.email'), $encryption->blindIndex('chief@test.com', 'email')]);
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    private function form(bool $issuesTicket): void
    {
        $articleId = $this->articles->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->accountId);
        $this->forms->create(
            $articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', false, null, $issuesTicket
        );
    }

    public function testAUnitThatNeverRanATicketedEventCarriesNoEntry(): void
    {
        $this->form(false);

        // A dead entry in a menu is worse than no entry at all.
        $this->assertSame([], $this->hook->getMenuEntries('chief@test.com'));
    }

    public function testTheShortcutAppearsAsSoonAsOneFormDeliversATicket(): void
    {
        $this->form(false);
        $this->form(true);

        $entries = $this->hook->getMenuEntries('chief@test.com');

        $this->assertCount(1, $entries);
        $this->assertSame('Scanner un billet', $entries[0]->label);
        // It leads to the GENERIC page: a menu cannot know which evening
        // is being held. The article's own tab opens straight onto its
        // event.
        $this->assertSame('/news/scan', $entries[0]->url);
        $this->assertSame(MenuBuilder::MENU_ESPACE_CHEFS, $entries[0]->menuId);
        // The door is held by the animateurs, not only by the unit staff.
        $this->assertSame('chief', $entries[0]->roleMin);
    }

    public function testTheShortcutIsOfferedToAnAnonymousVisitorToo(): void
    {
        // The provider is called on every menu build, email or not; the
        // entry's own roleMin is what filters the display, and the route
        // its own guard. Answering differently on $email === null would
        // be a permission decision made in the wrong place
        // (ARCHITECTURE.md §12).
        $this->form(true);

        $this->assertCount(1, $this->hook->getMenuEntries(null));
    }
}
