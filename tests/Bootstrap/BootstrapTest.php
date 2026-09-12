<?php

declare(strict_types=1);

namespace Tests\Bootstrap;

use PHPUnit\Framework\TestCase;
use RuntimeException;

if (!defined('BOOTSTRAP_TEST')) {
    define('BOOTSTRAP_TEST', true);
}
require_once dirname(__DIR__, 2) . '/bootstrap/bootstrap.php';

/**
 * bootstrap/bootstrap.php declares no namespace (it must run standalone,
 * before vendor/autoload.php exists) — every function under test lives in
 * the global namespace, hence the leading backslashes throughout.
 */
class BootstrapTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/bootstrap_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------
    // Archive URL resolution
    // -------------------------------------------------------------------

    public function testResolveArchiveUrlPrefersAsset(): void
    {
        $release = ['assets' => [['name' => 'release-v1.0.0.zip', 'browser_download_url' => 'https://example.com/artifact.zip', 'size' => 1234]], 'zipball_url' => 'https://example.com/zip'];
        $result = \bootstrapResolveArchiveUrl($release);

        $this->assertSame('https://example.com/artifact.zip', $result['url']);
        $this->assertSame(1234, $result['size']);
        $this->assertSame('asset', $result['source']);
    }

    /**
     * Regression: scripts/release.sh publishes bootstrap.php as a second
     * asset. GitHub does not preserve upload order in the assets array
     * (observed: it sorts alphabetically, landing bootstrap.php before the
     * zip at assets[0]) — the zip must be found by filename, not position.
     */
    public function testResolveArchiveUrlFindsZipRegardlessOfAssetOrder(): void
    {
        $release = ['assets' => [
            ['name' => 'bootstrap.php', 'browser_download_url' => 'https://example.com/bootstrap.php', 'size' => 100],
            ['name' => 'release-v1.0.1.zip', 'browser_download_url' => 'https://example.com/release-v1.0.1.zip', 'size' => 5000],
        ]];
        $result = \bootstrapResolveArchiveUrl($release);

        $this->assertSame('https://example.com/release-v1.0.1.zip', $result['url']);
        $this->assertSame('asset', $result['source']);
    }

    public function testResolveArchiveUrlFallsBackToZipballWhenNoZipNamedAsset(): void
    {
        $release = ['assets' => [['name' => 'bootstrap.php', 'browser_download_url' => 'https://example.com/bootstrap.php', 'size' => 100]], 'zipball_url' => 'https://example.com/zip'];
        $result = \bootstrapResolveArchiveUrl($release);

        $this->assertSame('https://example.com/zip', $result['url']);
        $this->assertSame('zipball', $result['source']);
    }

    public function testResolveArchiveUrlFallsBackToZipball(): void
    {
        $release = ['assets' => [], 'zipball_url' => 'https://example.com/zip'];
        $result = \bootstrapResolveArchiveUrl($release);

        $this->assertSame('https://example.com/zip', $result['url']);
        $this->assertSame(0, $result['size']);
        $this->assertSame('zipball', $result['source']);
    }

    public function testResolveArchiveUrlThrowsWhenNeitherPresent(): void
    {
        $this->expectException(RuntimeException::class);
        \bootstrapResolveArchiveUrl([]);
    }

    // -------------------------------------------------------------------
    // Archive root resolution + zip-slip rejection
    // -------------------------------------------------------------------

    public function testResolveArchiveRootUnwrapsZipballSingleTopLevelDir(): void
    {
        $extracted = $this->tempDir . '/extracted';
        mkdir($extracted . '/owner-repo-abc123', 0755, true);

        $this->assertSame($extracted . '/owner-repo-abc123', \bootstrapResolveArchiveRoot($extracted, 'zipball'));
    }

    public function testResolveArchiveRootNeverUnwrapsReleaseAsset(): void
    {
        $extracted = $this->tempDir . '/extracted';
        mkdir($extracted . '/only-dir', 0755, true);

        // Even with a single top-level dir (coincidentally zipball-shaped),
        // a release asset must never be stripped — decided by source type,
        // never entry count.
        $this->assertSame($extracted, \bootstrapResolveArchiveRoot($extracted, 'asset'));
    }

    public function testResolveArchiveRootLeavesMultiEntryZipballAlone(): void
    {
        $extracted = $this->tempDir . '/extracted';
        mkdir($extracted . '/core', 0755, true);
        mkdir($extracted . '/public', 0755, true);

        $this->assertSame($extracted, \bootstrapResolveArchiveRoot($extracted, 'zipball'));
    }

    public function testIsZipSlipRejectsParentTraversal(): void
    {
        $this->assertTrue(\bootstrapIsZipSlip('../../etc/passwd'));
        $this->assertTrue(\bootstrapIsZipSlip('core/../../../etc/passwd'));
    }

    public function testIsZipSlipRejectsAbsolutePath(): void
    {
        $this->assertTrue(\bootstrapIsZipSlip('/etc/passwd'));
    }

    public function testIsZipSlipRejectsWindowsDriveLetter(): void
    {
        $this->assertTrue(\bootstrapIsZipSlip('C:\\Windows\\System32'));
    }

    public function testIsZipSlipAcceptsOrdinaryRelativeEntries(): void
    {
        $this->assertFalse(\bootstrapIsZipSlip('core/Http/Controller/PageController.php'));
        $this->assertFalse(\bootstrapIsZipSlip('public/index.php'));
    }

    // -------------------------------------------------------------------
    // Layout selection — the 4 scenarios
    // -------------------------------------------------------------------

    public function testSelectLayoutChoosesAWhenParentWritableAndContainsDocRoot(): void
    {
        $parent = $this->tempDir . '/parent';
        $docRoot = $parent . '/public';
        mkdir($docRoot, 0755, true);

        $result = \bootstrapSelectLayout($docRoot, static fn (string $dir): bool => true);

        $this->assertSame('A', $result['layout']);
        $this->assertSame($parent, $result['parent']);
    }

    public function testSelectLayoutChoosesBWhenParentNotWritable(): void
    {
        $parent = $this->tempDir . '/parent2';
        $docRoot = $parent . '/public';
        mkdir($docRoot, 0755, true);

        $result = \bootstrapSelectLayout($docRoot, static fn (string $dir): bool => false);

        $this->assertSame('B', $result['layout']);
        $this->assertNull($result['parent']);
    }

    public function testSelectLayoutChoosesBWhenDocRootHasNoDistinctParent(): void
    {
        // dirname('/') === '/' — the degenerate case where a doc root has
        // no distinct parent at all (bootstrap.php itself would never
        // legitimately end up here given the location check, but the
        // layout selector must not assume otherwise).
        $result = \bootstrapSelectLayout('/', static fn (string $dir): bool => true);

        $this->assertSame('B', $result['layout']);
    }

    public function testSelectLayoutChoosesBWhenParentIsASystemRoot(): void
    {
        $systemRoot = $this->tempDir . '/fakesysroot';
        foreach (['etc', 'usr', 'bin', 'var'] as $marker) {
            mkdir($systemRoot . '/' . $marker, 0755, true);
        }
        $docRoot = $systemRoot . '/public';
        mkdir($docRoot, 0755, true);

        $result = \bootstrapSelectLayout($docRoot, static fn (string $dir): bool => true);

        $this->assertSame('B', $result['layout']);
        $this->assertStringContainsString('racine système', $result['reason']);
    }

    public function testLooksLikeSystemRootRequiresAllFourMarkers(): void
    {
        $partial = $this->tempDir . '/partial';
        mkdir($partial . '/etc', 0755, true);
        mkdir($partial . '/usr', 0755, true);

        $this->assertFalse(\bootstrapLooksLikeSystemRoot($partial));
    }

    // -------------------------------------------------------------------
    // Location detection
    // -------------------------------------------------------------------

    public function testCheckLocationPassesAtRoot(): void
    {
        $result = \bootstrapCheckLocation(['SCRIPT_NAME' => '/bootstrap.php']);
        $this->assertTrue($result['ok']);
    }

    public function testCheckLocationFailsInSubfolder(): void
    {
        $result = \bootstrapCheckLocation(['SCRIPT_NAME' => '/install/bootstrap.php']);
        $this->assertFalse($result['ok']);
    }

    public function testCheckLocationDefaultsToRootWhenScriptNameAbsent(): void
    {
        $result = \bootstrapCheckLocation([]);
        $this->assertTrue($result['ok']);
    }

    public function testCheckLocationNeverConsultsDocumentRoot(): void
    {
        // A legitimately differing DOCUMENT_ROOT (real chroot: Apache sees
        // /var/www/example.be/htdocs, PHP sees /htdocs, both correct) must
        // never block — bootstrapCheckLocation doesn't even look at it.
        $result = \bootstrapCheckLocation([
            'SCRIPT_NAME' => '/bootstrap.php',
            'DOCUMENT_ROOT' => '/var/www/example.be/htdocs',
        ]);
        $this->assertTrue($result['ok']);
    }

    // -------------------------------------------------------------------
    // S1-S8 server-side checks — pass and fail
    // -------------------------------------------------------------------

    public function testS1PassesWithNonEmptyVersionFile(): void
    {
        file_put_contents($this->tempDir . '/VERSION', "1.2.3\n");
        $this->assertTrue(\bootstrapCheckS1($this->tempDir)['ok']);
    }

    public function testS1FailsWhenVersionMissing(): void
    {
        $this->assertFalse(\bootstrapCheckS1($this->tempDir)['ok']);
    }

    public function testS2PassesWhenVendorAutoloadPresent(): void
    {
        mkdir($this->tempDir . '/vendor', 0755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');
        $this->assertTrue(\bootstrapCheckS2($this->tempDir)['ok']);
    }

    public function testS2FailsOnVendorlessArtifact(): void
    {
        $this->assertFalse(\bootstrapCheckS2($this->tempDir)['ok']);
    }

    public function testS3PassesWhenIndexPhpPresent(): void
    {
        file_put_contents($this->tempDir . '/index.php', '<?php');
        $this->assertTrue(\bootstrapCheckS3($this->tempDir)['ok']);
    }

    public function testS3Fails(): void
    {
        $this->assertFalse(\bootstrapCheckS3($this->tempDir)['ok']);
    }

    public function testS4PassesWhenSchemaPresent(): void
    {
        mkdir($this->tempDir . '/schema', 0755, true);
        file_put_contents($this->tempDir . '/schema/core.sql', 'CREATE TABLE x;');
        $this->assertTrue(\bootstrapCheckS4($this->tempDir)['ok']);
    }

    public function testS4Fails(): void
    {
        $this->assertFalse(\bootstrapCheckS4($this->tempDir)['ok']);
    }

    public function testS5PassesWhenAllStorageSubdirsExist(): void
    {
        \bootstrapCreateStorageDirs($this->tempDir);
        $this->assertTrue(\bootstrapCheckS5($this->tempDir)['ok']);
    }

    public function testS5FailsWhenAStorageSubdirIsMissing(): void
    {
        mkdir($this->tempDir . '/storage/keys', 0755, true);
        $this->assertFalse(\bootstrapCheckS5($this->tempDir)['ok']);
    }

    public function testS6PassesWhenKeysDirWritable(): void
    {
        mkdir($this->tempDir . '/storage/keys', 0755, true);
        $this->assertTrue(\bootstrapCheckS6($this->tempDir)['ok']);
    }

    public function testS6FailsWhenKeysDirMissing(): void
    {
        $this->assertFalse(\bootstrapCheckS6($this->tempDir)['ok']);
    }

    public function testS7PassesWhenArtifactHasNoRootHtaccess(): void
    {
        $this->assertTrue(\bootstrapCheckS7($this->tempDir)['ok']);
    }

    public function testS7FailsWhenArtifactShipsARootHtaccess(): void
    {
        file_put_contents($this->tempDir . '/.htaccess', 'deny all');
        $this->assertFalse(\bootstrapCheckS7($this->tempDir)['ok']);
    }

    public function testS8PassesWhenTempDirRemoved(): void
    {
        $this->assertTrue(\bootstrapCheckS8($this->tempDir . '/nonexistent-temp')['ok']);
    }

    public function testS8FailsWhenTempDirStillExists(): void
    {
        mkdir($this->tempDir . '/leftover-temp', 0755, true);
        $this->assertFalse(\bootstrapCheckS8($this->tempDir . '/leftover-temp')['ok']);
    }

    // -------------------------------------------------------------------
    // B1-B8 / F1 evaluation logic — "not readable" semantics
    // -------------------------------------------------------------------

    public function testControlProbePassesOnExactBodyMatch(): void
    {
        $this->assertTrue(\bootstrapEvaluateControlProbe(200, 'abc', 'abc'));
    }

    public function testControlProbeFailsOnMismatchOrNon200(): void
    {
        $this->assertFalse(\bootstrapEvaluateControlProbe(200, 'wrong', 'abc'));
        $this->assertFalse(\bootstrapEvaluateControlProbe(404, 'abc', 'abc'));
    }

    public function testPhpExecutionProbePassesOnEmptyBody(): void
    {
        $this->assertTrue(\bootstrapEvaluatePhpExecutionProbe(200, ''));
        $this->assertTrue(\bootstrapEvaluatePhpExecutionProbe(200, "  \n"));
    }

    public function testPhpExecutionProbeFailsWhenSourceLeaksAsPlaintext(): void
    {
        $this->assertFalse(\bootstrapEvaluatePhpExecutionProbe(200, "<?php /* TOKEN: abc */\n"));
    }

    /**
     * "not readable" means 403, 404, or a 200 whose body is an error page
     * rather than the probe content — never status alone.
     */
    public function testProtectionProbeTreats403And404AsProtected(): void
    {
        $this->assertTrue(\bootstrapEvaluateProtectionProbe(403, '', 'secret'));
        $this->assertTrue(\bootstrapEvaluateProtectionProbe(404, '', 'secret'));
    }

    public function testProtectionProbeTreats200WithDifferentBodyAsProtected(): void
    {
        $this->assertTrue(\bootstrapEvaluateProtectionProbe(200, '<html>Not Found</html>', 'secret-content'));
    }

    public function testProtectionProbeTreats200WithMatchingBodyAsExposed(): void
    {
        $this->assertFalse(\bootstrapEvaluateProtectionProbe(200, 'secret-content', 'secret-content'));
    }

    public function testProtectionProbeTreatsUnexpectedStatusAsNotProven(): void
    {
        $this->assertFalse(\bootstrapEvaluateProtectionProbe(500, '', 'secret'));
    }

    public function testNoDirectoryListingPassesOn403Or404(): void
    {
        $this->assertTrue(\bootstrapEvaluateNoDirectoryListing(403, ''));
        $this->assertTrue(\bootstrapEvaluateNoDirectoryListing(404, ''));
    }

    public function testNoDirectoryListingFailsOnApacheAutoindex(): void
    {
        $this->assertFalse(\bootstrapEvaluateNoDirectoryListing(200, '<title>Index of /storage/</title>'));
    }

    public function testFunctionalProbePassesWhenMarkerPresent(): void
    {
        $this->assertTrue(\bootstrapEvaluateFunctionalProbe(200, '<div id="scoutmagic-setup-wizard">', 'id="scoutmagic-setup-wizard"'));
    }

    public function testFunctionalProbeFailsWhenMarkerAbsent(): void
    {
        $this->assertFalse(\bootstrapEvaluateFunctionalProbe(200, '<div>Something else</div>', 'id="scoutmagic-setup-wizard"'));
    }

    // -------------------------------------------------------------------
    // Full gate report evaluation — B1 invalidates, B2 aborts, B4/B3
    // independence, and whole-tree rollback
    // -------------------------------------------------------------------

    public function testGateReportAbortsAndRollsBackWhenB1Fails(): void
    {
        $state = $this->installedFixtureState();
        $state['probes'] = [
            ['id' => 'B1', 'kind' => 'control', 'url' => '/control-x.txt', 'expected' => 'expected-content', 'file' => null],
        ];

        $result = \bootstrapEvaluateGateReport($this->tempDir, $state, [
            ['id' => 'B1', 'status' => 200, 'body' => 'not-the-expected-content'],
        ]);

        $this->assertFalse($result['gate_passed']);
        $this->assertSame('B1', $result['gate_aborted_at']);
        $this->assertFileDoesNotExist($this->tempDir . '/index.php');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/storage');
    }

    public function testGateReportAbortsWhenB2FailsEvenIfEverythingElsePasses(): void
    {
        $state = $this->installedFixtureState();
        $state['probes'] = [
            ['id' => 'B1', 'kind' => 'control', 'url' => '/c.txt', 'expected' => 'ok', 'file' => null],
            ['id' => 'B2', 'kind' => 'php_exec', 'url' => '/token.php', 'expected' => '', 'file' => null],
            ['id' => 'F1', 'kind' => 'functional', 'url' => '/', 'expected' => 'id="scoutmagic-setup-wizard"', 'file' => null],
        ];

        $result = \bootstrapEvaluateGateReport($this->tempDir, $state, [
            ['id' => 'B1', 'status' => 200, 'body' => 'ok'],
            // token.php served as source instead of executing — catastrophic.
            ['id' => 'B2', 'status' => 200, 'body' => "<?php /* TOKEN: abc */\n"],
            ['id' => 'F1', 'status' => 200, 'body' => '<div id="scoutmagic-setup-wizard">'],
        ]);

        $this->assertFalse($result['gate_passed']);
        $this->assertSame('B2', $result['gate_aborted_at']);
        $this->assertFileDoesNotExist($this->tempDir . '/index.php');
    }

    public function testGateReportAbortsWhenB4FailsWhileB3Passes(): void
    {
        $state = $this->installedFixtureState();
        $state['probes'] = [
            ['id' => 'B1', 'kind' => 'control', 'url' => '/c.txt', 'expected' => 'ok', 'file' => null],
            ['id' => 'B2', 'kind' => 'php_exec', 'url' => '/token.php', 'expected' => '', 'file' => null],
            ['id' => 'B3', 'kind' => 'protection', 'url' => '/storage/keys/canary.txt', 'expected' => 'secret-b3', 'file' => null],
            ['id' => 'B4', 'kind' => 'protection', 'url' => '/storage/gatecheck/canary.txt', 'expected' => 'secret-b4', 'file' => null],
            ['id' => 'F1', 'kind' => 'functional', 'url' => '/', 'expected' => 'id="scoutmagic-setup-wizard"', 'file' => null],
        ];

        $result = \bootstrapEvaluateGateReport($this->tempDir, $state, [
            ['id' => 'B1', 'status' => 200, 'body' => 'ok'],
            ['id' => 'B2', 'status' => 200, 'body' => ''],
            ['id' => 'B3', 'status' => 403, 'body' => ''],                    // protected — B3 passes
            ['id' => 'B4', 'status' => 200, 'body' => 'secret-b4'],           // EXPOSED — B4 fails
            ['id' => 'F1', 'status' => 200, 'body' => '<div id="scoutmagic-setup-wizard">'],
        ]);

        $this->assertFalse($result['gate_passed']);
        $b4 = array_values(array_filter($result['b_checks'], static fn (array $c): bool => $c['id'] === 'B4'))[0];
        $b3 = array_values(array_filter($result['b_checks'], static fn (array $c): bool => $c['id'] === 'B3'))[0];
        $this->assertFalse($b4['ok']);
        $this->assertTrue($b3['ok']);
        // B4 failing while B3 passes still aborts the whole install.
        $this->assertFileDoesNotExist($this->tempDir . '/index.php');
    }

    public function testGateReportPassesAndWritesTokenAllowedOnlyAfter(): void
    {
        $state = $this->installedFixtureState();
        $state['probes'] = [
            ['id' => 'B1', 'kind' => 'control', 'url' => '/c.txt', 'expected' => 'ok', 'file' => null],
            ['id' => 'B2', 'kind' => 'php_exec', 'url' => '/token.php', 'expected' => '', 'file' => null],
            ['id' => 'F1', 'kind' => 'functional', 'url' => '/', 'expected' => 'id="scoutmagic-setup-wizard"', 'file' => null],
        ];

        $result = \bootstrapEvaluateGateReport($this->tempDir, $state, [
            ['id' => 'B1', 'status' => 200, 'body' => 'ok'],
            ['id' => 'B2', 'status' => 200, 'body' => ''],
            ['id' => 'F1', 'status' => 200, 'body' => '<div id="scoutmagic-setup-wizard">'],
        ]);

        $this->assertTrue($result['gate_passed']);
        $this->assertFileExists($this->tempDir . '/index.php');

        $written = \bootstrapStepToken($this->tempDir, $result);
        $this->assertTrue($written['token_written']);
        $this->assertFileExists($this->tempDir . '/token.php');
        $this->assertStringContainsString('TOKEN:', (string) file_get_contents($this->tempDir . '/token.php'));
    }

    public function testStepTokenRefusesBeforeGatePasses(): void
    {
        $this->expectException(RuntimeException::class);
        \bootstrapStepToken($this->tempDir, ['gate_passed' => false]);
    }

    // -------------------------------------------------------------------
    // ?action=abort recovery — the browser can't tell "step actually
    // failed" from "response unparseable"; abort must roll back from
    // whatever was last durably written to .bootstrap-state.php and
    // leave the install target clean, regardless of which case it was.
    // bootstrapHandleAbortRequest() calls bootstrapSendJson()
    // internally, which clears every open buffer level including any
    // this test opens to capture output — same reason bootstrapSendJson
    // itself is tested via a subprocess.
    // -------------------------------------------------------------------

    public function testAbortRequestRollsBackInstalledFilesClearsLockAndState(): void
    {
        file_put_contents($this->tempDir . '/index.php', '<?php // installed');
        mkdir($this->tempDir . '/core', 0755, true);
        file_put_contents($this->tempDir . '/VERSION', "1.0.0\n");
        file_put_contents($this->tempDir . '/.bootstrap.lock', (string) getmypid());

        $stateFile = $this->tempDir . '/.bootstrap-state.php';
        \bootstrapWriteState($stateFile, [
            'layout' => 'B',
            'install_target' => $this->tempDir,
            'installed_entries' => ['index.php', 'core'],
            'temp_dir' => null,
        ]);

        $script = <<<'PHP'
define('BOOTSTRAP_TEST', true);
require %s;
bootstrapHandleAbortRequest(%s, %s . '/.bootstrap-state.php');
PHP;
        $decoded = json_decode($this->lastLineOfSubprocessWithDocRoot($script), true);

        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok'] ?? false);
        $this->assertFileDoesNotExist($this->tempDir . '/index.php');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/core');
        $this->assertFileDoesNotExist($this->tempDir . '/VERSION');
        $this->assertFileDoesNotExist($this->tempDir . '/.bootstrap.lock');
        $this->assertFileDoesNotExist($stateFile);
    }

    public function testAbortRequestSucceedsEvenWithNoStateFileAtAll(): void
    {
        // A genuinely fresh/never-started attempt — abort must still
        // respond cleanly rather than erroring, since the client can't
        // always know whether anything was ever written.
        $stateFile = $this->tempDir . '/.bootstrap-state.php';
        $script = <<<'PHP'
define('BOOTSTRAP_TEST', true);
require %s;
bootstrapHandleAbortRequest(%s, %s . '/.bootstrap-state.php');
PHP;
        $decoded = json_decode($this->lastLineOfSubprocessWithDocRoot($script), true);

        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok'] ?? false);
    }

    /**
     * Regression: a retry that fails at step 1 ("already installed",
     * bootstrapStepPreflight()'s own guard) must still roll back files
     * an EARLIER request's step 6 already copied, even though the
     * CURRENTLY failing step number (1) is nowhere near 6-8. Before this
     * fix, the catch block only rolled back when $step was itself in
     * [6,8], leaving the install permanently stuck with no recovery path
     * once the operator retried after clearing the lock.
     */
    public function testStepOneFailureStillRollsBackFilesFromAnEarlierSuccessfulStepSix(): void
    {
        file_put_contents($this->tempDir . '/index.php', '<?php // installed');
        mkdir($this->tempDir . '/core', 0755, true);
        file_put_contents($this->tempDir . '/VERSION', "1.0.0\n");

        $stateFile = $this->tempDir . '/.bootstrap-state.php';
        \bootstrapWriteState($stateFile, [
            'layout' => 'B',
            'install_target' => $this->tempDir,
            'installed_entries' => ['index.php', 'core'],
            'temp_dir' => null,
        ]);

        // No lock file: simulates the operator having already cleared it
        // (via the 10-minute expiry or the manual-remedy hint) before
        // retrying — bootstrapHandleStepRequest's step===1 branch
        // re-acquires it fresh, then bootstrapStepPreflight() throws
        // "already installed" against the files still on disk above.
        //
        // bootstrapHandleStepRequest() reads the step number from
        // php://input, which the CLI SAPI never populates (confirmed:
        // `php -r` and `php -f script < body` both return an empty
        // string for it regardless of stdin) — a real HTTP SAPI is
        // required, hence php's own built-in web server rather than a
        // plain subprocess.
        $decoded = json_decode($this->postStepViaHttpServer($this->tempDir, $stateFile, 1), true);

        $this->assertIsArray($decoded, 'response body must be valid JSON');
        $this->assertTrue($decoded['done'] ?? false);
        $this->assertStringContainsString('déjà une installation', (string) ($decoded['error'] ?? ''));
        $this->assertFileDoesNotExist($this->tempDir . '/index.php');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/core');
        $this->assertFileDoesNotExist($this->tempDir . '/VERSION');
    }

    /**
     * Starts php -S serving a tiny router that calls
     * bootstrapHandleStepRequest() for real, POSTs {"step": $step} to
     * it, and returns the raw response body.
     */
    private function postStepViaHttpServer(string $docRoot, string $stateFile, int $step): string
    {
        $router = $this->tempDir . '-router.php';
        file_put_contents($router, sprintf(
            "<?php\ndefine('BOOTSTRAP_TEST', true);\nrequire %s;\nbootstrapHandleStepRequest(%s, %s);\n",
            var_export(dirname(__DIR__, 2) . '/bootstrap/bootstrap.php', true),
            var_export($docRoot, true),
            var_export($stateFile, true)
        ));

        $port = random_int(20000, 60000);
        $process = proc_open(
            ['php', '-S', "127.0.0.1:{$port}", $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'failed to start php -S for test');

        try {
            $connected = false;
            for ($i = 0; $i < 50; $i++) {
                $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($conn !== false) {
                    fclose($conn);
                    $connected = true;
                    break;
                }
                usleep(50000);
            }
            $this->assertTrue($connected, 'php -S never became ready');

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => json_encode(['step' => $step]),
                    'ignore_errors' => true,
                ],
            ]);

            return (string) file_get_contents("http://127.0.0.1:{$port}/", false, $context);
        } finally {
            proc_terminate($process);
            proc_close($process);
            @unlink($router);
        }
    }

    /**
     * Same purpose as lastLineOfSubprocess() but for scripts that also
     * need the temp docRoot substituted in (twice, for the abort handler's
     * two path arguments) — %s placeholders filled in order: bootstrap.php
     * path, then $this->tempDir twice.
     */
    private function lastLineOfSubprocessWithDocRoot(string $script): string
    {
        $bootstrapPath = var_export(dirname(__DIR__, 2) . '/bootstrap/bootstrap.php', true);
        $docRoot = var_export($this->tempDir, true);
        $filled = sprintf($script, $bootstrapPath, $docRoot, $docRoot);
        $output = (string) shell_exec('php -r ' . escapeshellarg($filled) . ' 2>&1');
        $lines = array_values(array_filter(explode("\n", trim($output)), static fn (string $l): bool => trim($l) !== ''));

        return $lines === [] ? '' : end($lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function installedFixtureState(): array
    {
        file_put_contents($this->tempDir . '/index.php', '<?php // installed');
        mkdir($this->tempDir . '/storage/keys', 0755, true);
        file_put_contents($this->tempDir . '/VERSION', "1.0.0\n");

        return [
            'layout' => 'B',
            'install_target' => $this->tempDir,
            'installed_entries' => ['index.php'],
            'temp_dir' => null,
        ];
    }

    // -------------------------------------------------------------------
    // Graceful degradation — warns and proceeds, never blocks
    // -------------------------------------------------------------------

    public function testDiskSpaceCheckDegradesGracefullyWhenProbeFails(): void
    {
        $result = \bootstrapCheckDiskSpace($this->tempDir, 1000, static fn (string $dir) => false);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['degraded']);
    }

    public function testDiskSpaceCheckDegradesWhenArtifactSizeUnknown(): void
    {
        $result = \bootstrapCheckDiskSpace($this->tempDir, 0, static fn (string $dir) => 999999999);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['degraded']);
    }

    public function testDiskSpaceCheckFailsWhenGenuinelyInsufficient(): void
    {
        $result = \bootstrapCheckDiskSpace($this->tempDir, 1000, static fn (string $dir) => 100);
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['degraded']);
    }

    public function testDiskSpaceCheckPassesWithThreeTimesMargin(): void
    {
        $result = \bootstrapCheckDiskSpace($this->tempDir, 1000, static fn (string $dir) => 3000);
        $this->assertTrue($result['ok']);
    }

    public function testGatherEnvironmentInfoNeverThrowsAndAlwaysReturnsData(): void
    {
        $info = \bootstrapGatherEnvironmentInfo();
        $this->assertArrayHasKey('server_software', $info);
        $this->assertArrayHasKey('posix_available', $info);
        $this->assertArrayHasKey('open_basedir', $info);
    }

    // -------------------------------------------------------------------
    // Download retry + rate-limit detection
    // -------------------------------------------------------------------

    public function testDownloadWithRetrySucceedsAfterTransientFailures(): void
    {
        $attempts = 0;
        $downloader = function (string $url, string $dest) use (&$attempts): void {
            $attempts++;
            if ($attempts < 3) {
                throw new RuntimeException('transient failure');
            }
            file_put_contents($dest, 'PK-fake-zip-bytes');
        };

        \bootstrapDownloadWithRetry('https://example.com/a.zip', $this->tempDir . '/a.zip', $downloader);

        $this->assertSame(3, $attempts);
        $this->assertFileExists($this->tempDir . '/a.zip');
    }

    public function testDownloadWithRetryGivesUpAfterThreeAttempts(): void
    {
        $attempts = 0;
        $downloader = function () use (&$attempts): void {
            $attempts++;
            throw new RuntimeException('always fails');
        };

        $this->expectException(RuntimeException::class);
        try {
            \bootstrapDownloadWithRetry('https://example.com/a.zip', $this->tempDir . '/a.zip', $downloader);
        } finally {
            $this->assertSame(3, $attempts);
        }
    }

    public function testFetchLatestReleaseDetectsRateLimit(): void
    {
        $httpGet = static fn (string $url, array $headers): array => [
            'status' => 403,
            'headers' => ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) (time() + 60)],
            'body' => '',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Ll]imite/');
        \bootstrapFetchLatestRelease($httpGet);
    }

    public function testFetchLatestReleaseThrowsOn404(): void
    {
        $httpGet = static fn (string $url, array $headers): array => ['status' => 404, 'headers' => [], 'body' => ''];

        $this->expectException(RuntimeException::class);
        \bootstrapFetchLatestRelease($httpGet);
    }

    public function testFetchLatestReleaseRetriesOnServerErrorThenSucceeds(): void
    {
        $calls = 0;
        $httpGet = function (string $url, array $headers) use (&$calls): array {
            $calls++;
            if ($calls < 2) {
                return ['status' => 500, 'headers' => [], 'body' => ''];
            }
            return ['status' => 200, 'headers' => [], 'body' => json_encode(['tag_name' => 'v1.2.3', 'assets' => []])];
        };

        $release = \bootstrapFetchLatestRelease($httpGet);
        $this->assertSame('v1.2.3', $release['tag_name']);
        $this->assertSame(2, $calls);
    }

    // -------------------------------------------------------------------
    // Artifact verification — rejects a vendor-less tree
    // -------------------------------------------------------------------

    public function testVerifyArtifactRejectsMissingVendor(): void
    {
        file_put_contents($this->tempDir . '/x', '');
        mkdir($this->tempDir . '/public', 0755, true);
        file_put_contents($this->tempDir . '/public/index.php', '<?php');
        mkdir($this->tempDir . '/schema', 0755, true);
        file_put_contents($this->tempDir . '/schema/core.sql', 'CREATE TABLE x;');

        $result = \bootstrapVerifyArtifact($this->tempDir);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('vendor/autoload.php', $result['detail']);
    }

    public function testVerifyArtifactPassesWithAllRequiredEntries(): void
    {
        mkdir($this->tempDir . '/vendor', 0755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');
        mkdir($this->tempDir . '/public', 0755, true);
        file_put_contents($this->tempDir . '/public/index.php', '<?php');
        mkdir($this->tempDir . '/schema', 0755, true);
        file_put_contents($this->tempDir . '/schema/core.sql', 'CREATE TABLE x;');

        $this->assertTrue(\bootstrapVerifyArtifact($this->tempDir)['ok']);
    }

    // -------------------------------------------------------------------
    // Dotfile-preserving copy
    // -------------------------------------------------------------------

    public function testCopyTreePreservesDotfiles(): void
    {
        $source = $this->tempDir . '/source';
        $dest = $this->tempDir . '/dest';
        mkdir($source . '/public', 0755, true);
        file_put_contents($source . '/public/.htaccess', 'RewriteEngine On');
        file_put_contents($source . '/public/.user.ini', 'upload_max_filesize=2048M');
        file_put_contents($source . '/public/index.php', '<?php');

        \bootstrapCopyTree($source, $dest);

        $this->assertFileExists($dest . '/public/.htaccess');
        $this->assertFileExists($dest . '/public/.user.ini');
        $this->assertFileExists($dest . '/public/index.php');
    }

    public function testCopyTreeExcludesDeclaredTopLevelEntries(): void
    {
        $source = $this->tempDir . '/source2';
        $dest = $this->tempDir . '/dest2';
        mkdir($source . '/storage', 0755, true);
        file_put_contents($source . '/VERSION', "1.0.0\n");
        file_put_contents($source . '/core-file.php', '<?php');

        $copied = \bootstrapCopyTree($source, $dest, ['storage', 'VERSION']);

        $this->assertDirectoryDoesNotExist($dest . '/storage');
        $this->assertFileDoesNotExist($dest . '/VERSION');
        $this->assertFileExists($dest . '/core-file.php');
        $this->assertSame(['core-file.php'], $copied);
    }

    // -------------------------------------------------------------------
    // config/app.php seeding — config/app.php is deliberately excluded
    // from every release artifact (never overwrite an existing prod
    // config on update), but a genuinely fresh install has none to
    // protect, and Core\Config\AppConfig throws on every single request
    // without one — bootstrap.php is the only place positioned to seed
    // it from the shipped config/app.php.dist template.
    // -------------------------------------------------------------------

    public function testSeedAppConfigCopiesDistWhenRealFileAbsent(): void
    {
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/app.php.dist', "<?php\nreturn ['debug' => false];\n");

        \bootstrapSeedAppConfig($this->tempDir);

        $this->assertFileExists($this->tempDir . '/config/app.php');
        $this->assertStringContainsString('debug', (string) file_get_contents($this->tempDir . '/config/app.php'));
    }

    public function testSeedAppConfigNeverOverwritesAnExistingRealFile(): void
    {
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/app.php.dist', "<?php\nreturn ['debug' => false];\n");
        file_put_contents($this->tempDir . '/config/app.php', "<?php\nreturn ['debug' => true, 'custom' => 'kept'];\n");

        \bootstrapSeedAppConfig($this->tempDir);

        $this->assertStringContainsString('kept', (string) file_get_contents($this->tempDir . '/config/app.php'));
    }

    public function testSeedAppConfigIsANoOpWhenNoDistTemplateShipped(): void
    {
        mkdir($this->tempDir . '/config', 0755, true);

        \bootstrapSeedAppConfig($this->tempDir);

        $this->assertFileDoesNotExist($this->tempDir . '/config/app.php');
    }

    // -------------------------------------------------------------------
    // State persistence + token content + version write
    // -------------------------------------------------------------------

    public function testStateRoundTripsThroughPhpCommentWrapper(): void
    {
        $path = $this->tempDir . '/.bootstrap-state.php';
        \bootstrapWriteState($path, ['label' => 'Test', 'percent' => 42]);

        $this->assertStringStartsWith('<?php', (string) file_get_contents($path));
        $this->assertSame(['label' => 'Test', 'percent' => 42], \bootstrapReadState($path));
    }

    public function testReadStateReturnsEmptyArrayWhenFileAbsent(): void
    {
        $this->assertSame([], \bootstrapReadState($this->tempDir . '/nonexistent.php'));
    }

    public function testWriteVersionMatchesVersionFileFormat(): void
    {
        \bootstrapWriteVersion($this->tempDir, '2.5.0');
        $this->assertSame("2.5.0\n", file_get_contents($this->tempDir . '/VERSION'));
    }

    public function testTokenFileContentIsAWellFormedPhpCommentOnly(): void
    {
        $token = \bootstrapGenerateToken();
        $this->assertSame(64, strlen($token));
        $content = \bootstrapTokenFileContent($token);

        $this->assertStringStartsWith('<?php', $content);
        $this->assertStringContainsString("TOKEN: {$token}", $content);
    }

    public function testCreateStorageDirsCreatesExactlyTheFiveDeclaredSubdirs(): void
    {
        $created = \bootstrapCreateStorageDirs($this->tempDir);
        sort($created);
        $this->assertSame(['config', 'core', 'keys', 'modules', 'temp'], $created);
        foreach ($created as $sub) {
            $this->assertDirectoryExists($this->tempDir . '/storage/' . $sub);
        }
    }

    public function testAlreadyInstalledDetectsVersionFile(): void
    {
        $this->assertFalse(\bootstrapAlreadyInstalled($this->tempDir));
        file_put_contents($this->tempDir . '/VERSION', "1.0.0\n");
        $this->assertTrue(\bootstrapAlreadyInstalled($this->tempDir));
    }

    public function testAlreadyInstalledDetectsCoreDirectory(): void
    {
        mkdir($this->tempDir . '/core', 0755, true);
        $this->assertTrue(\bootstrapAlreadyInstalled($this->tempDir));
    }

    // -------------------------------------------------------------------
    // Layout B .htaccess content — the security-critical belt-and-braces
    // -------------------------------------------------------------------

    public function testHtaccessContentDeniesTheCliSchedulerEntryPoint(): void
    {
        // cron.php lives in the document root under Layout B and exists on
        // disk, so it would otherwise be served/executed on GET /cron.php.
        // The CLI SAPI guard inside cron.php is the load-bearing control;
        // this is the .htaccess belt to it.
        $content = \bootstrapHtaccessContent();
        $this->assertMatchesRegularExpression(
            '/<Files "cron\\.php">\s*Require all denied\s*<\/Files>/',
            $content,
            'the generated .htaccess must deny direct web access to cron.php'
        );
    }

    public function testHtaccessContentDeniesInternalDirectoriesBeforeTheRewrite(): void
    {
        $content = \bootstrapHtaccessContent();
        $denyPos = strpos($content, 'storage|core|modules');
        $rewritePos = strpos($content, 'public/$1');

        $this->assertIsInt($denyPos);
        $this->assertIsInt($rewritePos);
        $this->assertLessThan($rewritePos, $denyPos, 'the internal-directory deny rule must come before the rewrite-to-public/ rule');
        $this->assertStringContainsString('vendor', $content);
        $this->assertStringContainsString('key|enc|sql|log|sqlite', $content);
    }

    /**
     * Regression: "config" is both a protected internal directory
     * (config/app.php holds the encryption key) AND the app's own admin
     * route namespace (/config/maintenance, /config/notifications, etc. —
     * see MenuBuilder::MENU_CONFIGURATION page registrations). An earlier
     * version of this rule was an ungated string-prefix match, which 403'd
     * every one of those legitimate admin routes outright, since they
     * never correspond to a real file/directory on disk — only
     * config/app.php itself does. Observed in production: every
     * "Configuration" admin page returning the host's raw Apache 403 page
     * on a Layout B (single-tree) install.
     */
    public function testHtaccessContentOnlyDeniesInternalDirectoriesThatActuallyExistOnDisk(): void
    {
        $content = \bootstrapHtaccessContent();
        $denyRulePos = strpos($content, 'RewriteRule ^(storage|core|modules|config|schema|vendor|tests|scripts)(/|$) - [F,L]');
        $this->assertIsInt($denyRulePos, 'the internal-directory deny rule must exist verbatim');

        $precedingLines = substr($content, 0, $denyRulePos);
        $lastTwoConditions = array_values(array_slice(array_filter(explode("\n", trim($precedingLines))), -2));

        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} -f', $lastTwoConditions[0] ?? '');
        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} -d', $lastTwoConditions[1] ?? '');
    }

    /**
     * Regression: observed in the wild on OVH-style mutualized hosting,
     * which ships a placeholder page (e.g. default_index.html) and lists
     * it ahead of index.php in its own vhost-level DirectoryIndex. Without
     * an explicit DirectoryIndex override here, mod_dir's directory-index
     * resolution for the bare "/" request can win against this
     * .htaccess's own rewrite rules depending on the host's Apache module
     * hook ordering, serving the host's placeholder instead of ever
     * reaching the catch-all rule that routes to the index.php stub.
     */
    public function testHtaccessContentForcesDirectoryIndexToTheStub(): void
    {
        $content = \bootstrapHtaccessContent();
        $directiveOpos = strpos($content, 'DirectoryIndex index.php');
        $engineOnPos = strpos($content, 'RewriteEngine On');
        $catchAllPos = strpos($content, 'RewriteRule ^ index.php [L]');

        $this->assertIsInt($directiveOpos, 'DirectoryIndex must be forced to the index.php stub');
        $this->assertGreaterThan($engineOnPos, $directiveOpos);
        $this->assertLessThan($catchAllPos, $directiveOpos);
    }

    /**
     * Regression: an earlier version rewrote PHP execution straight to
     * public/index.php via a two-hop chain across directories (root
     * .htaccess → public/'s own .htaccess re-triggering a second
     * rewrite) — a well-documented trap on some Apache + PHP-FPM/FastCGI
     * setups, where SCRIPT_FILENAME is computed from the request's
     * original path rather than the rewritten target and the request
     * fails with a raw "File not found." even though the file genuinely
     * exists. PHP execution must only ever be routed to a same-directory
     * file (the index.php stub); only static assets may cross directories.
     */
    public function testHtaccessContentNeverRewritesPhpExecutionAcrossDirectories(): void
    {
        $content = \bootstrapHtaccessContent();

        $this->assertStringNotContainsString('public/index.php', $content, 'must never rewrite PHP execution straight to public/index.php across a directory boundary');
        $this->assertStringContainsString('RewriteRule ^ index.php [L]', $content, 'the catch-all must route to the same-directory stub');
        // The static-asset rewrite must exclude .php requests, or a direct
        // request for e.g. /index.php itself could still be misrouted
        // across directories to a PHP file.
        $this->assertStringContainsString('!\.php$', $content);
        // A real file/dir sitting directly in the document root (the gate's
        // own control-*.txt canary, token.php) must be served/executed
        // as-is, never swept into the catch-all — otherwise the B1/B2 gate
        // probes could never observe them correctly.
        $this->assertStringContainsString('%{REQUEST_FILENAME} -f', $content);
    }

    /**
     * Regression: the "serve a real docroot file as-is" rule must check
     * -f only, never -d. The document root itself is always a directory,
     * so an -f-or-d condition would match the root path "/" too and
     * serve it "as-is" — silently depending on the host's DirectoryIndex
     * configuration listing index.php (true almost everywhere, but never
     * something this file may assume). Without this, the root path must
     * fall through to the explicit catch-all instead.
     */
    public function testHtaccessRealFileRuleNeverMatchesOnDirectoryAlone(): void
    {
        $content = \bootstrapHtaccessContent();

        // Scoped to the "serve a real docroot file as-is" rule specifically
        // (the block ending in `RewriteRule ^ - [L]`) — the internal-
        // directory deny rule earlier in the file legitimately uses -d too
        // (see testHtaccessContentOnlyDeniesInternalDirectoriesThatActually
        // ExistOnDisk), just for a different purpose: matching a real
        // protected directory, not the docroot itself.
        $realFileRuleEnd = strpos($content, "RewriteRule ^ - [L]\n");
        $this->assertIsInt($realFileRuleEnd, 'the real-file rule must exist verbatim');
        $realFileRuleBlock = substr($content, 0, $realFileRuleEnd);
        $realFileConditionStart = strrpos($realFileRuleBlock, 'RewriteCond');
        $this->assertIsInt($realFileConditionStart);

        $this->assertStringNotContainsString('%{REQUEST_FILENAME} -d', substr($content, $realFileConditionStart, $realFileRuleEnd - $realFileConditionStart));
    }

    public function testIndexStubRequiresThePublicFrontControllerFromItsOwnDirectory(): void
    {
        $content = \bootstrapIndexStubContent();

        $this->assertStringStartsWith('<?php', $content);
        $this->assertStringContainsString("require __DIR__ . '/public/index.php';", $content);
    }

    /**
     * Regression: the post-install "continue" step used to redirect to
     * "/" and rely on the app's own not-initialized handler to bounce that
     * to /setup — an extra hop through the exact bare-root request this
     * file's own .htaccess hardening (see the DirectoryIndex tests above)
     * exists because some hosts can intercept before a PHP request is
     * ever made. Going straight to /setup avoids that hop entirely.
     */
    public function testPostInstallRedirectsExplicitlyToSetupNotBareRoot(): void
    {
        ob_start();
        \bootstrapRenderUi($this->tempDir, $this->tempDir . '/.bootstrap-state.php');
        $html = ob_get_clean();

        $this->assertStringContainsString("window.location.href = '/setup'", $html);
        $this->assertStringNotContainsString("window.location.href = '/';", $html);
    }

    // -------------------------------------------------------------------
    // Error sanitization — no absolute paths leak to the client
    // -------------------------------------------------------------------

    public function testSanitizeErrorStripsDocRootAndParentFromMessage(): void
    {
        $docRoot = '/home/user/site/public';
        $message = "Impossible d'écrire {$docRoot}/storage/keys/master.key";

        $sanitized = \bootstrapSanitizeErrorForClient($message, $docRoot);

        $this->assertStringNotContainsString('/home/user/site', $sanitized);
    }

    // -------------------------------------------------------------------
    // Rollback removes the entire installed tree
    // -------------------------------------------------------------------

    public function testRollbackRemovesInstalledEntriesStorageVersionAndHtaccess(): void
    {
        file_put_contents($this->tempDir . '/index.php', '<?php');
        mkdir($this->tempDir . '/core', 0755, true);
        mkdir($this->tempDir . '/storage/keys', 0755, true);
        file_put_contents($this->tempDir . '/VERSION', "1.0.0\n");
        file_put_contents($this->tempDir . '/.htaccess', 'deny');
        file_put_contents($this->tempDir . '/token.php', '<?php /* gate probe */');

        \bootstrapRollbackInstall($this->tempDir, [
            'layout' => 'B',
            'install_target' => $this->tempDir,
            'installed_entries' => ['index.php', 'core'],
        ]);

        $this->assertFileDoesNotExist($this->tempDir . '/index.php');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/core');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/storage');
        $this->assertFileDoesNotExist($this->tempDir . '/VERSION');
        $this->assertFileDoesNotExist($this->tempDir . '/.htaccess');
        $this->assertFileDoesNotExist($this->tempDir . '/token.php');
    }

    /**
     * The real layout-B scenario, distinct from the test above: the
     * index.php stub is written directly at docRoot by
     * bootstrapStepInstall() itself (never via installed_entries, since
     * it has no counterpart in the extracted artifact at all) — rollback
     * must remove it via its own dedicated path or a rolled-back attempt
     * leaves an orphaned stub require()-ing a now-deleted public/index.php.
     */
    public function testRollbackRemovesTheIndexPhpStubWrittenOutsideInstalledEntries(): void
    {
        mkdir($this->tempDir . '/public', 0755, true);
        file_put_contents($this->tempDir . '/public/index.php', '<?php // real front controller');
        file_put_contents($this->tempDir . '/index.php', \bootstrapIndexStubContent());
        file_put_contents($this->tempDir . '/.htaccess', \bootstrapHtaccessContent());

        \bootstrapRollbackInstall($this->tempDir, [
            'layout' => 'B',
            'install_target' => $this->tempDir,
            'installed_entries' => ['public'],
        ]);

        $this->assertFileDoesNotExist($this->tempDir . '/index.php');
        $this->assertFileDoesNotExist($this->tempDir . '/.htaccess');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/public');
    }

    // -------------------------------------------------------------------
    // Public state stripping — internal-only fields never reach the client
    // -------------------------------------------------------------------

    public function testPublicStateStripsInternalFilesystemPaths(): void
    {
        $public = \bootstrapPublicState([
            'label' => 'Installation',
            'percent' => 50,
            'install_target' => '/secret/absolute/path',
            'source_root' => '/secret/absolute/path/extracted',
            'probes' => [['id' => 'B1', 'kind' => 'control', 'url' => '/c.txt', 'expected' => 'ok', 'file' => '/secret/absolute/path/c.txt']],
        ]);

        $this->assertArrayNotHasKey('install_target', $public);
        $this->assertArrayNotHasKey('source_root', $public);
        $this->assertArrayNotHasKey('file', $public['probes'][0]);
    }

    // -------------------------------------------------------------------
    // JSON output must survive stray output ahead of it (a PHP warning
    // from an edge case, display_errors on, etc.) — this is what broke a
    // live install: some prior write emitted a stray warning that landed
    // in what was supposed to be a pure JSON response body, and
    // response.json() failed client-side with an opaque parse error.
    // -------------------------------------------------------------------

    /**
     * bootstrapSendJson() clears every open output-buffer level via its
     * own while(ob_get_level()>0) loop — which means it also consumes any
     * buffer *this test* opens to capture its output, making the
     * assertion untestable in-process. A real PHP subprocess sidesteps
     * that entirely: its stdout is exactly what a real HTTP response body
     * would be.
     */
    public function testSendJsonDiscardsStrayOutputBufferedBeforeIt(): void
    {
        $script = <<<'PHP'
define('BOOTSTRAP_TEST', true);
require %s;
ob_start();
echo '<b>Warning</b>: mkdir(): File exists in bootstrap.php on line 123';
bootstrapSendJson(['ok' => true, 'label' => 'Stockage']);
PHP;
        $decoded = json_decode($this->lastLineOfSubprocess($script), true);
        $this->assertNotNull($decoded, 'response body must be valid JSON even with stray output buffered ahead of it');
        $this->assertSame(['ok' => true, 'label' => 'Stockage'], $decoded);
    }

    public function testSendJsonDiscardsMultipleNestedBufferedWrites(): void
    {
        $script = <<<'PHP'
define('BOOTSTRAP_TEST', true);
require %s;
ob_start();
echo 'first stray write';
ob_start();
echo 'second stray write, nested buffer';
bootstrapSendJson(['ok' => true]);
PHP;
        $this->assertSame(['ok' => true], json_decode($this->lastLineOfSubprocess($script), true));
    }

    /**
     * Runs $script (a %s-templated PHP -r body, %s filled with the
     * bootstrap.php path) in a real subprocess and returns its last
     * output line — bootstrapSendJson()'s echo is always the last thing
     * printed, and taking only that line sidesteps unrelated noise this
     * local environment's php CLI happens to print to stdout (e.g. a
     * duplicate-extension warning), which isn't part of what's being
     * tested here.
     */
    private function lastLineOfSubprocess(string $script): string
    {
        $bootstrapPath = var_export(dirname(__DIR__, 2) . '/bootstrap/bootstrap.php', true);
        $output = (string) shell_exec('php -r ' . escapeshellarg(sprintf($script, $bootstrapPath)) . ' 2>&1');
        $lines = array_values(array_filter(explode("\n", trim($output)), static fn (string $l): bool => trim($l) !== ''));

        return $lines === [] ? '' : end($lines);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
        }
        @rmdir($dir);
    }
}
