<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Probe;

use Core\Mail\Feedback\Bounce\BounceCategory;
use Core\Mail\Probe\MailProbeRepository;
use Core\Mail\Probe\MailProbeSender;
use Core\Mail\Probe\MailProbeVerdict;
use Core\Mail\Transport\MailLane;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The probe history's two first-write-wins doors, against the engine an
 * installation runs.
 *
 * `recordVerdict()` and `recordBounce()` each answer « was I the call that
 * wrote it? » from `rowCount()` after a guarded UPDATE. That figure is
 * where SQLite and MySQL disagree: SQLite reports the rows the WHERE
 * MATCHED, MySQL and MariaDB the rows actually CHANGED, since the site's
 * connection does not set `PDO::MYSQL_ATTR_FOUND_ROWS`
 * (`Tests\Core\Mail\Feedback\Bounce\BounceSendReceiptMysqlTest` is what
 * that costs when it is overlooked). Here the two readings coincide only
 * because the guard — `verdict IS NULL`, `bounce_at IS NULL` — makes every
 * matched row one whose value moves from NULL to something. The premise
 * test below checks the engine really answers « changed »; the others
 * check the guard keeps the two readings together.
 *
 * `MailProbeRepositoryTest` covers the same methods on SQLite.
 */
#[Group('database')]
final class MailProbeRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private MailProbeRepository $probes;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->probes = new MailProbeRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    /**
     * First: an UPDATE writing the value a row already holds reports no
     * row on this connection. If that ever answers 1, FOUND_ROWS was
     * turned on and the rest of this class no longer tells the two
     * readings of `rowCount()` apart.
     */
    public function testThisEngineReportsChangedRowsRatherThanMatchedOnes(): void
    {
        $id = $this->sent('SM-ABCDEF', '2026-09-19 10:00:00');
        $this->pdo->prepare('UPDATE mail_probes SET verdict = ? WHERE id = ?')->execute(['inbox', $id]);

        $same = $this->pdo->prepare('UPDATE mail_probes SET verdict = ? WHERE id = ?');
        $same->execute(['inbox', $id]);

        $this->assertSame(0, $same->rowCount());
    }

    /**
     * The operator's first answer stands, and only the call that wrote it
     * is told so — including a second press of the SAME button, which on
     * a matched-rows reading with no guard would report a second success.
     */
    public function testOnlyTheFirstVerdictIsWrittenAndReportedAsWritten(): void
    {
        $id = $this->sent('SM-ABCDEF', '2026-09-19 10:00:00');

        $this->assertTrue($this->probes->recordVerdict($id, MailProbeVerdict::Spam, $this->at('10:05:00')));
        $this->assertFalse($this->probes->recordVerdict($id, MailProbeVerdict::Spam, $this->at('10:06:00')));
        $this->assertFalse($this->probes->recordVerdict($id, MailProbeVerdict::Inbox, $this->at('10:07:00')));

        $probe = $this->probes->find($id);
        $this->assertNotNull($probe);
        $this->assertSame(MailProbeVerdict::Spam, $probe->verdict);
        $this->assertSame('2026-09-19 10:05:00', $probe->verdictAt?->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->probes->pending());
    }

    public function testAVerdictForAProbeThatDoesNotExistIsNotReportedAsWritten(): void
    {
        $id = $this->sent('SM-ABCDEF', '2026-09-19 10:00:00');

        $this->assertFalse($this->probes->recordVerdict($id + 1000, MailProbeVerdict::Inbox, new \DateTimeImmutable()));
    }

    /**
     * The first rejection is the one kept, even when the same bounce is
     * read twice — the identical-values case is the one where changed and
     * matched would part if the guard were ever loosened.
     */
    public function testOnlyTheFirstBounceIsAttached(): void
    {
        $id = $this->sent('SM-ABCDEF', '2026-09-19 10:00:00');
        $at = new \DateTimeImmutable('2026-09-19 10:03:00');

        $this->assertTrue($this->probes->recordBounce($id, BounceCategory::MailboxFull, '4.2.2', $at));
        $this->assertFalse($this->probes->recordBounce($id, BounceCategory::MailboxFull, '4.2.2', $at));
        $this->assertFalse($this->probes->recordBounce(
            $id,
            BounceCategory::NoSuchAddress,
            '5.1.1',
            new \DateTimeImmutable('2026-09-19 11:00:00')
        ));

        $bounce = $this->probes->find($id)?->bounce;
        $this->assertNotNull($bounce);
        $this->assertSame(BounceCategory::MailboxFull, $bounce->category);
        $this->assertSame('4.2.2', $bounce->statusCode);
        $this->assertSame('2026-09-19 10:03:00', $bounce->at->format('Y-m-d H:i:s'));
    }

    /**
     * A repeated code answers the newest run, as on SQLite.
     *
     * The comparison on `code` is the collation's here
     * (`utf8mb4_unicode_ci`, case-insensitive) and byte-wise on SQLite. The
     * two engines can only part on a code in another case, and
     * `MailProbeSender::codeIn()` never extracts one — asserted here so
     * that the day it starts to, this test says the engines now disagree.
     */
    public function testARepeatedCodeAnswersTheNewestRun(): void
    {
        $this->sent('SM-ABCDEF', '2026-06-01 10:00:00');
        $newest = $this->sent('SM-ABCDEF', '2026-09-19 10:00:00');

        $this->assertSame($newest, $this->probes->findByCode('SM-ABCDEF')?->id);
        $this->assertNull(MailProbeSender::codeIn('Re: sm-abcdef'), 'only an upper-case code ever reaches the lookup');
    }

    public function testTheHistoryHonoursItsBoundLimitNewestFirst(): void
    {
        $this->sent('SM-AAAAAA', '2026-09-19 10:00:00');
        $second = $this->sent('SM-BBBBBB', '2026-09-19 10:01:00');
        $third = $this->sent('SM-CCCCCC', '2026-09-19 10:02:00');

        $this->assertSame([$third, $second], array_map(static fn($p) => $p->id, $this->probes->recent(2)));
        $this->assertSame(3, $this->probes->count());
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-19 ' . $time);
    }

    private function sent(string $code, string $at): int
    {
        return $this->probes->record(
            $code,
            'parent@exemple.be',
            null,
            'Relais principal',
            MailLane::Transactional,
            new \DateTimeImmutable($at)
        );
    }
}
