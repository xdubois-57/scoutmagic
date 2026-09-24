<?php

declare(strict_types=1);

namespace Tests\Bootstrap;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

if (!defined('BOOTSTRAP_TEST')) {
    define('BOOTSTRAP_TEST', true);
}
require_once dirname(__DIR__, 2) . '/bootstrap/bootstrap.php';

/**
 * The installer's three request handlers, driven without a web server.
 *
 * Two things make them awkward to reach, and both are deliberate in the
 * installer rather than accidental:
 *
 * 1. They read their body from `php://input`, which no CLI SAPI ever
 *    populates. A stream wrapper registered over the `php` scheme for the
 *    duration of the call answers it instead — unregistered again in a
 *    `finally`, so nothing outside the call sees it.
 * 2. They answer through bootstrapSendJson(), which closes EVERY output
 *    buffer before echoing, so that a stray PHP warning can never end up
 *    inside the JSON body. One of those buffers belongs to PHPUnit, and
 *    closing it is what makes each test here report as **risky** — the
 *    runner is telling the truth, and the alternative was leaving these
 *    three functions untested in process. Every test therefore runs in a
 *    process of its own (#[RunInSeparateProcess]) so the destroyed buffer
 *    belongs to a runner nobody else is sharing, and asserts on what the
 *    handler wrote to disk rather than on what it printed.
 *
 * BootstrapTest keeps the two cases that need a real HTTP round trip (a
 * genuine `php -S`, no coverage possible); this file is everything else.
 * Steps 1 to 5 stay out of it: bootstrapStepPreflight() probes GitHub for
 * outbound HTTPS and steps 2 and 3 fetch a release, so a test of the
 * dispatch itself uses the late steps, which touch nothing but the disk.
 *
 * bootstrap/bootstrap.php declares no namespace (it must run standalone,
 * before vendor/autoload.php exists) — hence the leading backslashes.
 */
class BootstrapRequestHandlersTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/bootstrap_handlers_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------
    // The harness
    // -------------------------------------------------------------------

    /**
     * Runs $handler with `php://input` answering $body, with the output
     * buffers left as they were found.
     *
     * @param array<string, mixed> $body
     */
    private function withRequestBody(array $body, callable $handler): void
    {
        BootstrapFakeInput::$body = (string) json_encode($body);
        $level = ob_get_level();

        stream_wrapper_unregister('php');
        stream_wrapper_register('php', BootstrapFakeInput::class);

        try {
            $handler();
        } finally {
            stream_wrapper_restore('php');

            // The handler opens exactly one buffer of its own and unwinds
            // to the level it found, so anything still above that level is
            // ours to close — and the buffers below are the ones PHPUnit
            // was holding, untouched.
            //
            // This used to re-open buffers to make the COUNT match, which
            // is not the same thing as leaving them alone: the originals
            // had been destroyed with their contents, PHPUnit compares the
            // state rather than the cardinal, and fifteen tests here were
            // reported « Test code or tested code closed output buffers
            // other than its own » on every run. Risky, never failure, and
            // nothing in phpunit.xml turns that into an exit code — so it
            // said so for months into a green CI.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            $this->assertSame(
                $level,
                ob_get_level(),
                'the handler must leave the output buffering stack as it found it'
            );
        }
    }

    /** @param array<string, mixed> $state */
    private function seedState(array $state): string
    {
        $stateFile = $this->tempDir . '/' . \BOOTSTRAP_STATE_FILE;
        \bootstrapWriteState($stateFile, $state);

        return $stateFile;
    }

    private function lockNow(): void
    {
        file_put_contents($this->tempDir . '/' . \BOOTSTRAP_LOCK_FILE, (string) getmypid());
    }

    /**
     * A document root that has already been installed into: the artifact's
     * files, storage/, VERSION, and the state steps 6 to 8 left behind.
     *
     * @return array<string, mixed>
     */
    private function installedLayoutB(): array
    {
        $source = $this->tempDir . '/source';
        mkdir($source . '/vendor', 0755, true);
        mkdir($source . '/public', 0755, true);
        mkdir($source . '/schema', 0755, true);
        mkdir($source . '/config', 0755, true);
        file_put_contents($source . '/vendor/autoload.php', "<?php // autoload\n");
        file_put_contents($source . '/public/index.php', "<?php // front controller\n");
        file_put_contents($source . '/schema/core.sql', "-- core schema\n");
        file_put_contents($source . '/config/app.php.dist', "<?php return [];\n");

        $state = [
            'layout' => 'B',
            'layout_parent' => dirname($this->tempDir),
            'source_root' => $source,
            'temp_dir' => $this->tempDir . '/.tmp-gone',
            'version' => '9.9.9',
        ];
        $state = \bootstrapStepInstall($this->tempDir, $state);
        $state = \bootstrapStepStorage($this->tempDir, $state);

        return \bootstrapStepFinalize($this->tempDir, $state);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // -------------------------------------------------------------------
    // bootstrapSendJson()
    // -------------------------------------------------------------------

    /**
     * **The response is exactly one JSON document, whatever else printed.**
     *
     * This is the whole reason the function exists rather than a bare
     * `echo json_encode()`: a host with `display_errors` on, or an
     * unsuppressed `mkdir()` hitting an edge case, prints a warning ahead
     * of the JSON and `response.json()` fails client-side with an opaque
     * "did not match the expected pattern" — on the install screen, where
     * the operator has no other channel.
     *
     * Serving a request the handler is alone on the stack, its floor is
     * 0, and the whole stack unwinds — taking the stray output with it.
     * That is what this pins: the floor added for #426 protects buffers
     * the function did not open and must not weaken this.
     *
     * **In a subprocess on purpose.** Proving it means letting the
     * function unwind to zero, which is exactly what destroys PHPUnit's
     * own buffer — the defect this test accompanies. A test that caused
     * it to prove it had been fixed would be its own counter-example.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostOutputBuffering')]
    public function testAStrayWarningNeverReachesTheJsonBody(string $outputBuffering): void
    {
        // Printed INSIDE the handler's own buffer — the ordinary case: an
        // unsuppressed mkdir() during the step itself.
        $this->assertSame(
            '{"done":true}',
            $this->sendJsonInASubprocess(
                $outputBuffering,
                "\$buffering = ob_get_level();\n"
                . "ob_start();\n"
                . "echo 'Warning: mkdir(): File exists in /htdocs/x.php on line 1';"
            ),
            'a warning printed while the handler runs must not reach the body'
        );
    }

    /**
     * And the harder half: a warning printed BEFORE the handler opened
     * anything.
     *
     * Only asserted where the function can still reach it. With
     * `output_buffering` off, such output has already left for the client
     * and no code anywhere can recall it — that was as true of the
     * previous release as it is here. With it **on**, PHP holds it in an
     * implicit buffer, and that is the case this pins: the floor is 1,
     * closing "down to the floor" stops one level short of it, and the
     * JSON would be appended behind the warning in the very same buffer.
     *
     * The value is the one nobody chooses. **4096 is the default** in
     * both `php.ini-production` and `php.ini-development`, and the norm on
     * the shared hosting this installer targets — while the CLI SAPI
     * forces it off, so a subprocess run without `-d` proves only the easy
     * half. Measured on this machine at 4096: the floor-only version
     * answered `Warning: … {"done":true}`, its predecessor `{"done":true}`.
     */
    public function testAWarningPrintedBeforeTheHandlerOpenedAnythingIsStillDiscarded(): void
    {
        $this->assertSame(
            '{"done":true}',
            $this->sendJsonInASubprocess(
                '4096',
                "\$buffering = ob_get_level();\n"
                . "echo 'Warning: mkdir(): File exists in /htdocs/x.php on line 1';\n"
                . 'ob_start();'
            ),
            'a host holding the warning in its implicit buffer must not serve it with the JSON'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostOutputBuffering(): array
    {
        return [
            'off — the CLI default' => ['0'],
            'on — what php.ini-production ships' => ['4096'],
        ];
    }

    /**
     * Run `bootstrapSendJson()` in a real subprocess under a given
     * `output_buffering`, with `$preamble` deciding where the stray output
     * lands, and return **the last non-empty line** of what came back.
     *
     * The last line, not the whole output, and for the reason
     * `BootstrapTest::lastLineOfSubprocess()` already gives: a php CLI can
     * print noise of its own before anything under test runs — a
     * duplicate-extension warning is the one this repository has met — and
     * comparing the whole stream makes these tests fail on a machine rather
     * than on a defect. `failOnRisky` landing in `phpunit.xml` in this very
     * change leaves the suite less room for that, not more.
     *
     * It still catches what they are for: the warning these tests plant
     * lands on the **same line** as the JSON, with no newline between them,
     * because that is what `echo json_encode()` into a buffer that already
     * holds something produces. Startup noise sits on its own line; a leak
     * does not.
     */
    private function sendJsonInASubprocess(string $outputBuffering, string $preamble): string
    {
        $bootstrap = dirname(__DIR__, 2) . '/bootstrap/bootstrap.php';
        $script = "define('BOOTSTRAP_TEST', true);\n"
            . 'require ' . var_export($bootstrap, true) . ";\n"
            . $preamble . "\n"
            . 'bootstrapSendJson([\'done\' => true], $buffering);';

        $output = shell_exec(sprintf(
            '%s -d output_buffering=%s -r %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($outputBuffering),
            escapeshellarg($script)
        ));

        $lines = array_values(array_filter(
            explode("\n", trim((string) $output)),
            static fn (string $line): bool => trim($line) !== ''
        ));

        return $lines === [] ? '' : end($lines);
    }

    /**
     * And the other half, which is what #426 was about: given a floor, it
     * unwinds to the floor and no further. Fifteen tests in this file
     * reported « closed output buffers other than its own » on every run
     * because "as far down as I opened" and "as far down as there is"
     * were the same sentence.
     */
    public function testAFloorIsRespectedSoACallersBuffersSurvive(): void
    {
        $floor = ob_get_level();

        ob_start();
        \bootstrapSendJson(['done' => true], $floor);

        $this->assertSame($floor, ob_get_level());
    }

    // -------------------------------------------------------------------
    // bootstrapHandleStepRequest()
    // -------------------------------------------------------------------

    #[RunInSeparateProcess]
    public function testAStepRunsAndItsNewStateIsWrittenBack(): void
    {
        $state = $this->installedLayoutB();
        $stateFile = $this->seedState($state);
        $this->lockNow();

        // Step 9 — the gate's static half, which writes the browser's
        // probes and then waits for it to report back.
        $this->withRequestBody(
            ['step' => 9],
            fn () => \bootstrapHandleStepRequest($this->tempDir, $stateFile)
        );

        $written = \bootstrapReadState($stateFile);
        $this->assertTrue($written['awaiting_gate_report']);
        $this->assertNotEmpty($written['probes']);
        $this->assertSame(50, $written['percent']);
    }

    #[RunInSeparateProcess]
    public function testAStepThatSucceedsLeavesTheLockHeldForTheNextOne(): void
    {
        // Step 7 creates storage/. The lock belongs to the whole install,
        // not to one request, so it is still there afterwards — only the
        // last step, a failure, or an abort gives it back.
        //
        // Step 11 is the one step this file never drives through the
        // handler: bootstrapStepCleanup()'s default self-deletion unlinks
        // __FILE__, which under the test runner is this repository's own
        // bootstrap/bootstrap.php. BootstrapStepsTest calls it directly,
        // where the callable can be injected.
        $stateFile = $this->seedState(['install_target' => $this->tempDir, 'label' => 'Installation des fichiers']);
        $this->lockNow();

        $this->withRequestBody(
            ['step' => 7],
            fn () => \bootstrapHandleStepRequest($this->tempDir, $stateFile)
        );

        $written = \bootstrapReadState($stateFile);
        $this->assertSame('Création du stockage', $written['label']);
        $this->assertDirectoryExists($this->tempDir . '/storage/keys');
        $this->assertFileExists($this->tempDir . '/' . \BOOTSTRAP_LOCK_FILE);
    }

    #[RunInSeparateProcess]
    public function testAFailedStepRecordsTheReasonAndRollsTheInstallBack(): void
    {
        // Step 10 refuses to mint a token before the gate has passed. The
        // install on disk must not survive that refusal, or the operator's
        // retry meets "this folder already contains an installation" with
        // no way out but FTP.
        $state = $this->installedLayoutB();
        $state['gate_passed'] = false;
        $stateFile = $this->seedState($state);
        $this->lockNow();

        $this->withRequestBody(
            ['step' => 10],
            fn () => \bootstrapHandleStepRequest($this->tempDir, $stateFile)
        );

        $written = \bootstrapReadState($stateFile);
        $this->assertSame(10, $written['failed_step']);
        $this->assertStringContainsString('contrôles', $written['error']);
        $this->assertFileDoesNotExist($this->tempDir . '/vendor/autoload.php');
        $this->assertFileDoesNotExist($this->tempDir . '/VERSION');
        $this->assertFileDoesNotExist($this->tempDir . '/' . \BOOTSTRAP_LOCK_FILE, 'a failure never strands the lock');
    }

    #[RunInSeparateProcess]
    public function testAnUnknownStepNumberChangesNothing(): void
    {
        $stateFile = $this->seedState(['label' => 'Finalisation', 'percent' => 100]);
        $this->lockNow();

        $this->withRequestBody(
            ['step' => 99],
            fn () => \bootstrapHandleStepRequest($this->tempDir, $stateFile)
        );

        $this->assertSame(
            ['label' => 'Finalisation', 'percent' => 100],
            \bootstrapReadState($stateFile),
            'an unknown step is refused before anything is written'
        );
    }

    #[RunInSeparateProcess]
    public function testAStepOtherThanTheFirstIsRefusedWhenNoInstallIsRunning(): void
    {
        // No lock file: the browser is replaying a step from a page left
        // open after an abort, and nothing may resume from it.
        $stateFile = $this->seedState(['label' => 'Finalisation']);

        $this->withRequestBody(
            ['step' => 7],
            fn () => \bootstrapHandleStepRequest($this->tempDir, $stateFile)
        );

        $this->assertSame(['label' => 'Finalisation'], \bootstrapReadState($stateFile));
    }

    #[RunInSeparateProcess]
    public function testTheFirstStepIsRefusedWhileAnotherInstallHoldsTheLock(): void
    {
        // The lock is fresh, so this is a second browser tab (or a second
        // operator) starting over the top of a running install.
        $stateFile = $this->seedState([]);
        $this->lockNow();

        $this->withRequestBody(
            ['step' => 1],
            fn () => \bootstrapHandleStepRequest($this->tempDir, $stateFile)
        );

        // Refused before bootstrapStepPreflight() runs — nothing was
        // probed, nothing was written, and the lock still belongs to
        // whoever took it.
        $this->assertSame([], \bootstrapReadState($stateFile));
        $this->assertFileExists($this->tempDir . '/' . \BOOTSTRAP_LOCK_FILE);
    }

    // Each test drives exactly ONE request: the handler answers by
    // closing every output buffer and echoing, so a second call in the
    // same process would meet headers it has already sent. The state a
    // previous step would have left is built by calling that step
    // directly, which is what BootstrapStepsTest covers on its own.

    #[RunInSeparateProcess]
    public function testAVerificationRequestRefusesAnArchiveMissingItsVendorDirectory(): void
    {
        $source = $this->tempDir . '/source';
        mkdir($source . '/public', 0755, true);
        file_put_contents($source . '/public/index.php', "<?php // front controller\n");

        $stateFile = $this->seedState(['layout' => 'B', 'source_root' => $source, 'temp_dir' => null]);
        $this->lockNow();

        $this->withRequestBody(
            ['step' => 5],
            fn () => \bootstrapHandleStepRequest($this->tempDir, $stateFile)
        );

        $written = \bootstrapReadState($stateFile);
        $this->assertSame(5, $written['failed_step']);
        $this->assertStringContainsString('vendor/autoload.php', $written['error']);
    }

    #[RunInSeparateProcess]
    public function testAnInstallRequestCopiesTheArchiveIntoTheDocumentRoot(): void
    {
        $source = $this->tempDir . '/source';
        mkdir($source . '/vendor', 0755, true);
        mkdir($source . '/public', 0755, true);
        file_put_contents($source . '/vendor/autoload.php', "<?php // autoload\n");
        file_put_contents($source . '/public/index.php', "<?php // front controller\n");

        $stateFile = $this->seedState([
            'layout' => 'B',
            'layout_parent' => dirname($this->tempDir),
            'source_root' => $source,
        ]);
        $this->lockNow();

        $this->withRequestBody(
            ['step' => 6],
            fn () => \bootstrapHandleStepRequest($this->tempDir, $stateFile)
        );

        $written = \bootstrapReadState($stateFile);
        $this->assertSame($this->tempDir, $written['install_target']);
        $this->assertContains('vendor', $written['installed_entries']);
        $this->assertFileExists($this->tempDir . '/vendor/autoload.php');
        $this->assertFileExists($this->tempDir . '/.htaccess');
    }

    #[RunInSeparateProcess]
    public function testAFinalisationRequestWritesTheVersionAndDropsTheTemporaryDirectory(): void
    {
        $tempDir = $this->tempDir . '/.tmp-final';
        mkdir($tempDir . '/extracted', 0755, true);

        $stateFile = $this->seedState([
            'layout' => 'B',
            'install_target' => $this->tempDir,
            'temp_dir' => $tempDir,
            'version' => '9.9.9',
        ]);
        $this->lockNow();

        $this->withRequestBody(
            ['step' => 8],
            fn () => \bootstrapHandleStepRequest($this->tempDir, $stateFile)
        );

        $this->assertSame("9.9.9\n", file_get_contents($this->tempDir . '/VERSION'));
        $this->assertDirectoryDoesNotExist($tempDir);
        $this->assertSame('Finalisation', \bootstrapReadState($stateFile)['label']);
    }

    #[RunInSeparateProcess]
    public function testAStepThatFailsBeforeAnythingWasInstalledStillClearsItsTemporaryDirectory(): void
    {
        // Steps 2 to 5 leave a temp dir behind and nothing else; a failure
        // there must not leave a .tmp-xxxx directory in the document root
        // of a site that never got installed.
        $tempDir = $this->tempDir . '/.tmp-doomed';
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/artifact.zip', 'not a zip at all');

        $stateFile = $this->seedState([
            'layout' => 'B',
            'temp_dir' => $tempDir,
            'artifact_path' => $tempDir . '/artifact.zip',
            'source_type' => 'asset',
        ]);
        $this->lockNow();

        $this->withRequestBody(
            ['step' => 4],
            fn () => \bootstrapHandleStepRequest($this->tempDir, $stateFile)
        );

        $written = \bootstrapReadState($stateFile);
        $this->assertSame(4, $written['failed_step']);
        $this->assertNotSame('', $written['error']);
        $this->assertDirectoryDoesNotExist($tempDir);
        $this->assertFileDoesNotExist($this->tempDir . '/' . \BOOTSTRAP_LOCK_FILE);
    }

    #[RunInSeparateProcess]
    public function testAGateThatFailsItsStaticChecksGivesTheLockBackImmediately(): void
    {
        // The browser is never asked for anything in this case: the
        // installer already knows the install is not sound, so the
        // operator must be able to start over without waiting out the
        // ten-minute lock expiry.
        $state = $this->installedLayoutB();
        unlink($this->tempDir . '/schema/core.sql'); // S4.
        $stateFile = $this->seedState($state);
        $this->lockNow();

        $this->withRequestBody(
            ['step' => 9],
            fn () => \bootstrapHandleStepRequest($this->tempDir, $stateFile)
        );

        $written = \bootstrapReadState($stateFile);
        $this->assertSame('S', $written['gate_aborted_at']);
        $this->assertFalse($written['gate_passed']);
        $this->assertFileDoesNotExist($this->tempDir . '/' . \BOOTSTRAP_LOCK_FILE);
    }

    // -------------------------------------------------------------------
    // bootstrapHandleGateReport()
    // -------------------------------------------------------------------

    #[RunInSeparateProcess]
    public function testTheGateReportFromABrowserThatFoundEverythingProtectedPasses(): void
    {
        $state = $this->installedLayoutB();
        $state = \bootstrapStepGatePrepare($this->tempDir, $state);
        $stateFile = $this->seedState($state);
        $this->lockNow();

        $results = [];
        foreach ($state['probes'] as $probe) {
            $results[] = match ($probe['kind']) {
                'control', 'functional' => [
                    'id' => $probe['id'],
                    'status' => 200,
                    'body' => (string) $probe['expected'],
                ],
                'php_exec' => ['id' => $probe['id'], 'status' => 200, 'body' => ''],
                default => ['id' => $probe['id'], 'status' => 403, 'body' => ''],
            };
        }

        $this->withRequestBody(
            ['results' => $results],
            fn () => \bootstrapHandleGateReport($this->tempDir, $stateFile)
        );

        $written = \bootstrapReadState($stateFile);
        $this->assertTrue($written['gate_passed']);
        $this->assertFalse($written['awaiting_gate_report']);
        // The install stands, and the lock stays held for step 10.
        $this->assertFileExists($this->tempDir . '/vendor/autoload.php');
        $this->assertFileExists($this->tempDir . '/' . \BOOTSTRAP_LOCK_FILE);
    }

    #[RunInSeparateProcess]
    public function testAGateReportNobodyAskedForIsRefused(): void
    {
        // Nothing is awaiting a report, so this is a stale page posting
        // results for probes that no longer exist.
        $stateFile = $this->seedState(['label' => 'Finalisation']);

        $this->withRequestBody(
            ['results' => [['id' => 'B1', 'status' => 200, 'body' => 'whatever']]],
            fn () => \bootstrapHandleGateReport($this->tempDir, $stateFile)
        );

        $this->assertSame(['label' => 'Finalisation'], \bootstrapReadState($stateFile));
    }

    #[RunInSeparateProcess]
    public function testAGateReportWhereAProtectedFileWasServedFailsAndRollsBack(): void
    {
        $state = $this->installedLayoutB();
        $state = \bootstrapStepGatePrepare($this->tempDir, $state);
        $stateFile = $this->seedState($state);
        $this->lockNow();

        // The browser read every probe, including the canary under
        // storage/keys/ — this host serves the encryption keys.
        $results = [];
        foreach ($state['probes'] as $probe) {
            $results[] = [
                'id' => $probe['id'],
                'status' => 200,
                'body' => (string) ($probe['expected'] ?? ''),
            ];
        }

        $this->withRequestBody(
            ['results' => $results],
            fn () => \bootstrapHandleGateReport($this->tempDir, $stateFile)
        );

        $written = \bootstrapReadState($stateFile);
        $this->assertFalse($written['gate_passed']);
        $this->assertFileDoesNotExist(
            $this->tempDir . '/' . \BOOTSTRAP_LOCK_FILE,
            'a failed gate releases the lock so the operator can start over'
        );
    }

    // -------------------------------------------------------------------
    // bootstrapHandleAbortRequest()
    // -------------------------------------------------------------------

    #[RunInSeparateProcess]
    public function testAbortUndoesTheInstallAndForgetsEverythingAboutIt(): void
    {
        $state = $this->installedLayoutB();
        $stateFile = $this->seedState($state);
        $this->lockNow();

        $this->withRequestBody(
            [],
            fn () => \bootstrapHandleAbortRequest($this->tempDir, $stateFile)
        );

        $this->assertFileDoesNotExist($this->tempDir . '/vendor/autoload.php');
        $this->assertFileDoesNotExist($this->tempDir . '/VERSION');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/storage');
        $this->assertFileDoesNotExist($stateFile);
        $this->assertFileDoesNotExist($this->tempDir . '/' . \BOOTSTRAP_LOCK_FILE);
    }
}

/**
 * Answers `php://input` with a body the test chose, and nothing else:
 * every other `php://` path is refused, so a stray use of php://memory
 * while this is registered fails loudly instead of reading the body.
 */
class BootstrapFakeInput
{
    public static string $body = '';

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return $path === 'php://input';
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
