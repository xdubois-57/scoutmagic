<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\SendCounterRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What each provider has sent, and the two different questions asked of
 * it (ARCHITECTURE.md §8.106).
 *
 * The quota reads one provider's whole day. The reserve (D8) reads the
 * highest daily **non-bulk** total of the last thirty days, across every
 * provider — which is why the lane is a column and not something derived
 * afterwards. A single figure per provider could answer the first
 * question and never the second.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SendCounterRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SendCounterRepository $counters;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->counters = new SendCounterRepository($this->pdo);
    }

    public function testIncrementingTheSameDayAndLaneFoldsIntoOneRow(): void
    {
        $this->counters->increment(1, MailLane::Bulk);
        $this->counters->increment(1, MailLane::Bulk);
        $this->counters->increment(1, MailLane::Bulk);

        $this->assertSame(3, $this->counters->totalForProvider(1));
        $this->assertSame(1, $this->rowCount(), 'The unique index folds them — never three rows.');
    }

    /**
     * A quota is one provider's whole day. Counting per lane would make
     * the authentication lane believe a relay was fresh the moment a
     * publipostage had emptied it.
     */
    public function testTheDailyTotalSpansEveryLaneOfThatProvider(): void
    {
        $this->counters->increment(1, MailLane::Bulk);
        $this->counters->increment(1, MailLane::Authentication);
        $this->counters->increment(1, MailLane::Transactional);

        $this->assertSame(3, $this->counters->totalForProvider(1));
    }

    public function testTotalsForDayAreKeyedByProvider(): void
    {
        $this->counters->increment(1, MailLane::Bulk);
        $this->counters->increment(MailProvider::LOCAL_ID, MailLane::Transactional);
        $this->counters->increment(MailProvider::LOCAL_ID, MailLane::Transactional);

        // Sorted before comparing: `assertSame()` on arrays compares key
        // ORDER too, and `totalsForDay()` groups without an `ORDER BY`,
        // so a server free to return provider 1 first would fail this on
        // MySQL while it passed on SQLite. What is asserted is the
        // mapping, which is what the method promises.
        $totals = $this->counters->totalsForDay();
        ksort($totals);

        $this->assertSame([MailProvider::LOCAL_ID => 2, 1 => 1], $totals);
    }

    public function testYesterdaysCountersAreNotTodays(): void
    {
        $this->counters->increment(1, MailLane::Bulk, $this->daysAgo(1));

        $this->assertSame(0, $this->counters->totalForProvider(1));
        $this->assertSame(1, $this->counters->totalForProvider(1, $this->daysAgo(1)));
    }

    // ── the history the reserve reads (D8) ────────────────────────────

    /**
     * The mailing lane is excluded, and that is the whole point: what the
     * reserve protects is a day's worth of authentication and
     * transactional mail, which is precisely what a publipostage must not
     * be allowed to eat into.
     */
    public function testTheNonBulkHistoryExcludesTheMailingLane(): void
    {
        $this->counters->increment(1, MailLane::Authentication, $this->daysAgo(2));
        $this->counters->increment(1, MailLane::Transactional, $this->daysAgo(2));
        $this->counters->increment(1, MailLane::Bulk, $this->daysAgo(2));

        $this->assertSame([$this->daysAgo(2) => 2], $this->counters->dailyNonBulkTotals(30));
    }

    /**
     * Summed across providers on purpose: a day's authentication traffic
     * does not become smaller because it was spread over two relays.
     */
    public function testTheNonBulkHistorySumsAcrossProviders(): void
    {
        $this->counters->increment(1, MailLane::Authentication, $this->daysAgo(1));
        $this->counters->increment(2, MailLane::Authentication, $this->daysAgo(1));
        $this->counters->increment(MailProvider::LOCAL_ID, MailLane::Transactional, $this->daysAgo(1));

        $this->assertSame([$this->daysAgo(1) => 3], $this->counters->dailyNonBulkTotals(30));
    }

    public function testTheNonBulkHistoryStopsAtTheWindow(): void
    {
        $this->counters->increment(1, MailLane::Authentication, $this->daysAgo(40));
        $this->counters->increment(1, MailLane::Authentication, $this->daysAgo(3));

        $totals = $this->counters->dailyNonBulkTotals(30);

        $this->assertArrayNotHasKey($this->daysAgo(40), $totals);
        $this->assertArrayHasKey($this->daysAgo(3), $totals);
    }

    public function testAnInstallationWithNoHistoryAnswersWithNothing(): void
    {
        $this->assertSame([], $this->counters->dailyNonBulkTotals(30));
    }

    // ── housekeeping ──────────────────────────────────────────────────

    public function testPurgingDropsOnlyWhatIsOlderThanTheCutoff(): void
    {
        $this->counters->increment(1, MailLane::Bulk, $this->daysAgo(10));
        $this->counters->increment(1, MailLane::Bulk, $this->daysAgo(2));

        $this->assertSame(1, $this->counters->purgeOlderThan($this->daysAgo(5)));
        $this->assertSame(1, $this->rowCount());
    }

    public function testADeletedProviderTakesItsCountersWithIt(): void
    {
        $this->counters->increment(1, MailLane::Bulk);
        $this->counters->increment(2, MailLane::Bulk);

        $this->counters->forgetProvider(1);

        $this->assertSame(0, $this->counters->totalForProvider(1));
        $this->assertSame(1, $this->counters->totalForProvider(2));
    }

    private function daysAgo(int $days): string
    {
        return (new \DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d');
    }

    private function rowCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM mail_send_counters');

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
