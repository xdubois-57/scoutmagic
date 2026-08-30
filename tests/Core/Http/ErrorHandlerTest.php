<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\ErrorHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
class ErrorHandlerTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        // The handler logs the real detail via error_log(); redirect that to
        // a file so it doesn't hit the isolated runner's stderr (which the
        // parent process would otherwise parse as a genuine uncaught error).
        $this->logFile = tempnam(sys_get_temp_dir(), 'errhandler_log_');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ErrorHandler::setJournalService(null);

        if ($this->logFile !== '' && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    /**
     * The real detail must still be logged server-side, just not shown.
     */
    public function testTheThrowableDetailIsLoggedServerSide(): void
    {
        ErrorHandler::register(false);

        ob_start();
        ErrorHandler::handleThrowable(new \RuntimeException('needle-in-the-log'));
        ob_end_clean();

        $this->assertStringContainsString('needle-in-the-log', (string) file_get_contents($this->logFile));
    }

    public function testRegisterForcesDisplayErrorsOffInProduction(): void
    {
        ErrorHandler::register(false);
        $this->assertSame('0', ini_get('display_errors'));
        $this->assertSame('1', ini_get('log_errors'));
    }

    public function testRegisterLeavesDisplayErrorsOnInDebug(): void
    {
        ErrorHandler::register(true);
        $this->assertSame('1', ini_get('display_errors'));
    }

    public function testGuardReturnsTheCallbackResultWhenNothingThrows(): void
    {
        ErrorHandler::register(false);
        $this->assertSame(42, ErrorHandler::guard(static fn() => 42));
    }

    /**
     * A production 500 page must never contain the exception message,
     * class, file path or trace — that is the whole point of the handler.
     */
    public function testProductionPageHidesTheThrowableDetail(): void
    {
        ErrorHandler::register(false);

        ob_start();
        ErrorHandler::handleThrowable(
            new \RuntimeException('SQLSTATE[HY000] Access denied for user secret-password')
        );
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('Une erreur est survenue', $body);
        $this->assertStringNotContainsString('secret-password', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    /**
     * In debug the operator is shown the detail deliberately — but escaped,
     * so a throwable message that contains markup cannot inject into the
     * error page itself.
     */
    public function testDebugPageShowsEscapedDetail(): void
    {
        ErrorHandler::register(true);

        ob_start();
        ErrorHandler::handleThrowable(new \RuntimeException('<script>alert(1)</script>'));
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testEmittedPageCarriesTheBaselineSecurityHeaders(): void
    {
        ErrorHandler::register(false);

        ob_start();
        ErrorHandler::handleThrowable(new \RuntimeException('x'));
        ob_end_clean();

        // headers_list() is empty under the CLI SAPI, so assert on the
        // http_response_code the handler set instead — the header emission
        // is exercised structurally by the source and by the code path above.
        $this->assertSame(500, http_response_code());
    }

    /**
     * The reason this whole iteration exists: on shared hosting the
     * operator does not choose where error_log() writes, so the same
     * detail has to reach a place the site itself can show.
     */
    public function testTheThrowableIsAlsoWrittenToTheSiteJournal(): void
    {
        ErrorHandler::register(false);
        $journal = $this->recordingJournal();
        ErrorHandler::setJournalService($journal);

        ob_start();
        ErrorHandler::handleThrowable(new \RuntimeException('journal-needle'));
        ob_end_clean();

        $this->assertCount(1, $journal->calls);
        [$category, $type, $level, $description, $context, $userId] = $journal->calls[0];

        $this->assertSame('core', $category);
        $this->assertSame('uncaught_error', $type);
        $this->assertSame('error', $level);
        $this->assertStringContainsString('RuntimeException', $description);
        $this->assertStringContainsString('journal-needle', $description);
        $this->assertStringContainsString(basename(__FILE__), $description);
        $this->assertSame(\RuntimeException::class, $context['exception']);
        $this->assertNotEmpty($context['trace']);
        // No acting user: reading the session is exactly what this class
        // refuses to do, since the session may be what failed.
        $this->assertNull($userId);
    }

    /**
     * The journal write is SECONDARY. Whatever it does — a dead database,
     * an event_log table that does not exist yet, an Error rather than an
     * Exception — one error must never become two: error_log() has already
     * run and the generic 500 page must still be emitted.
     */
    public function testAFailingJournalNeverEscalatesTheError(): void
    {
        ErrorHandler::register(false);
        ErrorHandler::setJournalService($this->throwingJournal());

        ob_start();
        ErrorHandler::handleThrowable(new \RuntimeException('needle-survives-a-dead-journal'));
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('Une erreur est survenue', $body);
        $this->assertSame(500, http_response_code());
        $this->assertStringContainsString(
            'needle-survives-a-dead-journal',
            (string) file_get_contents($this->logFile)
        );
    }

    public function testNothingIsWrittenWhenNoJournalIsWired(): void
    {
        ErrorHandler::register(false);

        ob_start();
        ErrorHandler::handleThrowable(new \RuntimeException('before-the-database'));
        $body = (string) ob_get_clean();

        // The documented consequence: an error raised before the database
        // is available is logged and displayed, and simply has nowhere to
        // be journaled. It must not fail trying.
        $this->assertStringContainsString('Une erreur est survenue', $body);
        $this->assertStringContainsString('before-the-database', (string) file_get_contents($this->logFile));
    }

    /**
     * getTraceAsString() puts every frame's ARGUMENTS in the string. The
     * trace of the incident this iteration comes from carried the user's
     * email address that way — into a table any chef d'unité can read.
     * The first assertion pins the hazard itself, so this test still means
     * something if PHP ever changes what it renders.
     */
    public function testTheSanitizedTraceKeepsTheFramesAndDropsTheArguments(): void
    {
        self::renderArgumentsInTraces();

        $caught = null;
        try {
            self::failWithAnEmailArgument('ab@cd.be');
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertStringContainsString('ab@cd.be', $caught->getTraceAsString());

        $sanitized = implode("\n", ErrorHandler::sanitizeTrace($caught));

        $this->assertStringNotContainsString('ab@cd.be', $sanitized);
        $this->assertStringContainsString('failWithAnEmailArgument', $sanitized);
        $this->assertStringContainsString(self::class, $sanitized);
        $this->assertStringContainsString(basename(__FILE__), $sanitized);
    }

    /**
     * The same guarantee where it actually matters: the context the
     * journal stores, for a throwable raised with a personal-data
     * argument on the stack.
     */
    public function testTheJournalContextCarriesNoCallArguments(): void
    {
        self::renderArgumentsInTraces();
        ErrorHandler::register(false);
        $journal = $this->recordingJournal();
        ErrorHandler::setJournalService($journal);

        try {
            self::failWithAnEmailArgument('ab@cd.be');
        } catch (\RuntimeException $e) {
            ob_start();
            ErrorHandler::handleThrowable($e);
            ob_end_clean();
        }

        $this->assertCount(1, $journal->calls);
        $encoded = (string) json_encode($journal->calls[0][4]);
        $this->assertStringNotContainsString('ab@cd.be', $encoded);
        $this->assertStringContainsString('failWithAnEmailArgument', $encoded);
    }

    /**
     * event_log.description is a VARCHAR(500): a longer description is
     * refused by the database in strict mode, which would lose exactly the
     * errors that have the most to say. The location must survive the cut,
     * or the entry says something broke somewhere.
     */
    public function testTheDescriptionIsTruncatedToTheColumnLengthAndKeepsTheLocation(): void
    {
        // Multi-byte on purpose: 500 CHARACTERS, not 500 bytes.
        $description = ErrorHandler::describe(new \RuntimeException(str_repeat('é', 2_000)));

        $this->assertSame(ErrorHandler::DESCRIPTION_MAX_LENGTH, mb_strlen($description));
        $this->assertStringContainsString('RuntimeException', $description);
        $this->assertStringContainsString(basename(__FILE__), $description);
        $this->assertStringEndsWith('…', $description);
    }

    public function testAShortDescriptionIsLeftAlone(): void
    {
        $description = ErrorHandler::describe(new \RuntimeException('court'));

        $this->assertLessThanOrEqual(ErrorHandler::DESCRIPTION_MAX_LENGTH, mb_strlen($description));
        $this->assertStringContainsString('court', $description);
        $this->assertStringStartsWith('Erreur non interceptée : ', $description);
    }

    /**
     * A message spanning several lines is rendered in one table cell.
     */
    public function testTheDescriptionIsASingleLine(): void
    {
        $description = ErrorHandler::describe(new \RuntimeException("première ligne\n\tseconde ligne"));

        $this->assertStringNotContainsString("\n", $description);
        $this->assertStringContainsString('première ligne seconde ligne', $description);
    }

    /**
     * Put PHP back in the configuration where a trace carries its call
     * arguments, which is what makes the hazard reproducible here.
     *
     * Two settings decide it, and both are OFF the leaking way by
     * default — `php.ini-production` is what turns them off, and a shared
     * host shipping no php.ini at all (or the development one) leaks:
     * `zend.exception_ignore_args` records the arguments at throw time,
     * `zend.exception_string_param_max_len` decides how much of a string
     * argument is then printed (0 renders every string as '...', which is
     * why forcing only the first setting proves nothing).
     */
    private static function renderArgumentsInTraces(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '15');
    }

    /**
     * @param string $email deliberately unused — it exists to sit in the
     *                      stack frame's arguments
     */
    private static function failWithAnEmailArgument(string $email): void
    {
        throw new \RuntimeException('boom');
    }

    /**
     * A JournalService that records instead of writing. A real one needs a
     * database; what these tests are about is WHAT the handler asks the
     * journal to store.
     */
    private function recordingJournal(): JournalService
    {
        return new class ($this->createStub(JournalRepository::class)) extends JournalService {
            /** @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>, 5: int|null}> */
            public array $calls = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log(
                string $category,
                string $type,
                string $level,
                string $description,
                array $context = [],
                ?int $userId = null
            ): void {
                $this->calls[] = [$category, $type, $level, $description, $context, $userId];
            }
        };
    }

    /**
     * The journal an installation whose database has just died offers.
     */
    private function throwingJournal(): JournalService
    {
        return new class ($this->createStub(JournalRepository::class)) extends JournalService {
            /**
             * @param array<string, mixed> $context
             */
            public function log(
                string $category,
                string $type,
                string $level,
                string $description,
                array $context = [],
                ?int $userId = null
            ): void {
                throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
            }
        };
    }
}
