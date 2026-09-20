<?php

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Http\Controller\UploadController;
use Core\Page\TextPageContentAuthorizer;
use Core\Page\TextPageRepository;
use Core\Page\TextPageService;
use Core\Security\AuthSession;
use Core\View\ConfigurationMode;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The second door onto `editable_contents`, and why it had to be guarded
 * by this iteration rather than by the one that opened it.
 *
 * `POST /upload` with `context=editable_image` writes that table under a
 * key the CLIENT chooses, through `PhotoIngestionService` — never
 * touching `EditableContentController`. Its authorization was
 * `ConfigurationMode::isActive()` alone, which means `admin`, and that
 * was the whole answer for as long as every editable key sat on a page
 * an admin could also read. A free-text page filed in the Configuration
 * menu is read at `superadmin` (ARCHITECTURE.md §8.115): the gap is
 * newly reachable because of this feature, so it is this feature's to
 * close.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TextPageUploadDoorTest extends TestCase
{
    private TextPageService $pages;
    private UploadController $controller;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $repository = new TextPageRepository($pdo);
        $content = new EditableContentRepository($pdo);
        $this->pages = new TextPageService($repository, $content);

        $this->controller = new UploadController(
            new \Twig\Environment(new \Twig\Loader\ArrayLoader([])),
            $this->createMock(\Core\Photo\PhotoIngestionService::class),
            $this->createMock(\Core\Member\MemberService::class),
            new EditableContentService($content, [new TextPageContentAuthorizer($repository)])
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        ConfigurationMode::deactivate();
        AuthSession::logout();
    }

    private function mayUpload(string $role, string $key): bool
    {
        AuthSession::logout();
        AuthSession::login(7, $role . '@test.be', $role);
        ConfigurationMode::activate($role);

        $method = new \ReflectionMethod(UploadController::class, 'isUploadAuthorized');

        return (bool) $method->invoke($this->controller, 'editable_image', $key);
    }

    public function testAnAdminCannotUploadIntoASuperadminOnlyPagesBody(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_CONFIGURATION, 'site');

        $this->assertFalse($this->mayUpload('admin', $page->contentKey()));
        $this->assertTrue($this->mayUpload('superadmin', $page->contentKey()));
    }

    /**
     * A key owned by nothing keeps the door's own rule.
     *
     * Every editable key that predates free-text pages owns nothing, so
     * configuration mode remains the whole answer for them — the guard
     * narrows where it must and nowhere else.
     */
    public function testAKeyOwnedByNothingKeepsTheDoorsOwnRule(): void
    {
        $this->assertTrue($this->mayUpload('admin', 'home.hero'));
        $this->assertTrue($this->mayUpload('admin', 'page_content_999999'));
    }

    /**
     * A page an admin may read stays uploadable by an admin, and every
     * pre-existing editable key is untouched: the guard narrows where it
     * must and nowhere else.
     */
    public function testEveryOtherUploadIsUnaffected(): void
    {
        $page = $this->pages->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $this->assertTrue($this->mayUpload('admin', $page->contentKey()));
        $this->assertTrue($this->mayUpload('admin', 'home.hero'));
    }
}
