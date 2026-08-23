<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\RgpdConfigController;
use Core\Http\Request;
use Core\Journal\JournalService;
use Core\Module\ModuleManager;
use Core\Security\AuthSession;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use Core\View\RgpdGenerationException;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * RgpdConfigController::generate() catches \Throwable on purpose (any
 * uncaught exception here would return an HTML error page to a fetch()
 * that expects JSON). That makes it the single display site for two very
 * different kinds of message, and the whole reason
 * Core\View\RgpdGenerationException exists:
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
final class RgpdConfigControllerErrorMessagesTest extends TestCase
{
    protected function tearDown(): void
    {
        AuthSession::logout();
        unset($_SESSION['_csrf_token']);
    }

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
                'info',
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
     * Drives generate() with a RgpdContentService that throws $thrown, and
     * returns the `error` string the admin's browser receives.
     */
    private function errorFrom(\Throwable $thrown, ?JournalService $journal = null): string
    {
        AuthSession::login(1, 'super@example.org', 'superadmin');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $rgpdContentService = $this->createStub(RgpdContentService::class);
        $rgpdContentService->method('isAvailable')->willReturn(true);
        $rgpdContentService->method('generateWithAi')->willThrowException($thrown);

        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn(['llm_connector']);

        $controller = new RgpdConfigController(
            new Environment(new ArrayLoader(['config/rgpd.html.twig' => ''])),
            $this->createStub(EditableContentService::class),
            $rgpdContentService,
            $this->createStub(SettingService::class),
            $moduleManager,
            $journal ?? $this->createStub(JournalService::class)
        );

        $request = $this->createConfiguredStub(Request::class, [
            'getRawBody' => (string) json_encode(['_csrf_token' => $token, 'prompt' => 'Test']),
        ]);

        $response = $controller->generate($request, []);
        self::assertSame(500, $response->getStatusCode());

        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);
        self::assertFalse($decoded['success']);

        return (string) $decoded['error'];
    }
}
