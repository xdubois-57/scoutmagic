<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

if (!defined('E2E_SUPPORT_TEST')) {
    define('E2E_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/e2e-support.php';

/**
 * The end-to-end run measures the web server and, until this, nothing else.
 *
 * `scripts/e2e-coverage-prepend.php` is loaded as `auto_prepend_file` on
 * the `php -S` process scripts/e2e.sh starts. A cron pass is a SEPARATE
 * process — scripts/e2e-support.php's run-scheduler executes the
 * instance's own public/cron.php on the CLI — and it had neither the
 * collector nor a way to attribute what it recorded, so both halves were
 * missing and the fragments it should have written never existed.
 *
 * The report therefore said `public/cron.php` was 0 % covered. Six
 * scenarios run it seven times a run, against a real database, so that
 * number did not describe the tests: it described the instrument. And
 * because every scheduled task runs inside that same process, the whole
 * scheduled layer — the one the coverage report ranks lowest in the
 * repository — was measured by nothing at all.
 *
 * Both halves are pinned here, because either alone still records
 * nothing: the options that put the collector on the pass, and the path
 * mapping that lets the merge step recognise what it wrote.
 *
 * scripts/e2e-support.php declares no namespace (it is a CLI script the
 * shell harness runs directly), so its functions live in the global
 * namespace — hence the leading backslash, same as E2eActivationOrderTest.
 */
class E2eSchedulerCoverageTest extends TestCase
{
    private const COLLECTOR = '/scripts/e2e-coverage-prepend.php';

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testAPassWithoutCoverageCarriesNoCollector(): void
    {
        $command = \e2e_scheduler_command('/tmp/instance', null);

        $this->assertStringNotContainsString('auto_prepend_file', $command);
        $this->assertStringNotContainsString('pcov', $command);
    }

    public function testAPassWithoutCoverageStillRedirectsItsMailAndRunsTheInstancesCron(): void
    {
        $command = \e2e_scheduler_command('/tmp/instance', null);

        $this->assertStringContainsString('sendmail_path', $command);
        $this->assertStringContainsString('/tmp/instance/public/cron.php', $command);
    }

    public function testAPassWithCoverageLoadsTheRepositorysOwnCollector(): void
    {
        $command = \e2e_scheduler_command('/tmp/instance', '/tmp/coverage');

        $this->assertStringContainsString(
            'auto_prepend_file=' . $this->repositoryRoot() . self::COLLECTOR,
            $command
        );
    }

    /**
     * The same four options scripts/e2e.sh gives `php -S`. pcov.directory
     * has to be `/`: the pass executes the instance's copied public/ and
     * the repository's core/ and modules/, whose only common ancestor is
     * the root — see that script's coverage block.
     */
    public function testAPassWithCoverageSpansBothTreesAndSkipsTheSdks(): void
    {
        $command = \e2e_scheduler_command('/tmp/instance', '/tmp/coverage');

        $this->assertStringContainsString('pcov.enabled=1', $command);
        $this->assertStringContainsString('pcov.directory=/', $command);
        $this->assertStringContainsString('vendor|node_modules', $command);
    }

    public function testMeasuringAPassDoesNotStopItSendingMailOrRunningTheRightScript(): void
    {
        $command = \e2e_scheduler_command('/tmp/instance', '/tmp/coverage');

        $this->assertStringContainsString('sendmail_path', $command);
        $this->assertStringContainsString('/tmp/instance/public/cron.php', $command);
    }

    /**
     * Everything the pass records lands under the throwaway instance's
     * path, which means nothing to the merge step or to SonarQube. On the
     * web side the mapper reads $_SERVER['DOCUMENT_ROOT']; a CLI pass has
     * none, so without the E2E_INSTANCE_DIR fallback every line it
     * recorded is dropped and the file reads 0 %.
     */
    public function testACronPassesLinesAreAttributedToTheRepository(): void
    {
        $instance = $this->makeInstance();

        $mapped = $this->mapWith(
            documentRoot: null,
            instanceDir: $instance,
            data: [$instance . '/public/cron.php' => [12 => 1]]
        );

        $this->assertSame(
            [$this->repositoryRoot() . '/public/cron.php' => [12 => 1]],
            $mapped
        );
    }

    public function testALineFromTheRepositoryItselfIsLeftWhereItIs(): void
    {
        $instance = $this->makeInstance();
        $handler = $this->repositoryRoot() . '/core/Scheduler/SchedulerRunner.php';

        $mapped = $this->mapWith(
            documentRoot: null,
            instanceDir: $instance,
            data: [$handler => [7 => 1]]
        );

        $this->assertSame([$handler => [7 => 1]], $mapped);
    }

    public function testAWebRequestStillUsesItsDocumentRoot(): void
    {
        $instance = $this->makeInstance();

        $mapped = $this->mapWith(
            documentRoot: $instance . '/public',
            instanceDir: '/nowhere/at/all',
            data: [$instance . '/public/index.php' => [3 => 1]]
        );

        $this->assertSame(
            [$this->repositoryRoot() . '/public/index.php' => [3 => 1]],
            $mapped
        );
    }

    public function testWithNoHintAtAllTheDataIsHandedBackUntouched(): void
    {
        $data = ['/somewhere/public/cron.php' => [1 => 1]];

        $this->assertSame($data, $this->mapWith(null, null, $data));
    }

    /**
     * @param array<string, array<int, int>> $data
     * @return array<string, array<int, int>>
     */
    private function mapWith(?string $documentRoot, ?string $instanceDir, array $data): array
    {
        $previousRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $previousInstance = getenv('E2E_INSTANCE_DIR');

        // Loaded here rather than at the top of the file: requiring the
        // collector runs its own bootstrap closure, which starts pcov
        // when E2E_COVERAGE_DIR names a directory. It must not, inside a
        // PHPUnit process that is already collecting coverage of its own.
        putenv('E2E_COVERAGE_DIR');
        require_once $this->repositoryRoot() . self::COLLECTOR;

        if ($documentRoot === null) {
            unset($_SERVER['DOCUMENT_ROOT']);
        } else {
            $_SERVER['DOCUMENT_ROOT'] = $documentRoot;
        }

        if ($instanceDir === null) {
            putenv('E2E_INSTANCE_DIR');
        } else {
            putenv('E2E_INSTANCE_DIR=' . $instanceDir);
        }

        try {
            return \e2e_coverage_map_instance_paths($data);
        } finally {
            if ($previousRoot === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previousRoot;
            }

            if ($previousInstance === false) {
                putenv('E2E_INSTANCE_DIR');
            } else {
                putenv('E2E_INSTANCE_DIR=' . $previousInstance);
            }
        }
    }

    /**
     * realpath() is what the mapper compares with, so the directory has to
     * exist on disk for the comparison to mean anything.
     */
    private function makeInstance(): string
    {
        $dir = sys_get_temp_dir() . '/scoutmagic-e2e-cov-' . bin2hex(random_bytes(6));
        mkdir($dir . '/public', 0o777, true);

        register_shutdown_function(static function () use ($dir): void {
            @rmdir($dir . '/public');
            @rmdir($dir);
        });

        return $dir;
    }
}
