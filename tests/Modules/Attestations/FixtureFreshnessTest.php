<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations;

use PHPUnit\Framework\TestCase;

/**
 * The committed golden fixture still matches the generator that wrote it.
 *
 * Same mechanism, and the same reason, as the reference dataset's
 * `--check` and `js-typecheck-baseline.json`: a change to the generator
 * that nobody re-ran leaves a fixture describing a document the project no
 * longer produces, and every assertion written against it goes on passing
 * while meaning something else.
 */
class FixtureFreshnessTest extends TestCase
{
    public function testTheCommittedBatchFixtureMatchesItsGenerator(): void
    {
        $generator = dirname(__DIR__, 2) . '/fixtures/pdf/generate-attestations-batch.php';
        $this->assertFileExists($generator);

        $output = [];
        $status = 0;
        exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($generator) . ' --check 2>&1', $output, $status);

        $this->assertSame(
            0,
            $status,
            "attestations_batch_sample.pdf is out of date. Re-run\n"
            . "  php tests/fixtures/pdf/generate-attestations-batch.php\n"
            . "and commit what it wrote.\n" . implode("\n", $output)
        );
    }
}
