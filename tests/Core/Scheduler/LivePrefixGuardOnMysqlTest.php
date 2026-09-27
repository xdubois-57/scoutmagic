<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Scheduler\SchedulerRepository;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * `hasLiveStartingWith()` against the engine an installation actually runs.
 *
 * **This exists because the SQLite-backed version of the same test passed
 * while the query was a syntax error in production.** The guard was
 * written as `LIKE ? ESCAPE '\'`; MySQL reads the backslash inside that
 * literal as escaping the closing quote and refuses the whole statement,
 * while SQLite is happy to take it as a literal backslash. Every test
 * that exercised the guard ran on SQLite, so all of them were green and
 * the reenrollment campaign would have scheduled no e-mail at all.
 *
 * That is the trap `.claude/skills/steward/SKILL.md` names — several CI
 * jobs are handed MySQL 8 while a developer's suite falls back to the
 * MariaDB this container starts, and a divergence between them "will sit
 * there staying green". A prefix match is exactly the kind of SQL where
 * the two engines disagree, so it is tested where it runs.
 *
 * Against `scheduled_actions` as the migration builds it
 * (`Tests\UsesProductionEngine`): its collation, its ENUM status and its
 * indexes are part of what decides how `LIKE` matches, so a copy written
 * out here would judge a table no site has.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class LivePrefixGuardOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
    }

    public function testTheGuardRunsAtAllOnThisEngine(): void
    {
        $this->queue('opening:2027-05-15', 'pending');

        $this->assertTrue(
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', 'opening:2027-05-15'),
            'A guard that cannot be parsed by the server is a hand-over that never happens.'
        );
    }

    public function testAContinuationOfTheSameChainIsSeenThroughItsPrefix(): void
    {
        $this->queue('opening:2027-05-15:37', 'pending');

        $this->assertTrue(
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', 'opening:2027-05-15')
        );
    }

    public function testAnotherHandOverIsNotMistakenForThisOne(): void
    {
        $this->queue('closing:2027-05-15', 'pending');

        $this->assertFalse(
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', 'opening:2027-05-15')
        );
    }

    public function testADrainedChainIsNotLive(): void
    {
        $this->queue('opening:2027-05-15', 'done');

        $this->assertFalse(
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', 'opening:2027-05-15')
        );
    }

    public function testAChainBeingRunRightNowCounts(): void
    {
        $this->queue('opening:2027-05-15', 'processing');

        $this->assertTrue(
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', 'opening:2027-05-15'),
            'A batch claimed a moment ago is a hand-over in flight.'
        );
    }

    /**
     * A reference is data, not a pattern: `%` in the prefix must match a
     * literal `%`, and the escape character must match itself.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function patternsInsideAReference(): array
    {
        return [
            'a percent matches itself' => ['100%:2027', '100%:2027', true],
            // The stored reference has no percent; a prefix carrying one
            // must therefore not match it. Unescaped, '100%' would.
            'a percent is not a wildcard' => ['100abc:2027', '100%', false],
            'an underscore matches itself' => ['a_b:2027', 'a_b', true],
            'an underscore is not any character' => ['axb:2027', 'a_b', false],
            'the escape character matches itself' => ['a!b:2027', 'a!b', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('patternsInsideAReference')]
    public function testAReferenceIsNeverReadAsAPattern(string $stored, string $prefix, bool $expected): void
    {
        $this->queue($stored, 'pending');

        $this->assertSame(
            $expected,
            $this->repository()->hasLiveStartingWith('registration', 'send_reenrollment_emails', $prefix)
        );
    }

    // ── harness ───────────────────────────────────────────────────────

    private function repository(): SchedulerRepository
    {
        return new SchedulerRepository($this->pdo);
    }

    private function queue(string $reference, string $status): void
    {
        // `run_at` has no default in the declared schema: every row the
        // scheduler queues says when it is due.
        $stmt = $this->pdo->prepare(
            'INSERT INTO scheduled_actions (module_id, task_key, reference, status, run_at) VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute(['registration', 'send_reenrollment_emails', $reference, $status]);
    }
}
