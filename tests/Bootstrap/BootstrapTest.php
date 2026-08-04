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
        $release = ['assets' => [['browser_download_url' => 'https://example.com/artifact.zip', 'size' => 1234]], 'zipball_url' => 'https://example.com/zip'];
        $result = \bootstrap_resolve_archive_url($release);

        $this->assertSame('https://example.com/artifact.zip', $result['url']);
        $this->assertSame(1234, $result['size']);
        $this->assertSame('asset', $result['source']);
    }

    public function testResolveArchiveUrlFallsBackToZipball(): void
    {
        $release = ['assets' => [], 'zipball_url' => 'https://example.com/zip'];
        $result = \bootstrap_resolve_archive_url($release);

        $this->assertSame('https://example.com/zip', $result['url']);
        $this->assertSame(0, $result['size']);
        $this->assertSame('zipball', $result['source']);
    }

    public function testResolveArchiveUrlThrowsWhenNeitherPresent(): void
    {
        $this->expectException(RuntimeException::class);
        \bootstrap_resolve_archive_url([]);
    }

    // -------------------------------------------------------------------
    // Archive root resolution + zip-slip rejection
    // -------------------------------------------------------------------

    public function testResolveArchiveRootUnwrapsZipballSingleTopLevelDir(): void
    {
        $extracted = $this->tempDir . '/extracted';
        mkdir($extracted . '/owner-repo-abc123', 0755, true);

        $this->assertSame($extracted . '/owner-repo-abc123', \bootstrap_resolve_archive_root($extracted, 'zipball'));
    }

    public function testResolveArchiveRootNeverUnwrapsReleaseAsset(): void
    {
        $extracted = $this->tempDir . '/extracted';
        mkdir($extracted . '/only-dir', 0755, true);

        // Even with a single top-level dir (coincidentally zipball-shaped),
        // a release asset must never be stripped — decided by source type,
        // never entry count.
        $this->assertSame($extracted, \bootstrap_resolve_archive_root($extracted, 'asset'));
    }

    public function testResolveArchiveRootLeavesMultiEntryZipballAlone(): void
    {
        $extracted = $this->tempDir . '/extracted';
        mkdir($extracted . '/core', 0755, true);
        mkdir($extracted . '/public', 0755, true);

        $this->assertSame($extracted, \bootstrap_resolve_archive_root($extracted, 'zipball'));
    }

    public function testIsZipSlipRejectsParentTraversal(): void
    {
        $this->assertTrue(\bootstrap_is_zip_slip('../../etc/passwd'));
        $this->assertTrue(\bootstrap_is_zip_slip('core/../../../etc/passwd'));
    }

    public function testIsZipSlipRejectsAbsolutePath(): void
    {
        $this->assertTrue(\bootstrap_is_zip_slip('/etc/passwd'));
    }

    public function testIsZipSlipRejectsWindowsDriveLetter(): void
    {
        $this->assertTrue(\bootstrap_is_zip_slip('C:\\Windows\\System32'));
    }

    public function testIsZipSlipAcceptsOrdinaryRelativeEntries(): void
    {
        $this->assertFalse(\bootstrap_is_zip_slip('core/Http/Controller/PageController.php'));
        $this->assertFalse(\bootstrap_is_zip_slip('public/index.php'));
    }

    // -------------------------------------------------------------------
    // Layout selection — the 4 scenarios
    // -------------------------------------------------------------------

    public function testSelectLayoutChoosesAWhenParentWritableAndContainsDocRoot(): void
    {
        $parent = $this->tempDir . '/parent';
        $docRoot = $parent . '/public';
        mkdir($docRoot, 0755, true);

        $result = \bootstrap_select_layout($docRoot, static fn (string $dir): bool => true);

        $this->assertSame('A', $result['layout']);
        $this->assertSame($parent, $result['parent']);
    }

    public function testSelectLayoutChoosesBWhenParentNotWritable(): void
    {
        $parent = $this->tempDir . '/parent2';
        $docRoot = $parent . '/public';
        mkdir($docRoot, 0755, true);

        $result = \bootstrap_select_layout($docRoot, static fn (string $dir): bool => false);

        $this->assertSame('B', $result['layout']);
        $this->assertNull($result['parent']);
    }

    public function testSelectLayoutChoosesBWhenDocRootHasNoDistinctParent(): void
    {
        // dirname('/') === '/' — the degenerate case where a doc root has
        // no distinct parent at all (bootstrap.php itself would never
        // legitimately end up here given the location check, but the
        // layout selector must not assume otherwise).
        $result = \bootstrap_select_layout('/', static fn (string $dir): bool => true);

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

        $result = \bootstrap_select_layout($docRoot, static fn (string $dir): bool => true);

        $this->assertSame('B', $result['layout']);
        $this->assertStringContainsString('racine système', $result['reason']);
    }

    public function testLooksLikeSystemRootRequiresAllFourMarkers(): void
    {
        $partial = $this->tempDir . '/partial';
        mkdir($partial . '/etc', 0755, true);
        mkdir($partial . '/usr', 0755, true);

        $this->assertFalse(\bootstrap_looks_like_system_root($partial));
    }

    // -------------------------------------------------------------------
    // Location detection
    // -------------------------------------------------------------------

    public function testCheckLocationPassesAtRoot(): void
    {
        $result = \bootstrap_check_location(['SCRIPT_NAME' => '/bootstrap.php']);
        $this->assertTrue($result['ok']);
    }

    public function testCheckLocationFailsInSubfolder(): void
    {
        $result = \bootstrap_check_location(['SCRIPT_NAME' => '/install/bootstrap.php']);
        $this->assertFalse($result['ok']);
    }

    public function testCheckLocationDefaultsToRootWhenScriptNameAbsent(): void
    {
        $result = \bootstrap_check_location([]);
        $this->assertTrue($result['ok']);
    }

    public function testCheckLocationNeverConsultsDocumentRoot(): void
    {
        // A legitimately differing DOCUMENT_ROOT (real chroot: Apache sees
        // /var/www/example.be/htdocs, PHP sees /htdocs, both correct) must
        // never block — bootstrap_check_location doesn't even look at it.
        $result = \bootstrap_check_location([
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
        $this->assertTrue(\bootstrap_check_s1($this->tempDir)['ok']);
    }

    public function testS1FailsWhenVersionMissing(): void
    {
        $this->assertFalse(\bootstrap_check_s1($this->tempDir)['ok']);
    }

    public function testS2PassesWhenVendorAutoloadPresent(): void
    {
        mkdir($this->tempDir . '/vendor', 0755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');
        $this->assertTrue(\bootstrap_check_s2($this->tempDir)['ok']);
    }

    public function testS2FailsOnVendorlessArtifact(): void
    {
        $this->assertFalse(\bootstrap_check_s2($this->tempDir)['ok']);
    }

    public function testS3PassesWhenIndexPhpPresent(): void
    {
        file_put_contents($this->tempDir . '/index.php', '<?php');
        $this->assertTrue(\bootstrap_check_s3($this->tempDir)['ok']);
    }

    public function testS3Fails(): void
    {
        $this->assertFalse(\bootstrap_check_s3($this->tempDir)['ok']);
    }

    public function testS4PassesWhenSchemaPresent(): void
    {
        mkdir($this->tempDir . '/schema', 0755, true);
        file_put_contents($this->tempDir . '/schema/core.sql', 'CREATE TABLE x;');
        $this->assertTrue(\bootstrap_check_s4($this->tempDir)['ok']);
    }

    public function testS4Fails(): void
    {
        $this->assertFalse(\bootstrap_check_s4($this->tempDir)['ok']);
    }

    public function testS5PassesWhenAllStorageSubdirsExist(): void
    {
        \bootstrap_create_storage_dirs($this->tempDir);
        $this->assertTrue(\bootstrap_check_s5($this->tempDir)['ok']);
    }

    public function testS5FailsWhenAStorageSubdirIsMissing(): void
    {
        mkdir($this->tempDir . '/storage/keys', 0755, true);
        $this->assertFalse(\bootstrap_check_s5($this->tempDir)['ok']);
    }

    public function testS6PassesWhenKeysDirWritable(): void
    {
        mkdir($this->tempDir . '/storage/keys', 0755, true);
        $this->assertTrue(\bootstrap_check_s6($this->tempDir)['ok']);
    }

    public function testS6FailsWhenKeysDirMissing(): void
    {
        $this->assertFalse(\bootstrap_check_s6($this->tempDir)['ok']);
    }

    public function testS7PassesWhenArtifactHasNoRootHtaccess(): void
    {
        $this->assertTrue(\bootstrap_check_s7($this->tempDir)['ok']);
    }

    public function testS7FailsWhenArtifactShipsARootHtaccess(): void
    {
        file_put_contents($this->tempDir . '/.htaccess', 'deny all');
        $this->assertFalse(\bootstrap_check_s7($this->tempDir)['ok']);
    }

    public function testS8PassesWhenTempDirRemoved(): void
    {
        $this->assertTrue(\bootstrap_check_s8($this->tempDir . '/nonexistent-temp')['ok']);
    }

    public function testS8FailsWhenTempDirStillExists(): void
    {
        mkdir($this->tempDir . '/leftover-temp', 0755, true);
        $this->assertFalse(\bootstrap_check_s8($this->tempDir . '/leftover-temp')['ok']);
    }

    // -------------------------------------------------------------------
    // B1-B8 / F1 evaluation logic — "not readable" semantics
    // -------------------------------------------------------------------

    public function testControlProbePassesOnExactBodyMatch(): void
    {
        $this->assertTrue(\bootstrap_evaluate_control_probe(200, 'abc', 'abc'));
    }

    public function testControlProbeFailsOnMismatchOrNon200(): void
    {
        $this->assertFalse(\bootstrap_evaluate_control_probe(200, 'wrong', 'abc'));
        $this->assertFalse(\bootstrap_evaluate_control_probe(404, 'abc', 'abc'));
    }

    public function testPhpExecutionProbePassesOnEmptyBody(): void
    {
        $this->assertTrue(\bootstrap_evaluate_php_execution_probe(200, ''));
        $this->assertTrue(\bootstrap_evaluate_php_execution_probe(200, "  \n"));
    }

    public function testPhpExecutionProbeFailsWhenSourceLeaksAsPlaintext(): void
    {
        $this->assertFalse(\bootstrap_evaluate_php_execution_probe(200, "<?php /* TOKEN: abc */\n"));
    }

    /**
     * "not readable" means 403, 404, or a 200 whose body is an error page
     * rather than the probe content — never status alone.
     */
    public function testProtectionProbeTreats403And404AsProtected(): void
    {
        $this->assertTrue(\bootstrap_evaluate_protection_probe(403, '', 'secret'));
        $this->assertTrue(\bootstrap_evaluate_protection_probe(404, '', 'secret'));
    }

    public function testProtectionProbeTreats200WithDifferentBodyAsProtected(): void
    {
        $this->assertTrue(\bootstrap_evaluate_protection_probe(200, '<html>Not Found</html>', 'secret-content'));
    }

    public function testProtectionProbeTreats200WithMatchingBodyAsExposed(): void
    {
        $this->assertFalse(\bootstrap_evaluate_protection_probe(200, 'secret-content', 'secret-content'));
    }

    public function testProtectionProbeTreatsUnexpectedStatusAsNotProven(): void
    {
        $this->assertFalse(\bootstrap_evaluate_protection_probe(500, '', 'secret'));
    }

    public function testNoDirectoryListingPassesOn403Or404(): void
    {
        $this->assertTrue(\bootstrap_evaluate_no_directory_listing(403, ''));
        $this->assertTrue(\bootstrap_evaluate_no_directory_listing(404, ''));
    }

    public function testNoDirectoryListingFailsOnApacheAutoindex(): void
    {
        $this->assertFalse(\bootstrap_evaluate_no_directory_listing(200, '<title>Index of /storage/</title>'));
    }

    public function testFunctionalProbePassesWhenMarkerPresent(): void
    {
        $this->assertTrue(\bootstrap_evaluate_functional_probe(200, '<div id="scoutmagic-setup-wizard">', 'id="scoutmagic-setup-wizard"'));
    }

    public function testFunctionalProbeFailsWhenMarkerAbsent(): void
    {
        $this->assertFalse(\bootstrap_evaluate_functional_probe(200, '<div>Something else</div>', 'id="scoutmagic-setup-wizard"'));
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

        $result = \bootstrap_evaluate_gate_report($this->tempDir, $state, [
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

        $result = \bootstrap_evaluate_gate_report($this->tempDir, $state, [
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

        $result = \bootstrap_evaluate_gate_report($this->tempDir, $state, [
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

        $result = \bootstrap_evaluate_gate_report($this->tempDir, $state, [
            ['id' => 'B1', 'status' => 200, 'body' => 'ok'],
            ['id' => 'B2', 'status' => 200, 'body' => ''],
            ['id' => 'F1', 'status' => 200, 'body' => '<div id="scoutmagic-setup-wizard">'],
        ]);

        $this->assertTrue($result['gate_passed']);
        $this->assertFileExists($this->tempDir . '/index.php');

        $written = \bootstrap_step_token($this->tempDir, $result);
        $this->assertTrue($written['token_written']);
        $this->assertFileExists($this->tempDir . '/token.php');
        $this->assertStringContainsString('TOKEN:', (string) file_get_contents($this->tempDir . '/token.php'));
    }

    public function testStepTokenRefusesBeforeGatePasses(): void
    {
        $this->expectException(RuntimeException::class);
        \bootstrap_step_token($this->tempDir, ['gate_passed' => false]);
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
        $result = \bootstrap_check_disk_space($this->tempDir, 1000, static fn (string $dir) => false);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['degraded']);
    }

    public function testDiskSpaceCheckDegradesWhenArtifactSizeUnknown(): void
    {
        $result = \bootstrap_check_disk_space($this->tempDir, 0, static fn (string $dir) => 999999999);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['degraded']);
    }

    public function testDiskSpaceCheckFailsWhenGenuinelyInsufficient(): void
    {
        $result = \bootstrap_check_disk_space($this->tempDir, 1000, static fn (string $dir) => 100);
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['degraded']);
    }

    public function testDiskSpaceCheckPassesWithThreeTimesMargin(): void
    {
        $result = \bootstrap_check_disk_space($this->tempDir, 1000, static fn (string $dir) => 3000);
        $this->assertTrue($result['ok']);
    }

    public function testGatherEnvironmentInfoNeverThrowsAndAlwaysReturnsData(): void
    {
        $info = \bootstrap_gather_environment_info();
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

        \bootstrap_download_with_retry('https://example.com/a.zip', $this->tempDir . '/a.zip', $downloader);

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
            \bootstrap_download_with_retry('https://example.com/a.zip', $this->tempDir . '/a.zip', $downloader);
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
        \bootstrap_fetch_latest_release($httpGet);
    }

    public function testFetchLatestReleaseThrowsOn404(): void
    {
        $httpGet = static fn (string $url, array $headers): array => ['status' => 404, 'headers' => [], 'body' => ''];

        $this->expectException(RuntimeException::class);
        \bootstrap_fetch_latest_release($httpGet);
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

        $release = \bootstrap_fetch_latest_release($httpGet);
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

        $result = \bootstrap_verify_artifact($this->tempDir);

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

        $this->assertTrue(\bootstrap_verify_artifact($this->tempDir)['ok']);
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

        \bootstrap_copy_tree($source, $dest);

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

        $copied = \bootstrap_copy_tree($source, $dest, ['storage', 'VERSION']);

        $this->assertDirectoryDoesNotExist($dest . '/storage');
        $this->assertFileDoesNotExist($dest . '/VERSION');
        $this->assertFileExists($dest . '/core-file.php');
        $this->assertSame(['core-file.php'], $copied);
    }

    // -------------------------------------------------------------------
    // State persistence + token content + version write
    // -------------------------------------------------------------------

    public function testStateRoundTripsThroughPhpCommentWrapper(): void
    {
        $path = $this->tempDir . '/.bootstrap-state.php';
        \bootstrap_write_state($path, ['label' => 'Test', 'percent' => 42]);

        $this->assertStringStartsWith('<?php', (string) file_get_contents($path));
        $this->assertSame(['label' => 'Test', 'percent' => 42], \bootstrap_read_state($path));
    }

    public function testReadStateReturnsEmptyArrayWhenFileAbsent(): void
    {
        $this->assertSame([], \bootstrap_read_state($this->tempDir . '/nonexistent.php'));
    }

    public function testWriteVersionMatchesVersionFileFormat(): void
    {
        \bootstrap_write_version($this->tempDir, '2.5.0');
        $this->assertSame("2.5.0\n", file_get_contents($this->tempDir . '/VERSION'));
    }

    public function testTokenFileContentIsAWellFormedPhpCommentOnly(): void
    {
        $token = \bootstrap_generate_token();
        $this->assertSame(64, strlen($token));
        $content = \bootstrap_token_file_content($token);

        $this->assertStringStartsWith('<?php', $content);
        $this->assertStringContainsString("TOKEN: {$token}", $content);
    }

    public function testCreateStorageDirsCreatesExactlyTheFiveDeclaredSubdirs(): void
    {
        $created = \bootstrap_create_storage_dirs($this->tempDir);
        sort($created);
        $this->assertSame(['config', 'core', 'keys', 'modules', 'temp'], $created);
        foreach ($created as $sub) {
            $this->assertDirectoryExists($this->tempDir . '/storage/' . $sub);
        }
    }

    public function testAlreadyInstalledDetectsVersionFile(): void
    {
        $this->assertFalse(\bootstrap_already_installed($this->tempDir));
        file_put_contents($this->tempDir . '/VERSION', "1.0.0\n");
        $this->assertTrue(\bootstrap_already_installed($this->tempDir));
    }

    public function testAlreadyInstalledDetectsCoreDirectory(): void
    {
        mkdir($this->tempDir . '/core', 0755, true);
        $this->assertTrue(\bootstrap_already_installed($this->tempDir));
    }

    // -------------------------------------------------------------------
    // Layout B .htaccess content — the security-critical belt-and-braces
    // -------------------------------------------------------------------

    public function testHtaccessContentDeniesInternalDirectoriesBeforeTheRewrite(): void
    {
        $content = \bootstrap_htaccess_content();
        $denyPos = strpos($content, 'storage|core|modules');
        $rewritePos = strpos($content, 'public/$1');

        $this->assertIsInt($denyPos);
        $this->assertIsInt($rewritePos);
        $this->assertLessThan($rewritePos, $denyPos, 'the internal-directory deny rule must come before the rewrite-to-public/ rule');
        $this->assertStringContainsString('vendor', $content);
        $this->assertStringContainsString('key|enc|sql|log|sqlite', $content);
    }

    // -------------------------------------------------------------------
    // Error sanitization — no absolute paths leak to the client
    // -------------------------------------------------------------------

    public function testSanitizeErrorStripsDocRootAndParentFromMessage(): void
    {
        $docRoot = '/home/user/site/public';
        $message = "Impossible d'écrire {$docRoot}/storage/keys/master.key";

        $sanitized = \bootstrap_sanitize_error_for_client($message, $docRoot);

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

        \bootstrap_rollback_install($this->tempDir, [
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

    // -------------------------------------------------------------------
    // Public state stripping — internal-only fields never reach the client
    // -------------------------------------------------------------------

    public function testPublicStateStripsInternalFilesystemPaths(): void
    {
        $public = \bootstrap_public_state([
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
