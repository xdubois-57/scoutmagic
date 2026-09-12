<?php

declare(strict_types=1);

namespace Tests\Bootstrap;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

if (!defined('BOOTSTRAP_TEST')) {
    define('BOOTSTRAP_TEST', true);
}
require_once dirname(__DIR__, 2) . '/bootstrap/bootstrap.php';

/**
 * The installer's step machine, driven in process.
 *
 * BootstrapTest covers the pure helpers one by one, and drives the three
 * request handlers through a real `php -S` subprocess — the only way to
 * reach them, since they read php://input (which no CLI SAPI populates)
 * and answer through bootstrapSendJson(), which closes every output
 * buffer including the test runner's own.
 *
 * What sat between those two, untested, is the layer the handlers dispatch
 * to: the bootstrapStep*() functions. Each takes ($docRoot, $state) and
 * returns the next $state, touching nothing but the filesystem — so each
 * one runs here against a throwaway document root, with the state array a
 * previous step would have handed it.
 *
 * bootstrapStepResolve() and bootstrapStepDownload() are the two absent
 * from this file, and deliberately: both name their HTTP callable inline
 * ('bootstrapDefaultHttpGet', 'bootstrapDefaultDownloader') rather than
 * taking it as an argument, so reaching them means reaching GitHub. The
 * functions they wrap — bootstrapFetchLatestRelease(),
 * bootstrapDownloadWithRetry(), bootstrapCheckDiskSpace() — all take their
 * callable as a parameter and are tested with a stub in BootstrapTest.
 *
 * bootstrap/bootstrap.php declares no namespace (it must run standalone,
 * before vendor/autoload.php exists) — hence the leading backslashes.
 */
class BootstrapStepsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/bootstrap_steps_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------

    /**
     * An extracted release artifact: the three entries
     * bootstrapVerifyArtifact() requires, plus the `config/app.php.dist`
     * that a fresh install seeds `config/app.php` from.
     */
    private function artifactRoot(string $path): string
    {
        mkdir($path . '/vendor', 0755, true);
        mkdir($path . '/public', 0755, true);
        mkdir($path . '/schema', 0755, true);
        mkdir($path . '/config', 0755, true);
        file_put_contents($path . '/vendor/autoload.php', "<?php // autoload\n");
        file_put_contents($path . '/public/index.php', "<?php // front controller\n");
        file_put_contents($path . '/schema/core.sql', "-- core schema\n");
        file_put_contents($path . '/config/app.php.dist', "<?php return [];\n");

        return $path;
    }

    /**
     * A document root that has already been through steps 6 to 8: files
     * copied, storage created, VERSION written. Layout B, so the
     * application lives in the document root itself and the public
     * directory is `public/` under it.
     *
     * @return array<string, mixed> the state those steps would have left
     */
    private function installedLayoutB(): array
    {
        $source = $this->artifactRoot($this->tempDir . '/source');
        $tempDir = $this->tempDir . '/.tmp-fixture';
        mkdir($tempDir, 0755, true);

        $state = [
            'layout' => 'B',
            'layout_parent' => dirname($this->tempDir),
            'source_root' => $source,
            'temp_dir' => $tempDir,
            'version' => '9.9.9',
        ];

        $state = \bootstrapStepInstall($this->tempDir, $state);
        $state = \bootstrapStepStorage($this->tempDir, $state);
        $state = \bootstrapStepFinalize($this->tempDir, $state);

        return $state;
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
    // Extraction and verification
    // -------------------------------------------------------------------

    public function testStepExtractUnpacksTheArchiveAndFindsItsRoot(): void
    {
        $tempDir = $this->tempDir . '/.tmp-extract';
        mkdir($tempDir, 0755, true);
        $zipPath = $tempDir . '/artifact.zip';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('public/index.php', "<?php // front controller\n");
        $zip->addFromString('VERSION', "9.9.9\n");
        $zip->close();

        $state = \bootstrapStepExtract($this->tempDir, [
            'temp_dir' => $tempDir,
            'artifact_path' => $zipPath,
            'source_type' => 'asset',
        ]);

        $this->assertSame($tempDir . '/extracted', $state['extracted_dir']);
        // A release asset is never unwrapped: its entries are the tree.
        $this->assertSame($tempDir . '/extracted', $state['source_root']);
        $this->assertFileExists($tempDir . '/extracted/public/index.php');
        $this->assertSame('Extraction', $state['label']);
        $this->assertSame(100, $state['percent']);
    }

    public function testStepVerifyArtifactPassesOnACompleteArchive(): void
    {
        $state = \bootstrapStepVerifyArtifact($this->tempDir, [
            'source_root' => $this->artifactRoot($this->tempDir . '/source'),
        ]);

        $this->assertSame("Vérification de l'artefact", $state['label']);
    }

    public function testStepVerifyArtifactRefusesAnArchiveWithoutVendor(): void
    {
        // The one that bites: no Composer on a shared host means a
        // vendor-less artifact installs cleanly and yields a dead site.
        $source = $this->artifactRoot($this->tempDir . '/source');
        unlink($source . '/vendor/autoload.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/vendor\/autoload\.php/');
        \bootstrapStepVerifyArtifact($this->tempDir, ['source_root' => $source]);
    }

    public function testStepVerifyArtifactRefusesAnArchiveShippingItsOwnHtaccess(): void
    {
        // An artifact .htaccess would overwrite the one the installer
        // wrote for this layout, which is what protects storage/.
        $source = $this->artifactRoot($this->tempDir . '/source');
        file_put_contents($source . '/.htaccess', "Require all denied\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\.htaccess/');
        \bootstrapStepVerifyArtifact($this->tempDir, ['source_root' => $source]);
    }

    // -------------------------------------------------------------------
    // Installation
    // -------------------------------------------------------------------

    public function testStepInstallCopiesTheArtifactAndWritesLayoutBsOwnFiles(): void
    {
        $source = $this->artifactRoot($this->tempDir . '/source');

        $state = \bootstrapStepInstall($this->tempDir, [
            'layout' => 'B',
            'layout_parent' => dirname($this->tempDir),
            'source_root' => $source,
        ]);

        $this->assertSame($this->tempDir, $state['install_target']);
        $this->assertContains('vendor', $state['installed_entries']);
        $this->assertFileExists($this->tempDir . '/vendor/autoload.php');
        $this->assertFileExists($this->tempDir . '/public/index.php');

        // Layout B keeps a single tree, so the document root needs the
        // deny rules and the stub that forwards to public/index.php.
        $this->assertFileExists($this->tempDir . '/.htaccess');
        $this->assertFileExists($this->tempDir . '/index.php');

        // config/app.php is seeded from the .dist the artifact ships;
        // the artifact never carries a real one (it is per-unit).
        $this->assertFileExists($this->tempDir . '/config/app.php');
    }

    public function testStepInstallWritesNoHtaccessNorStubInLayoutA(): void
    {
        // Layout A merges public/ into the document root, so the server
        // already serves the front controller and the application tree
        // sits one level up, outside the web root.
        $source = $this->artifactRoot($this->tempDir . '/source');
        $parent = $this->tempDir . '/parent';
        mkdir($parent . '/docroot', 0755, true);

        $state = \bootstrapStepInstall($parent . '/docroot', [
            'layout' => 'A',
            'layout_parent' => $parent,
            'source_root' => $source,
        ]);

        $this->assertSame($parent, $state['install_target']);
        $this->assertFileExists($parent . '/vendor/autoload.php');
        $this->assertFileDoesNotExist($parent . '/docroot/.htaccess');
        $this->assertFileDoesNotExist($parent . '/docroot/index.php');
    }

    public function testStepInstallNeverOverwritesAnExistingAppConfig(): void
    {
        $source = $this->artifactRoot($this->tempDir . '/source');
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/app.php', "<?php return ['kept' => true];\n");

        \bootstrapStepInstall($this->tempDir, [
            'layout' => 'B',
            'layout_parent' => dirname($this->tempDir),
            'source_root' => $source,
        ]);

        $this->assertStringContainsString('kept', (string) file_get_contents($this->tempDir . '/config/app.php'));
    }

    public function testStepStorageCreatesEverySubdirectory(): void
    {
        $state = \bootstrapStepStorage($this->tempDir, ['install_target' => $this->tempDir]);

        foreach (\BOOTSTRAP_STORAGE_SUBDIRS as $sub) {
            $this->assertDirectoryExists($this->tempDir . '/storage/' . $sub);
        }
        $this->assertSame('Création du stockage', $state['label']);
    }

    public function testStepFinalizeWritesTheVersionAndRemovesTheTemporaryDirectory(): void
    {
        $tempDir = $this->tempDir . '/.tmp-finalize';
        mkdir($tempDir . '/extracted', 0755, true);
        file_put_contents($tempDir . '/artifact.zip', 'PK');

        $state = \bootstrapStepFinalize($this->tempDir, [
            'install_target' => $this->tempDir,
            'temp_dir' => $tempDir,
            'version' => '9.9.9',
        ]);

        $this->assertSame("9.9.9\n", file_get_contents($this->tempDir . '/VERSION'));
        $this->assertDirectoryDoesNotExist($tempDir);
        $this->assertSame('Finalisation', $state['label']);
    }

    // -------------------------------------------------------------------
    // The gate: preparing the probes, and reading the browser's report
    // -------------------------------------------------------------------

    public function testStepGatePrepareWritesItsProbesAndWaitsForTheBrowser(): void
    {
        $state = $this->installedLayoutB();
        $this->removeDirectory($state['temp_dir']); // S8: the temp dir is gone by now.

        $state = \bootstrapStepGatePrepare($this->tempDir, $state);

        $this->assertTrue($state['awaiting_gate_report']);
        $this->assertSame(50, $state['percent'], 'the gate is only half done until the browser reports back');
        $this->assertCount(8, $state['s_checks']);
        foreach ($state['s_checks'] as $check) {
            $this->assertTrue($check['ok'], $check['id'] . ' should pass on a complete install: ' . $check['detail']);
        }

        $ids = array_column($state['probes'], 'id');
        // B1 is the positive control, B2 the token that must execute
        // rather than be served, B3-B8 the layout-B protections, F1 the
        // wizard itself.
        $this->assertSame(['B1', 'B2', 'B7', 'B3', 'B4', 'B5', 'B6', 'B8', 'F1'], $ids);
        foreach ($state['probes'] as $probe) {
            if (!empty($probe['file'])) {
                $this->assertFileExists($probe['file'], $probe['id'] . ' must have written its canary');
            }
        }
        // B2 overwrites token.php with a placeholder: the real token is
        // only written once the whole gate has passed.
        $this->assertStringContainsString('gate probe', (string) file_get_contents($this->tempDir . '/token.php'));
    }

    public function testStepGatePrepareRollsTheInstallBackWhenAStaticCheckFails(): void
    {
        $state = $this->installedLayoutB();
        $this->removeDirectory($state['temp_dir']);
        unlink($this->tempDir . '/schema/core.sql'); // S4 fails.

        $state = \bootstrapStepGatePrepare($this->tempDir, $state);

        $this->assertSame('S', $state['gate_aborted_at']);
        $this->assertFalse($state['gate_passed']);
        $this->assertFalse($state['awaiting_gate_report']);
        $this->assertArrayNotHasKey('probes', $state, 'no probe is written once the S checks have failed');

        // Rolled back: an install that cannot be trusted leaves nothing
        // behind, or the operator's retry meets "already installed".
        $this->assertFileDoesNotExist($this->tempDir . '/vendor/autoload.php');
        $this->assertFileDoesNotExist($this->tempDir . '/VERSION');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/storage');
        $this->assertFileDoesNotExist($this->tempDir . '/.htaccess');
    }

    public function testEvaluateGateReportPassesWhenEveryProbeAnsweredAsItShould(): void
    {
        $state = $this->installedLayoutB();
        $this->removeDirectory($state['temp_dir']);
        $state = \bootstrapStepGatePrepare($this->tempDir, $state);

        $state = \bootstrapEvaluateGateReport($this->tempDir, $state, $this->passingResults($state));

        $this->assertTrue($state['gate_passed'], 'a report where every probe behaved must pass the gate');
        $this->assertFalse($state['awaiting_gate_report']);
        $this->assertTrue($state['gate_report']['passed']);
        // The canaries are removed whichever way the gate goes: they are
        // readable files under storage/ by construction.
        foreach ($state['probes'] as $probe) {
            if (!empty($probe['file']) && ($probe['id'] ?? '') !== 'B2') {
                $this->assertFileDoesNotExist($probe['file'], $probe['id'] . "'s canary must not survive the gate");
            }
        }
    }

    public function testEvaluateGateReportFailsWhenAProtectedFileWasServed(): void
    {
        $state = $this->installedLayoutB();
        $this->removeDirectory($state['temp_dir']);
        $state = \bootstrapStepGatePrepare($this->tempDir, $state);

        $results = $this->passingResults($state);
        // B3 — the canary under storage/keys/ — came back with its
        // content, which means the server serves the encryption keys.
        foreach ($results as $index => $result) {
            if ($result['id'] === 'B3') {
                $results[$index] = [
                    'id' => 'B3',
                    'status' => 200,
                    'body' => \bootstrapProbeExpected($state, 'B3'),
                ];
            }
        }

        $state = \bootstrapEvaluateGateReport($this->tempDir, $state, $results);

        $this->assertFalse($state['gate_passed']);
        $failed = array_values(array_filter($state['b_checks'], static fn (array $c): bool => !$c['ok']));
        $this->assertSame(['B3'], array_column($failed, 'id'));
        // A failed gate takes the install with it.
        $this->assertFileDoesNotExist($this->tempDir . '/vendor/autoload.php');
    }

    public function testEvaluateGateReportFailsWhenTheControlProbeItselfDidNotAnswer(): void
    {
        // B1 is the positive control: if the browser could not read a
        // plain file it just wrote, nothing B2-B8 reports means anything,
        // and reading their silence as "protected" would pass an install
        // whose storage/ is wide open.
        $state = $this->installedLayoutB();
        $this->removeDirectory($state['temp_dir']);
        $state = \bootstrapStepGatePrepare($this->tempDir, $state);

        $results = $this->passingResults($state);
        foreach ($results as $index => $result) {
            if ($result['id'] === 'B1') {
                $results[$index] = ['id' => 'B1', 'status' => 404, 'body' => ''];
            }
        }

        $state = \bootstrapEvaluateGateReport($this->tempDir, $state, $results);

        $this->assertFalse($state['gate_passed']);
    }

    /**
     * What a browser that behaves correctly reports back: the control and
     * the wizard are readable, every protected URL is refused, the token
     * executes as PHP, and the storage/ listing is denied.
     *
     * @param array<string, mixed> $state
     * @return array<int, array{id: string, status: int, body: string}>
     */
    private function passingResults(array $state): array
    {
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

        return $results;
    }

    // -------------------------------------------------------------------
    // Token and cleanup
    // -------------------------------------------------------------------

    public function testStepTokenRefusesToGenerateOneBeforeTheGateHasPassed(): void
    {
        $this->expectException(RuntimeException::class);
        \bootstrapStepToken($this->tempDir, ['gate_passed' => false]);
    }

    public function testStepTokenWritesTheTokenFileOnceTheGateHasPassed(): void
    {
        $state = \bootstrapStepToken($this->tempDir, ['gate_passed' => true]);

        $this->assertTrue($state['token_written']);
        $this->assertArrayNotHasKey('token_write_warning', $state);
        $content = (string) file_get_contents($this->tempDir . '/token.php');
        $this->assertStringStartsWith('<?php', $content);
        // The token is what the operator types into the setup wizard, so
        // it has to survive being read back out of the file.
        $this->assertMatchesRegularExpression('/[0-9a-f]{16,}/', $content);
    }

    public function testStepCleanupRemovesTheStateFileAndReportsASelfDeletionItCouldNotDo(): void
    {
        // Self-deletion is injected because the real one unlinks
        // bootstrap.php itself — including the copy this suite runs from.
        file_put_contents($this->tempDir . '/' . \BOOTSTRAP_STATE_FILE, '<?php return [];');

        $state = \bootstrapStepCleanup($this->tempDir, [], static fn (): bool => false);

        $this->assertFileDoesNotExist($this->tempDir . '/' . \BOOTSTRAP_STATE_FILE);
        $this->assertFalse($state['self_deleted']);
        $this->assertStringContainsString('supprimez-le manuellement', $state['cleanup_warning']);
        $this->assertTrue($state['done']);
    }

    public function testStepCleanupSaysNothingWhenItDeletedItself(): void
    {
        $state = \bootstrapStepCleanup($this->tempDir, [], static fn (): bool => true);

        $this->assertTrue($state['self_deleted']);
        $this->assertArrayNotHasKey('cleanup_warning', $state);
    }

    public function testStepTokenSaysSoWhenItCouldNotWriteTheFileItself(): void
    {
        // A document root the installer cannot write into is the ordinary
        // shared-hosting case, and it must not end the install: the wizard
        // shows the exact content to create over FTP instead.
        $state = \bootstrapStepToken($this->tempDir . '/does-not-exist', ['gate_passed' => true]);

        $this->assertFalse($state['token_written']);
        $this->assertStringContainsString('manuellement', $state['token_write_warning']);
        $this->assertStringStartsWith('<?php', $state['token_manual_content']);
    }

    // -------------------------------------------------------------------
    // Preflight
    // -------------------------------------------------------------------

    /**
     * The one step that reaches outside the machine: it asks GitHub
     * whether outbound HTTPS works at all, through a callable it names
     * itself rather than takes. So the assertion follows whichever answer
     * this host actually gets — a CI runner without egress must not read
     * as a broken installer.
     */
    public function testPreflightRecordsTheLayoutItChoseOrRefusesForTheOneReasonItCannotWorkAround(): void
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        // bootstrapCheckLocation() reads this: under the test runner it is
        // the phpunit binary, which would read as "installed in a
        // subfolder" and refuse before anything else was tried.
        $_SERVER['SCRIPT_NAME'] = '/bootstrap.php';

        try {
            if (!\bootstrapDefaultHttpsProbe()) {
                $this->expectException(RuntimeException::class);
                $this->expectExceptionMessageMatches('/HTTPS/');
                \bootstrapStepPreflight($this->tempDir, []);

                return;
            }

            $state = \bootstrapStepPreflight($this->tempDir, []);

            $this->assertSame($this->tempDir, $state['doc_root']);
            $this->assertContains($state['layout'], ['A', 'B']);
            $this->assertNotSame('', $state['layout_reason']);
            $this->assertSame(PHP_VERSION, $state['environment']['php_version']);
            $this->assertSame('Préflight', $state['label']);
        } finally {
            if ($scriptName === null) {
                unset($_SERVER['SCRIPT_NAME']);
            } else {
                $_SERVER['SCRIPT_NAME'] = $scriptName;
            }
        }
    }

    public function testPreflightRefusesADocumentRootThatAlreadyHoldsAnInstallation(): void
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/bootstrap.php';
        file_put_contents($this->tempDir . '/VERSION', "1.0.0\n");

        try {
            if (!\bootstrapDefaultHttpsProbe()) {
                $this->markTestSkipped('no outbound HTTPS: preflight refuses on that before reaching its own guard');
            }

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/déjà une installation/');
            \bootstrapStepPreflight($this->tempDir, []);
        } finally {
            if ($scriptName === null) {
                unset($_SERVER['SCRIPT_NAME']);
            } else {
                $_SERVER['SCRIPT_NAME'] = $scriptName;
            }
        }
    }

    // -------------------------------------------------------------------
    // The two pages the installer draws
    // -------------------------------------------------------------------

    public function testTheWizardPageDrawsItsThreeScreensAndTheInstallButton(): void
    {
        ob_start();
        \bootstrapRenderUi($this->tempDir, $this->tempDir . '/' . \BOOTSTRAP_STATE_FILE);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('id="screen-confirm"', $html);
        $this->assertStringContainsString('id="screen-progress"', $html);
        $this->assertStringContainsString('id="screen-report"', $html);
        $this->assertStringContainsString('id="install-btn"', $html);
        // Preflight runs before anything is offered, so the page already
        // carries its verdict when it is first drawn.
        $this->assertStringContainsString('Version de PHP', $html);
    }

    public function testTheWizardPageNamesTheLayoutItWouldInstallAndWhatThatMeansForSecurity(): void
    {
        // The layout box is only drawn once every preflight check passes
        // — including the outbound-HTTPS one, so this follows the same
        // rule as the preflight tests above.
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/bootstrap.php';

        try {
            if (!\bootstrapDefaultHttpsProbe()) {
                $this->markTestSkipped('no outbound HTTPS: the wizard draws its refusal instead of the layout box');
            }

            ob_start();
            \bootstrapRenderUi($this->tempDir, $this->tempDir . '/' . \BOOTSTRAP_STATE_FILE);
            $html = (string) ob_get_clean();

            $this->assertMatchesRegularExpression('/Option [AB] —/', $html);
            $this->assertStringContainsString('Ce que cela signifie pour la sécurité', $html);
            // Whichever layout is chosen, the operator is told where the
            // files go before anything is written.
            $this->assertStringContainsString(\bootstrapHtmlEscape($this->tempDir), $html);
        } finally {
            if ($scriptName === null) {
                unset($_SERVER['SCRIPT_NAME']);
            } else {
                $_SERVER['SCRIPT_NAME'] = $scriptName;
            }
        }
    }

    public function testTheFunctionalProbeLooksForAMarkerTheApplicationStillRenders(): void
    {
        // F1 asks the browser to fetch "/" once the files are installed
        // and to find this marker — which belongs to the APPLICATION's
        // setup page, not to the installer's own. The two live in
        // different repositories' worth of distance from each other
        // (bootstrap.php ships alone, the template ships in the archive),
        // so a rename on either side fails every install at the last gate
        // with nothing else to go on.
        $state = $this->installedLayoutB();
        $this->removeDirectory($state['temp_dir']);
        $state = \bootstrapStepGatePrepare($this->tempDir, $state);

        $expected = \bootstrapProbeExpected($state, 'F1');
        $template = dirname(__DIR__, 2) . '/core/View/templates/setup/token_gate.html.twig';

        $this->assertNotSame('', $expected);
        $this->assertStringContainsString(
            $expected,
            (string) file_get_contents($template),
            'the application\'s setup page must still carry the marker the installer\'s F1 probe looks for'
        );
    }

    public function testTheErrorPageEscapesWhatItIsGiven(): void
    {
        ob_start();
        \bootstrapRenderErrorPage('<script>alert(1)</script> & compagnie');
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp; compagnie', $html);
    }

    // -------------------------------------------------------------------
    // Rollback
    // -------------------------------------------------------------------

    public function testRollbackRemovesEverythingTheInstallWroteAndNothingElse(): void
    {
        $state = $this->installedLayoutB();
        // A file the operator had on the host before any of this started.
        file_put_contents($this->tempDir . '/notes-du-chef.txt', 'à garder');

        \bootstrapRollbackInstall($this->tempDir, $state);

        $this->assertFileDoesNotExist($this->tempDir . '/vendor/autoload.php');
        $this->assertFileDoesNotExist($this->tempDir . '/public/index.php');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/storage');
        $this->assertFileDoesNotExist($this->tempDir . '/VERSION');
        $this->assertFileDoesNotExist($this->tempDir . '/.htaccess');
        $this->assertFileExists($this->tempDir . '/notes-du-chef.txt');
    }
}
