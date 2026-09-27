<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Trend;

use Core\Mail\Feedback\Dmarc\AuthenticationTrend;
use Core\Mail\Feedback\Dmarc\DmarcRecord;
use Core\Mail\Feedback\Dmarc\DmarcReport;
use Core\Mail\Feedback\Dmarc\DmarcReportRepository;
use Core\Mail\Feedback\Dmarc\Task\PurgeDmarcReportsHandler;
use Core\Mail\Feedback\Seed\DomainRouting;
use Core\Mail\Feedback\Seed\LandingTrend;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Mail\Feedback\Seed\Task\PurgeSeedCopiesHandler;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The two trends end to end, and the one thing neither may get wrong: the
 * curve stops where the purge stops (issue #420).
 */
#[Group('database')]
class TrendsFollowTheirPurgeTest extends TestCase
{
    private \PDO $pdo;
    private DmarcReportRepository $reports;
    private SeedCopyRepository $copies;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->reports = new DmarcReportRepository($this->pdo);
        $this->copies = new SeedCopyRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    /**
     * **The left edge is the retention constant, read and not restated.**
     * Asserted against the constant itself rather than against 90: a change to
     * the purge that this curve did not follow is exactly the drift the
     * arbitration asked to make impossible, and a literal here would sail
     * through it.
     */
    public function testTheDmarcCurveSpansExactlyTheRetentionWindow(): void
    {
        $now = new \DateTimeImmutable('2026-09-23 12:00:00');

        $series = AuthenticationTrend::build($this->reports, $now);

        $first = $series->points[0]['from'];
        $edge = $now->modify('-' . PurgeDmarcReportsHandler::RETENTION_DAYS . ' days');

        $this->assertSame(
            $edge->modify('monday this week')->format('Y-m-d'),
            $first->format('Y-m-d'),
            'the curve begins in the week the purge stops deleting'
        );
        $this->assertTrue(
            $series->points[count($series->points) - 1]['partial'],
            'and ends in the week still filling up'
        );
    }

    /** The same, for the seed boxes and their own constant. */
    public function testTheSeedCurveSpansExactlyItsOwnRetentionWindow(): void
    {
        $now = new \DateTimeImmutable('2026-09-23 12:00:00');
        $this->landOneMailing('envoi-1', $now->modify('-3 days'), 'INBOX');

        $series = LandingTrend::build($this->copies, $now);
        $edge = $now->modify('-' . PurgeSeedCopiesHandler::RETENTION_DAYS . ' days');

        $this->assertSame(
            $edge->modify('monday this week')->format('Y-m-d'),
            $series['gmail.com']->points[0]['from']->format('Y-m-d')
        );
    }

    /**
     * **A young installation gets holes, not zeros**, which is what makes the
     * fixed left edge honest: the curve covers the whole window without
     * claiming that nothing was sent before the site existed.
     */
    public function testAYoungInstallationReadsAsUnmeasuredAndNotAsSilence(): void
    {
        $now = new \DateTimeImmutable('2026-09-23 12:00:00');
        $this->recordReport('r-1', $now->modify('-2 days'), messages: 40, authenticated: 40);

        $series = AuthenticationTrend::build($this->reports, $now);
        $values = array_column($series->points, 'value');

        $this->assertNull($values[0], 'the oldest week has no rows and no point');
        $this->assertSame(1.0, $values[count($values) - 1], 'the measured week is drawn');
        $this->assertNotContains(0.0, $values, 'no week is asserted to have authenticated nothing');
    }

    /**
     * The DMARC threshold is in messages, and twenty of them: a week of stray
     * e-mails draws nothing, and one ordinary mailing clears it.
     */
    public function testAWeekOfStrayMessagesDrawsNothingAndAMailingDraws(): void
    {
        $now = new \DateTimeImmutable('2026-09-23 12:00:00');
        $this->recordReport('r-thin', $now->modify('-9 days'), messages: 6, authenticated: 3);
        $this->recordReport('r-real', $now->modify('-2 days'), messages: 40, authenticated: 30);

        $series = AuthenticationTrend::build($this->reports, $now);
        $drawn = array_values(array_filter(
            $series->points,
            static fn(array $point): bool => $point['value'] !== null
        ));

        $this->assertCount(1, $drawn, 'six messages are under the threshold of twenty');
        $this->assertSame(0.75, $drawn[0]['value']);
        $this->assertLessThan(
            AuthenticationTrend::MINIMUM_MESSAGES,
            6,
            'the thin week is thin by the constant, not by a number written here'
        );
    }

    /**
     * **A provider measured too thinly is left off the chart**, rather than
     * drawn as an empty line: a legend entry with no line beside it reads as
     * « delivered nothing », the opposite of « not measured enough to say ».
     */
    public function testAProviderWithTooFewMailingsIsNotDrawable(): void
    {
        $now = new \DateTimeImmutable('2026-09-23 12:00:00');

        // One mailing at orange.fr, and MINIMUM_RUNS at gmail.com — the same
        // week, so only the threshold separates them.
        $this->landOneMailing('envoi-orange', $now->modify('-2 days'), 'Indésirables', 'temoin@orange.fr');
        for ($i = 0; $i < DomainRouting::MINIMUM_RUNS; $i++) {
            $this->landOneMailing('envoi-g-' . $i, $now->modify('-2 days'), 'INBOX');
        }

        $drawable = LandingTrend::drawable(LandingTrend::build($this->copies, $now));

        $this->assertSame(
            ['gmail.com'],
            array_keys($drawable),
            'one mailing cannot carry a provider\'s figures, and is not drawn as if it could'
        );
    }

    private function recordReport(
        string $id,
        \DateTimeImmutable $end,
        int $messages,
        int $authenticated
    ): void {
        $this->assertTrue($this->reports->record(
            new DmarcReport(
                organisation: 'google.com',
                reportId: $id,
                domain: 'unite.be',
                begin: $end->modify('-1 day'),
                end: $end,
                policy: 'none',
                records: [
                    new DmarcRecord('185.12.80.100', $authenticated, 'none', true, true, 'unite.be'),
                    new DmarcRecord(
                        '203.0.113.77',
                        $messages - $authenticated,
                        'none',
                        false,
                        false,
                        'unite.be'
                    ),
                ]
            ),
            new \DateTimeImmutable()
        ));
    }

    private function landOneMailing(
        string $run,
        \DateTimeImmutable $at,
        string $folder,
        string $address = 'temoin@gmail.com'
    ): void {
        $this->copies->claim($run, $address, $at);
        $this->copies->recordLanding($run, $address, $folder, $at->modify('+5 minutes'));
    }
}
