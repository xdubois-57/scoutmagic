<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Module\ModuleManager;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use Core\View\RgpdGenerationException;
use PHPUnit\Framework\TestCase;

/**
 * Core\View\RgpdGenerationRunner::runInBackground() catches \Throwable on
 * purpose: it is a scheduled task, and an uncaught exception there would
 * leave the page waiting on a run that will never report. That makes it
 * the single display site for two very different kinds of message, and
 * the whole reason Core\View\RgpdGenerationException exists:
 *
 * - the three refusals RgpdContentService writes FOR the admin — the AI
 *   service being unusable, an answer still truncated after every
 *   continuation, generated text that does not name the deploying unit as
 *   data controller. Each says what to change and retry, and each must
 *   survive verbatim;
 * - everything else — the nine « Erreur regex lors du nettoyage du HTML
 *   généré (…) » failures of sanitizeHtmlOutput(), a PDO error from the
 *   auto-save. These name an internal step, mean nothing to an admin, and
 *   must never be shown.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RgpdGenerationErrorMessagesTest extends TestCase
{
    public function testTheGenerationRefusalWrittenForTheAdminSurvives(): void
    {
        $refusal = 'Le contenu généré ne désigne pas clairement « 57e Unité » comme responsable du '
            . 'traitement — il n\'a pas été enregistré. Réessayez, ou complétez les instructions '
            . 'personnalisées pour préciser le nom de l\'unité.';

        $error = $this->errorFrom(new RgpdGenerationException($refusal));

        self::assertStringContainsString($refusal, $error);
    }

    public function testTheInternalCleanupFailureNeverReachesTheAdmin(): void
    {
        $error = $this->errorFrom(
            new \RuntimeException('Erreur regex lors du nettoyage du HTML généré (code fence début)')
        );

        self::assertStringNotContainsString('regex', $error);
        self::assertStringNotContainsString('code fence', $error);
        self::assertStringContainsString('n\'a pas pu être traité', $error);
    }

    /**
     * The catch is on \Throwable, so the auto-save's own failures land here
     * too — and a PDO error names the table and column it choked on.
     */
    public function testADatabaseErrorFromTheAutoSaveNeverReachesTheAdminEither(): void
    {
        $error = $this->errorFrom(
            new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'body' in 'editable_content'")
        );

        self::assertStringNotContainsString('SQLSTATE', $error);
        self::assertStringNotContainsString('editable_content', $error);
        self::assertStringContainsString('Réessayez', $error);
    }

    /**
     * The substituted sentence is not a loss of information — the real one
     * is journalled, with its class, file and line.
     */
    public function testTheSubstitutedDetailIsStillJournalled(): void
    {
        $journal = $this->createMock(JournalService::class);
        $journal->expects(self::once())
            ->method('log')
            ->with(
                'core',
                'rgpd_generation_failed',
                'error',
                self::anything(),
                self::callback(static fn(array $context): bool
                    => str_contains((string) $context['error'], 'code fence début')),
                self::anything()
            );

        $this->errorFrom(
            new \RuntimeException('Erreur regex lors du nettoyage du HTML généré (code fence début)'),
            $journal
        );
    }

    /**
     * Drives a background run whose RgpdContentService throws $thrown, and
     * returns the `error` string the admin's browser is eventually shown.
     *
     * The generation moved off the request — the document takes minutes
     * to write, and the provider timeout was what an administrator saw
     * instead (Core\View\RgpdGenerationRunner) — so the single display
     * site for these two kinds of message moved with it. What is being
     * pinned is unchanged: which sentence survives, which is substituted,
     * and that the substituted one is still journalled in full.
     */
    private function errorFrom(\Throwable $thrown, ?JournalService $journal = null): string
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $settingService = new SettingService(new \Core\Config\SettingRepository($pdo));

        $rgpdContentService = $this->createStub(RgpdContentService::class);
        $rgpdContentService->method('isAvailable')->willReturn(true);
        $rgpdContentService->method('generateWithAi')->willThrowException($thrown);

        $runner = new \Core\View\RgpdGenerationRunner(
            static fn (): RgpdContentService => $rgpdContentService,
            $settingService,
            new \Core\Scheduler\SchedulerService(new \Core\Scheduler\SchedulerRepository($pdo)),
            $this->createStub(EditableContentService::class),
            $journal ?? $this->createStub(JournalService::class)
        );

        $runner->runInBackground('Test', 1);

        $status = $runner->status();
        self::assertFalse($status['running']);
        self::assertSame('failed', $status['status']);

        return (string) ($status['error'] ?? '');
    }
}
