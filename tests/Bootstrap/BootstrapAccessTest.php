<?php

declare(strict_types=1);

namespace Tests\Bootstrap;

use PHPUnit\Framework\TestCase;

if (!defined('BOOTSTRAP_TEST')) {
    define('BOOTSTRAP_TEST', true);
}
require_once dirname(__DIR__, 2) . '/bootstrap/bootstrap.php';

/**
 * What the installer asks before it installs anything (#719, B): the
 * token first, then HTTPS, then — optionally — the archive to restore,
 * sent afterwards in resumable chunks.
 *
 * bootstrap/bootstrap.php declares no namespace — hence the leading
 * backslashes.
 */
final class BootstrapAccessTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $docRoot;

    protected function setUp(): void
    {
        $this->docRoot = sys_get_temp_dir() . '/bootstrap_access_' . uniqid();
        mkdir($this->docRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->docRoot);
    }

    // -------------------------------------------------------------------
    // The token, first
    // -------------------------------------------------------------------

    public function testTheTokenFileIsWrittenOnceAndThenKept(): void
    {
        $this->assertTrue(\bootstrapEnsureTokenFile($this->docRoot));
        $token = \bootstrapReadTokenValue($this->docRoot);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertTrue(\bootstrapEnsureTokenFile($this->docRoot));
        $this->assertSame($token, \bootstrapReadTokenValue($this->docRoot), 'never regenerated under the operator');
    }

    public function testTheRightTokenGivesAProofAndNeverEchoesTheToken(): void
    {
        \bootstrapEnsureTokenFile($this->docRoot);
        $token = \bootstrapReadTokenValue($this->docRoot);

        $result = \bootstrapVerifyToken($this->docRoot, '  ' . strtoupper($token) . "\n", self::NOW);

        $this->assertTrue($result['ok']);
        $this->assertStringNotContainsString($token, (string) $result['cookie']);
        $this->assertTrue(\bootstrapProofIsValid((string) $result['cookie'], $token, self::NOW + 60));
        $this->assertTrue(\bootstrapIsAuthorized(
            $this->docRoot,
            [\BOOTSTRAP_PROOF_COOKIE => $result['cookie']],
            self::NOW + 60
        ));
    }

    public function testNoCookieOrAForgedOneIsNotAuthorized(): void
    {
        \bootstrapEnsureTokenFile($this->docRoot);

        $this->assertFalse(\bootstrapIsAuthorized($this->docRoot, [], self::NOW));
        $this->assertFalse(\bootstrapIsAuthorized(
            $this->docRoot,
            [\BOOTSTRAP_PROOF_COOKIE => \bootstrapProofValue(str_repeat('0', 64), self::NOW + 60)],
            self::NOW
        ));
    }

    /** The wizard's own ladder: 4, 6, 8, 10 failures → 1 min, 5 min, 30 min, 24 h. */
    public function testWrongTokensFollowTheWizardsLockoutLadder(): void
    {
        $this->assertSame(0, \bootstrapTokenLockSeconds(3));
        $this->assertSame(60, \bootstrapTokenLockSeconds(4));
        $this->assertSame(300, \bootstrapTokenLockSeconds(6));
        $this->assertSame(1800, \bootstrapTokenLockSeconds(8));
        $this->assertSame(86400, \bootstrapTokenLockSeconds(10));

        \bootstrapEnsureTokenFile($this->docRoot);
        $token = \bootstrapReadTokenValue($this->docRoot);
        for ($i = 1; $i <= 3; $i++) {
            $this->assertArrayNotHasKey('locked_until', \bootstrapVerifyToken($this->docRoot, 'faux', self::NOW));
        }
        $fourth = \bootstrapVerifyToken($this->docRoot, 'faux', self::NOW);
        $this->assertSame(self::NOW + 60, $fourth['locked_until'] ?? null);

        // Locked: even the right token is not read until it lifts.
        $this->assertFalse(\bootstrapVerifyToken($this->docRoot, $token, self::NOW + 30)['ok']);
        $this->assertTrue(\bootstrapVerifyToken($this->docRoot, $token, self::NOW + 61)['ok']);
        $this->assertArrayNotHasKey('token_attempts', \bootstrapReadAccess($this->docRoot), 'a success resets the count');
    }

    /**
     * Fail closed: an attempt that cannot be recorded is not compared. A
     * folder refusing the access file would otherwise reset the count on
     * every request, and the ladder would never engage.
     */
    public function testATokenIsNotEvenComparedWhenItsAttemptCannotBeRecorded(): void
    {
        \bootstrapEnsureTokenFile($this->docRoot);
        $token = \bootstrapReadTokenValue($this->docRoot);
        // A directory where the file should be: no write succeeds, root included.
        mkdir($this->docRoot . '/' . \BOOTSTRAP_ACCESS_FILE);

        $result = \bootstrapVerifyToken($this->docRoot, $token, self::NOW);

        rmdir($this->docRoot . '/' . \BOOTSTRAP_ACCESS_FILE);
        $this->assertFalse($result['ok']);
        $this->assertArrayNotHasKey('cookie', $result);
        $this->assertStringContainsString('écriture', (string) ($result['error'] ?? ''));
    }

    /** @return array<string, array{0: array<string, string>, 1: bool}> */
    public static function requestSchemes(): array
    {
        return [
            'plain http' => [['HTTP_HOST' => 'unite.example.org'], false],
            'IIS saying off' => [['HTTPS' => 'off'], false],
            'HTTPS on' => [['HTTPS' => 'on'], true],
            'port 443' => [['SERVER_PORT' => '443'], true],
            'TLS ended by a proxy' => [['HTTP_X_FORWARDED_PROTO' => 'https, http'], true],
            'a proxy speaking http' => [['HTTP_X_FORWARDED_PROTO' => 'http'], false],
        ];
    }

    /** @param array<string, string> $server */
    #[\PHPUnit\Framework\Attributes\DataProvider('requestSchemes')]
    public function testTheSchemeTheBrowserSpokeIsRecognised(array $server, bool $https): void
    {
        $this->assertSame($https, \bootstrapRequestIsHttps($server));
    }

    // -------------------------------------------------------------------
    // HTTPS, blocking
    // -------------------------------------------------------------------

    public function testTheSiteAnsweringItsProbeOverHttpsPasses(): void
    {
        $asked = [];
        $result = \bootstrapCheckSiteHttps($this->docRoot, 'unite.example.org', function (string $url) use (&$asked): array {
            $asked[] = $url;
            $file = basename((string) parse_url($url, PHP_URL_PATH));

            return ['status' => 200, 'body' => (string) file_get_contents($this->docRoot . '/' . $file)];
        });

        $this->assertTrue($result['ok'], $result['detail']);
        $this->assertStringStartsWith('https://unite.example.org/bootstrap-https-', $asked[0]);
        $this->assertSame([], glob($this->docRoot . '/bootstrap-https-*') ?: [], 'the probe is removed');
    }

    /** @return array<string, array{0: array{status: int, body: string}, 1: string}> */
    public static function failingHttps(): array
    {
        return [
            'no certificate or port closed' => [['status' => 0, 'body' => ''], 'certificat'],
            'an error page' => [['status' => 404, 'body' => 'Not Found'], 'code HTTP 404'],
            'another folder' => [['status' => 200, 'body' => 'autre chose'], 'un autre dossier'],
        ];
    }

    /**
     * @param array{status: int, body: string} $answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failingHttps')]
    public function testEveryOtherAnswerSaysWhatToFix(array $answer, string $said): void
    {
        $result = \bootstrapCheckSiteHttps($this->docRoot, 'unite.example.org', static fn (): array => $answer);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString($said, $result['detail']);
        $this->assertStringContainsString('relancez la vérification', $result['detail']);
    }

    public function testWithoutAUsableHostTheCheckSaysHowToReachThePage(): void
    {
        $result = \bootstrapCheckSiteHttps($this->docRoot, null, function (): array {
            $this->fail('no request without a host');
        });

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("l'adresse publique", $result['detail']);
    }

    public function testAMalformedOrEmptyProofIsNeverValid(): void
    {
        $this->assertFalse(\bootstrapProofIsValid('', str_repeat('a', 64), self::NOW));
        $this->assertFalse(\bootstrapProofIsValid('123.xyz', str_repeat('a', 64), self::NOW));
        $this->assertFalse(\bootstrapProofIsValid(\bootstrapProofValue('', self::NOW + 60), '', self::NOW));
    }

    public function testAChunkBeforeTheReceptionFolderWasPreparedIsRefused(): void
    {
        $state = $this->installedState();

        $this->assertSame(409, \bootstrapArchiveAppend($state, 0, 'x', false)['status']);
    }

    public function testOnlyAPlainHostNameIsEverPutInAUrl(): void
    {
        $this->assertSame('unite.example.org', \bootstrapRequestHost(['HTTP_HOST' => 'Unite.Example.org']));
        $this->assertSame('localhost:8080', \bootstrapRequestHost(['HTTP_HOST' => 'localhost:8080']));
        $this->assertNull(\bootstrapRequestHost(['HTTP_HOST' => 'evil.example/@x']));
        $this->assertNull(\bootstrapRequestHost(['HTTP_HOST' => 'a b']));
        $this->assertNull(\bootstrapRequestHost([]));
    }

    /** Nothing installs before HTTPS was seen working: step 1 refuses. */
    public function testTheInstallRefusesToStartWithoutAVerifiedHttps(): void
    {
        \bootstrapRequireVerifiedHttps(['site_https_verified' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTPS/');
        \bootstrapRequireVerifiedHttps([]);
    }

    // -------------------------------------------------------------------
    // The archive's release
    // -------------------------------------------------------------------

    public function testOnlyAPublishedReleaseNumberIsAccepted(): void
    {
        $this->assertTrue(\bootstrapIsReleaseVersion('1.2.3'));
        $this->assertFalse(\bootstrapIsReleaseVersion('dev-12b6042'));
        $this->assertFalse(\bootstrapIsReleaseVersion('1.2.3/../../x'));
        $this->assertFalse(\bootstrapIsReleaseVersion('1.2'));
    }

    public function testTheReleaseThatWroteTheArchiveIsFetchedByItsTag(): void
    {
        $asked = [];
        $release = \bootstrapFetchReleaseByVersion(function (string $url) use (&$asked): array {
            $asked[] = $url;

            return ['status' => 200, 'headers' => [], 'body' => json_encode(['tag_name' => 'v1.4.2'])];
        }, '1.4.2');

        $this->assertSame('v1.4.2', $release['tag_name']);
        $this->assertSame(['https://api.github.com/repos/xdubois-57/scoutmagic/releases/tags/v1.4.2'], $asked);
    }

    public function testADevelopmentVersionIsRefusedBeforeAnyRequest(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/pas une version publiée/');

        \bootstrapFetchReleaseByVersion(function (): array {
            $this->fail('no request for a version that was never published');
        }, 'dev-12b6042');
    }

    public function testTheResolveStepUsesTheArchivesReleaseWhenOneWasChosen(): void
    {
        $asked = [];
        $state = \bootstrapStepResolve($this->docRoot, ['layout' => 'B', 'release_version' => '1.4.2'], function (string $url) use (&$asked): array {
            $asked[] = $url;

            return ['status' => 200, 'headers' => [], 'body' => json_encode([
                'tag_name' => 'v1.4.2',
                'assets' => [['name' => 'release-v1.4.2.zip', 'browser_download_url' => 'https://example.invalid/r.zip', 'size' => 10]],
            ])];
        });

        $this->assertSame('1.4.2', $state['version']);
        $this->assertStringEndsWith('/releases/tags/v1.4.2', $asked[0]);
    }

    public function testAnArchiveCommentIsReadAsTheWizardWritesIt(): void
    {
        $hints = \bootstrapParseArchiveComment((string) json_encode([
            'format' => \BOOTSTRAP_PORTABLE_FORMAT,
            'format_version' => \BOOTSTRAP_PORTABLE_FORMAT_VERSION,
            'scoutmagic_version' => '1.4.2',
            'site_url' => "https://ancien.example\u{0007}",
            'created_at' => '2026-09-30T21:15:00Z',
            'kind' => 'remote',
            'passphrase_generation' => 2,
            'something_newer' => true,
        ]));

        $this->assertSame('1.4.2', $hints['version'] ?? null);
        $this->assertSame('https://ancien.example', $hints['site_url'] ?? null, 'control characters stripped');
        $this->assertNull(\bootstrapParseArchiveComment('{"format":"autre"}'));
        $this->assertNull(\bootstrapParseArchiveComment((string) json_encode([
            'format' => \BOOTSTRAP_PORTABLE_FORMAT,
            'format_version' => 1,
            'scoutmagic_version' => '1.0.0',
        ])), 'the older format is not this one');
        $this->assertNull(\bootstrapParseArchiveComment('pas du json'));
    }

    // -------------------------------------------------------------------
    // Sending the archive
    // -------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function installedState(string $layout = 'A'): array
    {
        $target = $this->docRoot . '/site';
        mkdir($target . '/storage', 0755, true);

        return ['gate_passed' => true, 'install_target' => $target, 'layout' => $layout, 'version' => '1.4.2'];
    }

    private function archiveBytes(string $version = '1.4.2'): string
    {
        $path = $this->docRoot . '/fixture-' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', str_repeat('x', 5000));
        $zip->setArchiveComment((string) json_encode([
            'format' => \BOOTSTRAP_PORTABLE_FORMAT,
            'format_version' => \BOOTSTRAP_PORTABLE_FORMAT_VERSION,
            'scoutmagic_version' => $version,
            'site_url' => 'https://ancien.example',
            'created_at' => '2026-09-30T21:15:00Z',
            'kind' => 'manual',
            'passphrase_generation' => null,
        ]));
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function testNothingIsAcceptedBeforeTheGateHasPassed(): void
    {
        $this->assertFalse(\bootstrapArchiveBegin($this->docRoot, [], null, static fn (): array => [], ['size' => 1])['ok']);
        $this->assertSame(409, \bootstrapArchiveAppend([], 0, 'x', false)['status']);
    }

    public function testChunksAreAppendedInSequenceAndTheArchiveLandsWhereTheWizardReadsIt(): void
    {
        $bytes = $this->archiveBytes();
        $half = intdiv(strlen($bytes), 2);
        $file = ['size' => strlen($bytes), 'name' => 'unite.zip', 'modified' => 1_800_000_000];
        $begin = \bootstrapArchiveBegin($this->docRoot, $this->installedState(), null, static fn (): array => [], $file);
        $this->assertTrue($begin['ok']);
        $this->assertSame(0, $begin['received']);
        $state = $begin['state'];
        $this->assertSame("Require all denied\n", file_get_contents($state['install_target'] . '/' . \BOOTSTRAP_INCOMING_DIR . '/.htaccess'));

        $first = \bootstrapArchiveAppend($state, 0, substr($bytes, 0, $half), false);
        $this->assertSame([200, $half], [$first['status'], $first['received']]);

        // A replayed or skipped chunk is refused with what is held: resume from there.
        $again = \bootstrapArchiveAppend($state, 0, substr($bytes, 0, $half), false);
        $this->assertSame([409, $half], [$again['status'], $again['received']]);
        $this->assertSame($half, \bootstrapArchiveBegin($this->docRoot, $state, null, static fn (): array => [], $file)['received']);

        $last = \bootstrapArchiveAppend($state, $half, substr($bytes, $half), true);
        $this->assertSame(200, $last['status'], (string) ($last['error'] ?? ''));
        $this->assertTrue($last['done'] ?? false);
        $this->assertSame($bytes, file_get_contents($state['install_target'] . '/' . \BOOTSTRAP_ARCHIVE_PATH));
        $this->assertFileDoesNotExist($state['install_target'] . '/' . \BOOTSTRAP_INCOMING_DIR . '/' . \BOOTSTRAP_INCOMING_PART);
    }

    public function testAnArchiveFromAnotherVersionThanTheOneInstalledIsRefused(): void
    {
        $bytes = $this->archiveBytes('1.3.0');
        $state = \bootstrapArchiveBegin(
            $this->docRoot,
            $this->installedState(),
            null,
            static fn (): array => [],
            ['size' => strlen($bytes), 'name' => 'unite.zip', 'modified' => 1_800_000_000]
        )['state'];

        $result = \bootstrapArchiveAppend($state, 0, $bytes, true);

        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('1.3.0', (string) $result['error']);
        $this->assertFileDoesNotExist($state['install_target'] . '/' . \BOOTSTRAP_ARCHIVE_PATH);
    }

    public function testAFileThatIsNotAPortableArchiveIsRefused(): void
    {
        $bytes = 'pas une archive';
        $state = \bootstrapArchiveBegin(
            $this->docRoot,
            $this->installedState(),
            null,
            static fn (): array => [],
            ['size' => strlen($bytes), 'name' => 'unite.zip', 'modified' => 1_800_000_000]
        )['state'];

        $this->assertSame(422, \bootstrapArchiveAppend($state, 0, $bytes, true)['status']);
    }

    /**
     * The resume offset belongs to one file: another one starts over rather
     * than being spliced onto the first one's bytes, and a leftover as long
     * as the file never reads as « already sent ».
     */
    public function testALeftoverResumesOnlyAShorterCopyOfTheSameFile(): void
    {
        $bytes = $this->archiveBytes();
        $file = ['size' => strlen($bytes), 'name' => 'unite.zip', 'modified' => 1_800_000_000];
        $state = \bootstrapArchiveBegin($this->docRoot, $this->installedState(), null, static fn (): array => [], $file)['state'];
        \bootstrapArchiveAppend($state, 0, substr($bytes, 0, 100), false);
        $part = $state['install_target'] . '/' . \BOOTSTRAP_INCOMING_DIR . '/' . \BOOTSTRAP_INCOMING_PART;

        $same = \bootstrapArchiveBegin($this->docRoot, $state, null, static fn (): array => [], $file);
        $this->assertSame(100, $same['received'], 'the same file resumes');

        $other = \bootstrapArchiveBegin($this->docRoot, $state, null, static fn (): array => [], ['modified' => 1] + $file);
        $this->assertSame(0, $other['received'], 'another file starts over');
        $this->assertFileDoesNotExist($part);

        file_put_contents($part, $bytes);
        $complete = \bootstrapArchiveBegin($this->docRoot, $state, null, static fn (): array => [], $file);
        $this->assertSame(0, $complete['received'], 'a full leftover is not « already sent »');
    }

    public function testChunksNeverGoPastTheAnnouncedSizeAndTheLastOneMustCompleteIt(): void
    {
        $file = ['size' => 10, 'name' => 'unite.zip', 'modified' => 1_800_000_000];
        $state = \bootstrapArchiveBegin($this->docRoot, $this->installedState(), null, static fn (): array => [], $file)['state'];

        $this->assertSame(413, \bootstrapArchiveAppend($state, 0, str_repeat('x', 11), false)['status']);
        $this->assertSame(409, \bootstrapArchiveAppend($state, 0, str_repeat('x', 4), true)['status']);
        $this->assertSame(200, \bootstrapArchiveAppend($state, 0, str_repeat('x', 4), false)['status']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidFiles(): array
    {
        return [
            'no size' => [['name' => 'unite.zip']],
            'empty' => [['size' => 0]],
            'a size as text' => [['size' => '10']],
            'over 2 GB' => [['size' => \BOOTSTRAP_ARCHIVE_MAX_BYTES + 1]],
        ];
    }

    /** @param array<string, mixed> $file */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFiles')]
    public function testAnUnusableAnnouncedSizeIsRefused(array $file): void
    {
        $begin = \bootstrapArchiveBegin($this->docRoot, $this->installedState(), null, static fn (): array => [], $file);

        $this->assertFalse($begin['ok']);
        $this->assertArrayNotHasKey('archive_upload', $begin['state']);
    }

    /**
     * Layout B keeps storage/ under the document root: the reception folder
     * is proved unreachable through the site before anything is accepted.
     */
    public function testInLayoutBAReceptionFolderServedByTheSiteRefusesTheUpload(): void
    {
        $state = $this->installedState('B');
        $exposed = function (string $url) use ($state): array {
            $file = basename((string) parse_url($url, PHP_URL_PATH));

            return ['status' => 200, 'body' => (string) file_get_contents(
                $state['install_target'] . '/' . \BOOTSTRAP_INCOMING_DIR . '/' . $file
            )];
        };

        $file = ['size' => 10, 'name' => 'unite.zip', 'modified' => 1_800_000_000];
        $protected = \bootstrapArchiveBegin($this->docRoot, $state, 'unite.example.org', static fn (): array => ['status' => 403, 'body' => ''], $file);
        $this->assertTrue($protected['ok']);

        $refused = \bootstrapArchiveBegin($this->docRoot, $protected['state'], 'unite.example.org', $exposed, $file);
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString("l'assistant de configuration", (string) $refused['detail']);

        // The refusal is the server's: a chunk sent anyway, past the
        // browser, is refused too — even after an earlier check passed.
        $this->assertSame(409, \bootstrapArchiveAppend($refused['state'], 0, 'x', false)['status']);
    }

    /**
     * Fail closed: when the canary cannot be written, its URL answers 404 —
     * which reads as « protected ». The check refuses instead of passing
     * without having tested anything.
     */
    public function testInLayoutBACanaryThatCouldNotBeWrittenRefusesTheUpload(): void
    {
        if (!is_dir('/proc') || @file_put_contents('/proc/scoutmagic-write-test', 'x') !== false) {
            $this->markTestSkipped('needs a directory nobody, root included, can create files in');
        }
        $state = $this->installedState('B');
        $incoming = $state['install_target'] . '/' . \BOOTSTRAP_INCOMING_DIR;
        mkdir(dirname($incoming), 0700, true);
        // A reception folder that exists and refuses every new file: /proc.
        symlink('/proc', $incoming);

        try {
            $result = \bootstrapArchiveBegin(
                $this->docRoot,
                $state,
                'unite.example.org',
                static fn (): array => ['status' => 404, 'body' => ''],
                ['size' => 10, 'name' => 'unite.zip', 'modified' => 1_800_000_000]
            );
        } finally {
            unlink($incoming); // the link, never what it points to
        }

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("n'a pas pu être écrit", (string) $result['detail']);
    }

    public function testCleanupSendsTheOperatorToTheRestoreModeOnlyWhenAnArchiveWaits(): void
    {
        $state = $this->installedState();
        $plain = \bootstrapStepCleanup($this->docRoot, $state, static fn (): bool => true);
        $this->assertSame('/setup', $plain['redirect']);

        mkdir(dirname($state['install_target'] . '/' . \BOOTSTRAP_ARCHIVE_PATH), 0700, true);
        file_put_contents($state['install_target'] . '/' . \BOOTSTRAP_ARCHIVE_PATH, 'PK');
        \bootstrapWriteAccess($this->docRoot, ['https_verified_at' => self::NOW]);

        $withArchive = \bootstrapStepCleanup($this->docRoot, $state, static fn (): bool => true);
        $this->assertSame(\BOOTSTRAP_RESTORE_MODE_URL, $withArchive['redirect']);
        $this->assertFileDoesNotExist($this->docRoot . '/' . \BOOTSTRAP_ACCESS_FILE);
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
