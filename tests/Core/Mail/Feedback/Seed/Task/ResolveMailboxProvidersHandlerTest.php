<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Seed\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Mail\Feedback\Seed\Task\ResolveMailboxProvidersHandler;
use Core\Mail\MailService;
use Core\Mail\Transport\MailboxProviderRepository;
use Core\Net\MxLookup;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The only place the MX records of a recipient domain are read (issue
 * #422): a bounded batch, a cache, a conduct on failure — and never the
 * network in a test.
 */
#[Group('database')]
class ResolveMailboxProvidersHandlerTest extends TestCase
{
    private \PDO $pdo;
    private TaskContext $context;
    private MailboxProviderRepository $cache;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->cache = new MailboxProviderRepository($this->pdo);

        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }

    /**
     * A resolver answering from a table, and counting what it was asked.
     *
     * @param array<string, list<string>|null|\Throwable> $answers
     */
    private function lookup(array $answers): MxLookup
    {
        return new class ($answers) implements MxLookup {
            /** @var list<string> */
            public array $asked = [];

            /** @param array<string, list<string>|null|\Throwable> $answers */
            public function __construct(private array $answers)
            {
            }

            public function hostsFor(string $domain): ?array
            {
                $this->asked[] = $domain;
                $answer = array_key_exists($domain, $this->answers) ? $this->answers[$domain] : [];
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }
        };
    }

    /** @return array<string, mixed>|false */
    private function row(string $domain): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM mail_domain_providers WHERE domain = ?');
        $statement->execute([$domain]);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    public function testAPersonalDomainServedByGoogleIsAttributedToGmail(): void
    {
        $this->cache->note('famille.be', new \DateTimeImmutable());

        (new ResolveMailboxProvidersHandler($this->lookup(['famille.be' => ['aspmx.l.google.com']])))
            ->handle([], $this->context);

        $this->assertSame('gmail.com', (new MailboxProviderRepository($this->pdo))->providerOf('famille.be'));
        $this->assertNotNull(($this->row('famille.be') ?: [])['resolved_at'] ?? null);
    }

    /** An MX nobody on the list runs is a resolved answer: « its own name ». */
    public function testAnUnknownProviderIsResolvedToNobody(): void
    {
        $this->cache->note('ecole.be', new \DateTimeImmutable());

        (new ResolveMailboxProvidersHandler($this->lookup(['ecole.be' => ['mx.ecole.be']])))
            ->handle([], $this->context);

        $row = $this->row('ecole.be') ?: [];
        $this->assertNull($row['provider']);
        $this->assertNotNull($row['resolved_at']);
    }

    /**
     * **A failure is recorded, backs off, and escapes nowhere** — neither
     * a null answer nor a resolver that throws may end the task's chain.
     */
    public function testAFailedLookupBacksOffAndTheChainGoesOn(): void
    {
        $this->cache->note('muet.be', new \DateTimeImmutable());
        $this->cache->note('casse.be', new \DateTimeImmutable());

        (new ResolveMailboxProvidersHandler($this->lookup([
            'muet.be' => null,
            'casse.be' => new \RuntimeException('resolver exploded'),
        ])))->handle([], $this->context);

        foreach (['muet.be', 'casse.be'] as $domain) {
            $row = $this->row($domain) ?: [];
            $this->assertSame(1, (int) $row['failures'], $domain);
            $this->assertSame('no_answer', $row['last_error'], $domain);
            $this->assertNotNull($row['retry_after'], $domain);
            $this->assertNull($row['resolved_at'], $domain);
        }

        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = '"
                    . ResolveMailboxProvidersHandler::TASK_KEY . "'"
            )?->fetchColumn()
        );
    }

    public function testTheBackOffDoublesUpToTheTtl(): void
    {
        $this->assertSame(
            [1, 2, 4, 7, 7],
            array_map(ResolveMailboxProvidersHandler::backoffDays(...), [1, 2, 3, 4, 10])
        );
    }

    /** A fresh answer is not asked again: the cache is the point. */
    public function testAFreshAnswerIsNotAskedAgain(): void
    {
        $this->cache->note('famille.be', new \DateTimeImmutable());
        $this->cache->recordResolved('famille.be', 'gmail.com', new \DateTimeImmutable('-1 day'));
        $lookup = $this->lookup([]);

        (new ResolveMailboxProvidersHandler($lookup))->handle([], $this->context);

        $this->assertSame([], $lookup->asked);
    }

    /**
     * **Out of time is not a failure.** A spent budget leaves the rest for
     * tomorrow without starting their back-off.
     */
    public function testASpentBudgetLeavesTheRestUntouched(): void
    {
        $this->cache->note('famille.be', new \DateTimeImmutable());
        $lookup = $this->lookup(['famille.be' => ['aspmx.l.google.com']]);

        (new ResolveMailboxProvidersHandler($lookup, 0.0))->handle([], $this->context);

        $this->assertSame([], $lookup->asked);
        $row = $this->row('famille.be') ?: [];
        $this->assertSame(0, (int) $row['failures']);
        $this->assertNull($row['retry_after']);
    }

    public function testOneRunAsksABoundedNumberOfDomains(): void
    {
        for ($i = 0; $i < ResolveMailboxProvidersHandler::BATCH + 5; $i++) {
            $this->cache->note('famille' . $i . '.be', new \DateTimeImmutable());
        }
        $lookup = $this->lookup([]);

        (new ResolveMailboxProvidersHandler($lookup))->handle([], $this->context);

        $this->assertCount(ResolveMailboxProvidersHandler::BATCH, $lookup->asked);
    }

    /** The seed boxes' own domains are attributed too, before any mailing notes them. */
    public function testTheSeedBoxesDomainsAreAskedAbout(): void
    {
        (new SeedCopyRepository($this->pdo, $this->encryption))
            ->claim('envoi-1', 'temoin@unite-scoute.be', new \DateTimeImmutable('-1 day'));
        $lookup = $this->lookup(['unite-scoute.be' => ['aspmx.l.google.com']]);

        (new ResolveMailboxProvidersHandler($lookup))->handle([], $this->context);

        $this->assertSame(['unite-scoute.be'], $lookup->asked);
        $this->assertSame('gmail.com', (new MailboxProviderRepository($this->pdo))->providerOf('unite-scoute.be'));
    }

    /**
     * A seed box's domain is dated by its last send, not by the run: the
     * retention on the RGPD page counts from the site's last message, and
     * one past it is not noted back in every day.
     */
    public function testASeedDomainIsDatedByItsLastSend(): void
    {
        $seeds = new SeedCopyRepository($this->pdo, $this->encryption);
        $seeds->claim('envoi-1', 'temoin@recent.be', new \DateTimeImmutable('-3 days'));
        $seeds->claim(
            'envoi-2',
            'temoin@ancien.be',
            new \DateTimeImmutable('-' . (ResolveMailboxProvidersHandler::RETENTION_DAYS + 5) . ' days')
        );

        (new ResolveMailboxProvidersHandler($this->lookup([])))->handle([], $this->context);

        $recent = $this->row('recent.be');
        $this->assertNotFalse($recent);
        $this->assertLessThan(
            (new \DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s'),
            (string) $recent['noted_at'],
            'Noted at its last send, three days ago, not today.'
        );
        $this->assertFalse($this->row('ancien.be'));
    }

    /** The retention: a domain nobody has written to for six months is forgotten. */
    public function testADomainNobodyWritesToAnyMoreIsForgotten(): void
    {
        $this->cache->note(
            'parti.be',
            new \DateTimeImmutable('-' . (ResolveMailboxProvidersHandler::RETENTION_DAYS + 1) . ' days')
        );

        (new ResolveMailboxProvidersHandler($this->lookup([])))->handle([], $this->context);

        $this->assertFalse($this->row('parti.be'));
    }

    /** Counters only: a personal domain can name a family, so it stays out of the journal. */
    public function testTheJournalCarriesCountersAndNoDomain(): void
    {
        $this->cache->note('famille-dupont.be', new \DateTimeImmutable());

        (new ResolveMailboxProvidersHandler($this->lookup(['famille-dupont.be' => ['aspmx.l.google.com']])))
            ->handle([], $this->context);

        $statement = $this->pdo->prepare(
            "SELECT context FROM event_log WHERE event_type = 'mail_domain_providers_resolved'"
        );
        $statement->execute();
        $context = (string) $statement->fetchColumn();

        $this->assertStringContainsString('"attributed":1', $context);
        $this->assertStringNotContainsString('dupont', $context);
    }
}
