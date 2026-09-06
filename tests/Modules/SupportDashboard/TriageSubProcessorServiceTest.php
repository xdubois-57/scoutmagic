<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\SubProcessorView;
use Modules\SupportDashboard\Service\TriageSubProcessorService;
use Modules\SupportDashboard\Service\TriageTokenService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The RGPD declaration follows the token (ARCHITECTURE.md §8.49sexies):
 * no token, no processor — a route that refuses every call processes
 * nobody's data, and a document claiming otherwise would be wrong.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TriageSubProcessorServiceTest extends TestCase
{
    private TriageTokenService $tokens;
    private TriageSubProcessorService $service;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $pdo = DatabaseTestHelper::createTestDatabase();

        $repository = new SettingRepository($pdo);
        $repository->insert(
            TriageTokenService::MODULE_ID,
            TriageTokenService::SETTING_KEY,
            '',
            'secret',
            'Jeton',
            'Empreinte.',
            null,
            null,
            false,
            0
        );

        $this->tokens = new TriageTokenService(
            new SettingService($repository),
            new JournalService(new JournalRepository($pdo))
        );
        $this->service = new TriageSubProcessorService($this->tokens);
    }

    public function testNoTokenMeansNoSubProcessorAtAll(): void
    {
        $this->assertSame([], $this->service->getSubProcessors());
    }

    public function testAConfiguredTokenDeclaresBothProcessorsUnderTheTriageCategory(): void
    {
        $this->tokens->issue();

        $views = $this->service->getSubProcessors();

        $this->assertCount(1, $views);
        $this->assertSame(SubProcessorView::CATEGORY_ISSUE_TRIAGE, $views[0]->category);
        $this->assertStringContainsString('GitHub', $views[0]->name);
        $this->assertStringContainsString('Anthropic', $views[0]->name);
        $this->assertStringContainsString('hors UE', $views[0]->name);
        $this->assertStringContainsString('anonymisée', $views[0]->purpose);
    }

    public function testRevokingTheTokenWithdrawsTheDeclaration(): void
    {
        $this->tokens->issue();
        $this->tokens->revoke();

        $this->assertSame([], $this->service->getSubProcessors());
    }
}
