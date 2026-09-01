<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Modules\SupportDashboard\Service\ReportedFacts;
use PHPUnit\Framework\TestCase;

/**
 * The one reader of a usage payload.
 *
 * This file exists because of a shipped defect: the ticket detail page
 * read `scoutmagic_version`, `php_version` and `active_members` off the
 * TOP LEVEL of the document, where none of them lives, so a payload
 * carrying all three rendered « Non renseigné » for all three. The test
 * that would have caught it is the one that feeds a payload shaped like
 * the real builder's and asserts the values come back.
 */
class ReportedFactsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        // The shape Core\Statistics\StatisticsPayloadBuilder::build()
        // actually produces — nested, which is the whole point.
        return [
            'statistics_schema_version' => 1,
            'installation_id' => str_repeat('a', 32),
            'instance_url' => 'https://unite-de-test.be',
            'scoutmagic' => ['version' => '1.0.40', 'is_dev_build' => false],
            'scout_year' => ['label' => '2026-2027'],
            'usage' => ['active_members' => 118, 'active_sections' => 6],
            'installation' => ['method' => 'archive'],
            'runtime' => ['php_version' => '8.4.1'],
            'database' => ['engine' => 'mysql', 'version' => '10.11.6-MariaDB'],
            'updates' => ['auto_update_enabled' => true, 'auto_update_level' => 'patch'],
        ];
    }

    public function testItReadsTheNestedFieldsTheBuilderActuallyProduces(): void
    {
        $facts = ReportedFacts::fromPayload($this->payload());

        $this->assertSame('1.0.40', $facts['scoutmagic_version']);
        $this->assertSame('8.4.1', $facts['php_version']);
        $this->assertSame(118, $facts['active_members']);
        $this->assertSame(6, $facts['active_sections']);
        $this->assertSame('archive', $facts['installation_method']);
        $this->assertSame('patch', $facts['auto_update_level']);
    }

    /** Two columns nobody reads apart, joined into the one they do. */
    public function testTheDatabaseEngineAndVersionReadAsOneValue(): void
    {
        $this->assertSame(
            'mysql 10.11.6-MariaDB',
            ReportedFacts::fromPayload($this->payload())['database_version']
        );
    }

    public function testEitherHalfAloneStillReads(): void
    {
        $this->assertSame(
            'mysql',
            ReportedFacts::fromPayload(['database' => ['engine' => 'mysql']])['database_version']
        );
        $this->assertSame(
            '10.11',
            ReportedFacts::fromPayload(['database' => ['version' => '10.11']])['database_version']
        );
    }

    /**
     * Every field is absent rather than invented — an empty payload is an
     * ordinary case (a ticket from before the snapshot existed).
     */
    public function testAnEmptyPayloadYieldsNullsAndNeverAnError(): void
    {
        $facts = ReportedFacts::fromPayload([]);

        $this->assertSame(array_keys(ReportedFacts::LABELS), array_keys($facts));
        foreach ($facts as $value) {
            $this->assertNull($value);
        }
    }

    /**
     * The payload comes from another installation: a nested array where a
     * string was expected must become null, not something a template has
     * to render.
     */
    public function testAValueOfTheWrongShapeBecomesNull(): void
    {
        $facts = ReportedFacts::fromPayload([
            'scoutmagic' => ['version' => ['1.0.40']],
            'runtime' => 'pas un objet',
            'usage' => ['active_members' => 'beaucoup'],
        ]);

        $this->assertNull($facts['scoutmagic_version']);
        $this->assertNull($facts['php_version']);
        $this->assertNull($facts['active_members']);
    }

    public function testEveryLabelledFieldIsProduced(): void
    {
        $this->assertSame(
            array_keys(ReportedFacts::LABELS),
            array_keys(ReportedFacts::fromPayload($this->payload()))
        );
    }
}
