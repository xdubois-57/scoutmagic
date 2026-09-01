<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use Core\View\RgpdGenerationRunner;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Generating the RGPD document is a background job.
 *
 * The measurement is in the runner's own docblock; the short version is
 * that the system prompt carries the whole 135 KB reference document —
 * some 38 000 tokens — and the model is asked for a ten-section HTML
 * document across up to three sequential calls. Run inside the HTTP
 * request, with a ninety-second provider timeout, it produced « Échec de
 * génération : Timeout lors de l'appel au fournisseur IA » on a call that
 * had not failed so much as not finished.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RgpdGenerationRunnerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SchedulerService $scheduler;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->scheduler = new SchedulerService(new SchedulerRepository($this->pdo));

        // Declared by public/index.php on every web request, so the rows
        // exist by the time any background run happens. Repeated here
        // rather than re-declared by the runner: re-registering a setting
        // from a task would let a background process rewrite the page's
        // own declaration of it (this one is a `select` with three
        // options), which is a worse failure than the one it would avoid.
        $this->settings->register('rgpd_generation_mode', 'default', 'select', 'Mode de génération RGPD', 'x', null, null, ['default', 'custom', 'ai'], false);
        $this->settings->register('rgpd_custom_prompt', '', 'textarea', 'Prompt RGPD personnalisé', 'x', null, null, null, false);
    }

    private function runner(?RgpdContentService $content = null): RgpdGenerationRunner
    {
        return new RgpdGenerationRunner(
            fn (): RgpdContentService => $content ?? $this->createStub(RgpdContentService::class),
            $this->settings,
            $this->scheduler,
            $this->createStub(EditableContentService::class),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function queuedCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM scheduled_actions WHERE task_key = ?');
        $stmt->execute([RgpdGenerationRunner::TASK_KEY]);

        return (int) $stmt->fetchColumn();
    }

    public function testAskingForOneQueuesATaskRatherThanGeneratingHere(): void
    {
        $runner = $this->runner();

        $this->assertTrue($runner->scheduleBackgroundRun('Nous utilisons Google Drive.', 7));
        $this->assertSame(1, $this->queuedCount());
        $this->assertTrue($runner->isRunning());
        $this->assertSame(['running' => true, 'status' => 'running'], $runner->status());
    }

    /**
     * Two runs of a job this expensive would be two providers' bills for
     * one document, and a race over which answer gets saved.
     */
    public function testASecondRequestWhileOneIsInFlightIsRefused(): void
    {
        $runner = $this->runner();
        $runner->scheduleBackgroundRun('', 7);

        $this->assertFalse($runner->scheduleBackgroundRun('', 7));
        $this->assertSame(1, $this->queuedCount());
    }

    public function testTheOutcomeIsWhatThePageEventuallyReads(): void
    {
        $content = $this->createStub(RgpdContentService::class);
        $content->method('generateWithAi')->willReturn('<h2>Politique</h2>');

        $runner = $this->runner($content);
        $runner->scheduleBackgroundRun('', 7);
        $runner->runInBackground('', 7);

        $status = $runner->status();
        $this->assertFalse($status['running']);
        $this->assertSame('done', $status['status']);
        $this->assertSame('<h2>Politique</h2>', $status['content'] ?? null);
    }

    /**
     * A run that crashed must not leave the button disabled for half an
     * hour: the marker is cleared in a `finally`, whatever happened.
     */
    public function testAFailedRunFreesTheButton(): void
    {
        $content = $this->createStub(RgpdContentService::class);
        $content->method('generateWithAi')->willThrowException(new \RuntimeException('boum'));

        $runner = $this->runner($content);
        $runner->scheduleBackgroundRun('', 7);
        $runner->runInBackground('', 7);

        $this->assertFalse($runner->isRunning());
        $this->assertSame('failed', $runner->status()['status']);
        $this->assertTrue($runner->scheduleBackgroundRun('', 7));
    }

    /**
     * The pass that held the marker died — the host recycled the process,
     * a fatal hit a time limit — so nothing ever wrote an outcome. Saying
     * « interrompue » beats « en cours » for ever, and beats « terminé »
     * for a document nobody wrote.
     */
    public function testAMarkerThatExpiredWithNoOutcomeIsReportedAsInterrupted(): void
    {
        $runner = $this->runner();
        $runner->scheduleBackgroundRun('', 7);

        $this->settings->setInternal('rgpd_generation_started_at', (string) (time() - 7200));

        $status = $runner->status();
        $this->assertFalse($status['running']);
        $this->assertSame('failed', $status['status']);
        $this->assertStringContainsString('interrompue', $status['error'] ?? '');
    }

    /**
     * The state lives in the settings table and not in the session that
     * asked: reloading the page mid-run has to pick the ticker back up,
     * and the run outlives the request by minutes.
     */
    public function testTheStateSurvivesTheRequestThatAskedForIt(): void
    {
        $this->runner()->scheduleBackgroundRun('', 7);

        $fresh = new RgpdGenerationRunner(
            fn (): RgpdContentService => $this->createStub(RgpdContentService::class),
            new SettingService(new SettingRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $this->createStub(EditableContentService::class),
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->assertTrue($fresh->isRunning());
    }
}
