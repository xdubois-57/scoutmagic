<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Mail\Transport\MailboxProviderRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The cache of which provider hosts a recipient domain (issue #422): what
 * the send path may write, what the task reads back, and what it forgets.
 */
#[Group('database')]
class MailboxProviderRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private MailboxProviderRepository $repository;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new MailboxProviderRepository($this->pdo);
        $this->now = new \DateTimeImmutable('2026-09-26 10:00:00');
    }

    private function column(string $sql): mixed
    {
        return $this->pdo->query($sql)?->fetchColumn();
    }

    public function testANewDomainIsNotedOnceWithNoProvider(): void
    {
        $this->assertTrue($this->repository->note('Famille.BE', $this->now));
        $this->assertFalse($this->repository->note('famille.be', $this->now));

        $this->assertSame(1, (int) $this->column('SELECT COUNT(*) FROM mail_domain_providers'));
        $this->assertSame('famille.be', $this->column('SELECT domain FROM mail_domain_providers'));
        $this->assertNull($this->column('SELECT provider FROM mail_domain_providers'));
        $this->assertNull($this->repository->providerOf('famille.be'));
    }

    /** A malformed address yields '' or junk; neither is a domain to ask about. */
    public function testSomethingThatIsNotADomainIsNeverNoted(): void
    {
        $this->assertFalse($this->repository->note('', $this->now));
        $this->assertFalse($this->repository->note('pas un domaine', $this->now));

        $this->assertSame(0, (int) $this->column('SELECT COUNT(*) FROM mail_domain_providers'));
    }

    /** Two processes noting the same domain: the second one's insert must not throw. */
    public function testADomainAnotherProcessNotedFirstIsNotAnError(): void
    {
        $this->repository->providerOf('warm.up'); // loads the (empty) cache
        (new MailboxProviderRepository($this->pdo))->note('famille.be', $this->now);

        $this->assertTrue($this->repository->note('famille.be', $this->now));
        $this->assertSame(1, (int) $this->column('SELECT COUNT(*) FROM mail_domain_providers'));
    }

    public function testAResolvedProviderIsWhatTheSendPathReads(): void
    {
        $this->repository->note('famille.be', $this->now);
        $this->repository->recordResolved('famille.be', 'gmail.com', $this->now);

        $this->assertSame('gmail.com', (new MailboxProviderRepository($this->pdo))->providerOf('famille.be'));
        $this->assertSame(1, $this->repository->countAttributed());
    }

    /** gmail.com under gmail.com moved nobody, and the screen must not count it. */
    public function testADomainThatIsItsOwnProviderIsNotCountedAsAttributed(): void
    {
        $this->repository->note('gmail.com', $this->now);
        $this->repository->recordResolved('gmail.com', 'gmail.com', $this->now);
        $this->repository->note('inconnu.be', $this->now);
        $this->repository->recordResolved('inconnu.be', null, $this->now);

        $this->assertSame(0, $this->repository->countAttributed());
    }

    public function testTheUnresolvedComeFirstThenTheStaleAndNeverTheFresh(): void
    {
        foreach (['frais.be', 'rassis.be', 'neuf.be'] as $domain) {
            $this->repository->note($domain, $this->now);
        }
        $this->repository->recordResolved('frais.be', 'gmail.com', $this->now->modify('-1 day'));
        $this->repository->recordResolved('rassis.be', 'gmail.com', $this->now->modify('-10 days'));

        $due = $this->repository->due($this->now, $this->now->modify('-7 days'), 10);

        $this->assertSame(['neuf.be', 'rassis.be'], array_column($due, 'domain'));
    }

    /**
     * **A failure keeps the last good answer.** A resolver down today says
     * nothing about where yesterday's mail went.
     */
    public function testAFailureKeepsTheLastAnswerAndBacksOff(): void
    {
        $this->repository->note('famille.be', $this->now);
        $this->repository->recordResolved('famille.be', 'gmail.com', $this->now->modify('-10 days'));
        $this->repository->recordFailure('famille.be', 'no_answer', $this->now->modify('+1 day'));

        $this->assertSame('gmail.com', (new MailboxProviderRepository($this->pdo))->providerOf('famille.be'));
        $this->assertSame([], $this->repository->due($this->now, $this->now->modify('-7 days'), 10));
        $this->assertSame(
            [['domain' => 'famille.be', 'failures' => 1]],
            $this->repository->due($this->now->modify('+2 days'), $this->now->modify('-7 days'), 10)
        );
        $this->assertSame('no_answer', $this->column('SELECT last_error FROM mail_domain_providers'));
    }

    public function testASuccessClearsTheFailures(): void
    {
        $this->repository->note('famille.be', $this->now);
        $this->repository->recordFailure('famille.be', 'no_answer', $this->now->modify('-1 hour'));
        $this->repository->recordResolved('famille.be', 'outlook.com', $this->now);

        $this->assertSame(0, (int) $this->column('SELECT failures FROM mail_domain_providers'));
        $this->assertNull($this->column('SELECT retry_after FROM mail_domain_providers'));
    }

    /**
     * The retention runs on the last send, and a send refreshes it — at
     * most monthly, so a mailing does not write a date per message.
     */
    public function testAStaleNoteIsRefreshedAndOnlyTheForgottenArePurged(): void
    {
        $this->repository->note('actif.be', $this->now->modify('-200 days'));
        $this->repository->note('parti.be', $this->now->modify('-200 days'));

        $fresh = new MailboxProviderRepository($this->pdo);
        $fresh->note('actif.be', $this->now);

        $this->assertSame(1, $fresh->purgeNotedBefore($this->now->modify('-180 days')));
        $this->assertSame('actif.be', $this->column('SELECT domain FROM mail_domain_providers'));
    }

    public function testARecentNoteIsNotRewritten(): void
    {
        $this->repository->note('famille.be', $this->now->modify('-3 days'));
        (new MailboxProviderRepository($this->pdo))->note('famille.be', $this->now);

        $this->assertSame(
            $this->now->modify('-3 days')->format('Y-m-d H:i:s'),
            $this->column('SELECT noted_at FROM mail_domain_providers')
        );
    }
}
