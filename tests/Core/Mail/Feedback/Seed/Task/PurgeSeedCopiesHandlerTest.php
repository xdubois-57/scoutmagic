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
use Core\Mail\Feedback\Seed\DomainRouting;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Mail\Feedback\Seed\SeedVerdict;
use Core\Mail\Feedback\Seed\Task\PurgeSeedCopiesHandler;
use Core\Mail\MailService;
use Core\Mail\Transport\DomainPreferences;
use Core\Mail\Transport\MailLane;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * « Not yet » and « never » are two different answers, and this is where
 * the site decides which one it is looking at (roadmap IT-07).
 */
#[Group('database')]
class PurgeSeedCopiesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private TaskContext $context;
    private SeedCopyRepository $copies;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->copies = new SeedCopyRepository($this->pdo, $encryption);

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        foreach (
            [
                [DomainRouting::SETTING_AUTOMATIC, '0', 'boolean', 59],
                [DomainPreferences::SETTING_KEY, '', 'text', 60],
            ] as [$key, $default, $type, $order]
        ) {
            $this->settings->register($key, $default, $type, $key, '', null, null, null, false, $order);
        }

        $this->context = $this->contextWatching([['INBOX', 'Junk']]);
    }

    /**
     * A context whose seed boxes watch the folders given.
     *
     * **The default is one box that CAN see its junk folder**, because
     * that is the state in which the automatic routing is allowed to act
     * at all: a unit with no seed box, or with one read only in its
     * inbox, reports junk-filed mail as « jamais arrivé », and the sweep
     * stands down rather than rerouting a provider on that. A fixture
     * without boxes would have every routing test below assert the
     * stand-down instead of the routing, while looking like it asserted
     * the routing.
     *
     * @param list<list<string>> $watched one entry per declared box
     */
    private function contextWatching(array $watched): TaskContext
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $moduleManager = $this->createMock(\Core\Module\ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn(['inbound_mail']);
        $capabilities = new \Core\Scheduler\TaskCapabilities($moduleManager);
        $capabilities->register(
            \Modules\InboundMail\Api\InboundMailInterface::class,
            'inbound_mail',
            function () use ($watched): object {
                $gateway = $this->createStub(\Modules\InboundMail\Api\InboundMailInterface::class);
                $gateway->method('probeAddressesFor')
                    ->willReturn(array_map(
                        static fn (int $index): string => 'temoin' . $index . '@gmail.com',
                        array_keys($watched)
                    ));
                $gateway->method('watchedFoldersFor')->willReturn($watched);

                return $gateway;
            }
        );

        return new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir(),
            null,
            $capabilities
        );
    }

    /** Two relays on the mailing lane, so « applying » has somewhere to go. */
    private function twoRelays(): int
    {
        $ids = [];
        foreach (['Premier', 'Second'] as $position => $name) {
            $statement = $this->pdo->prepare(
                'INSERT INTO mail_providers (name, secret_prefix, batch_size, batch_interval_minutes)
                 VALUES (?, ?, 50, 10)'
            );
            $statement->execute([$name, 'mail_provider_' . $name]);
            $ids[] = $id = (int) $this->pdo->lastInsertId();

            $entry = $this->pdo->prepare(
                'INSERT INTO mail_lane_entries (lane, provider_id, position, is_enabled) VALUES (?, ?, ?, 1)'
            );
            $entry->execute([MailLane::Bulk->value, $id, $position + 1]);
        }

        return $ids[1];
    }

    /** A provider that has plainly been filing this unit's mailings away. */
    private function troubledProvider(string $address = 'temoin@gmail.com'): void
    {
        for ($i = 0; $i < DomainRouting::MINIMUM_RUNS; $i++) {
            $sent = new \DateTimeImmutable('-1 day');
            $this->copies->claim('envoi-' . $i, $address, $sent);
            $this->copies->recordLanding('envoi-' . $i, $address, 'Junk', $sent);
        }
    }

    /**
     * **The decision the screen must not be left to make.** A copy sent
     * and not yet found is the ordinary state of every mailing still
     * going out; only elapsed time turns it into a refusal. Written down
     * once, here, it stays what it was — where a screen computing it on
     * the fly would change its own verdict while somebody watched.
     */
    public function testACopyNobodyEverSawBecomesNeverArrived(): void
    {
        $this->copies->claim('vieux', 'temoin@gmail.com', new \DateTimeImmutable('-5 days'));

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame(SeedVerdict::Missing, $this->copies->forRun('vieux')[0]->verdict);
    }

    /**
     * **And a recent one is left alone**, which is the half that matters
     * more: an hour's patience would manufacture « jamais arrivé » for
     * copies that turn up perfectly well, and that is the one error this
     * screen must not make.
     */
    public function testARecentCopyIsStillWaiting(): void
    {
        $this->copies->claim('recent', 'temoin@gmail.com', new \DateTimeImmutable('-1 hour'));

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame(SeedVerdict::Pending, $this->copies->forRun('recent')[0]->verdict);
    }

    /** An answer already given is never overwritten. */
    public function testACopyAlreadyFoundKeepsItsVerdict(): void
    {
        $sent = new \DateTimeImmutable('-5 days');
        $this->copies->claim('trouve', 'temoin@gmail.com', $sent);
        $this->copies->recordLanding('trouve', 'temoin@gmail.com', 'Junk', $sent);

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame(SeedVerdict::Spam, $this->copies->forRun('trouve')[0]->verdict);
    }

    public function testResultsPastTheRetentionAreDropped(): void
    {
        $this->copies->claim(
            'antique',
            'temoin@gmail.com',
            new \DateTimeImmutable('-' . (PurgeSeedCopiesHandler::RETENTION_DAYS + 5) . ' days')
        );

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertCount(0, $this->copies->forRun('antique'));
    }

    /**
     * The window the screen shows is narrower than the one kept, so
     * « depuis quand ? » has an answer.
     */
    public function testTheRetentionIsWiderThanTheScreenReportsOn(): void
    {
        $this->assertGreaterThan(30, PurgeSeedCopiesHandler::RETENTION_DAYS);
        $this->assertLessThan(PurgeSeedCopiesHandler::RETENTION_DAYS, PurgeSeedCopiesHandler::GIVE_UP_AFTER_DAYS);
    }

    /** A sweep that did nothing says nothing. */
    public function testASweepWithNothingToDoWritesNoJournalLine(): void
    {
        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame(0, $this->scalar("SELECT COUNT(*) FROM event_log WHERE event_type = 'mail_seed_copies_swept'"));
    }

    /** And one that did says so — in counters, never an address. */
    public function testTheSweepJournalsCountsAndNoAddress(): void
    {
        $this->copies->claim('vieux', 'temoin-secret@gmail.com', new \DateTimeImmutable('-5 days'));

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $statement = $this->pdo->prepare("SELECT context FROM event_log WHERE event_type = 'mail_seed_copies_swept'");
        $statement->execute();
        $row = (string) $statement->fetchColumn();

        $this->assertStringNotContainsString('temoin-secret', $row);
        $this->assertSame(1, json_decode($row, true)['given_up']);
    }

    /** It re-arms itself, or it runs once and never again. */
    public function testItRearmsItself(): void
    {
        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame(
            1,
            $this->scalar("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'purge_mail_seed_copies'")
        );
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    // ── the automatism, and its two locks (D13) ───────────────────────

    /**
     * **With the switch off, nothing moves however bad the figures are.**
     * That is the default state of every installation, and it is the
     * whole of D13: the remedy — splitting a sender's volume — costs each
     * relay the regular traffic its reputation rests on, and that price
     * is not the site's to pay unasked.
     */
    public function testWithTheSwitchOffNothingIsRouted(): void
    {
        $this->twoRelays();
        $this->troubledProvider();

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertNull((new DomainPreferences($this->settings))->forDomain('gmail.com'));
    }

    public function testWithTheSwitchOnATroubledProviderIsRouted(): void
    {
        $second = $this->twoRelays();
        $this->troubledProvider();
        $this->settings->setInternal(DomainRouting::SETTING_AUTOMATIC, '1');

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame($second, (new DomainPreferences($this->settings))->forDomain('gmail.com'));
    }

    /**
     * **The switch is re-examined here, not only where it was armed.**
     *
     * The screen refuses to arm the automatism on a measurement that
     * cannot support it, but the configuration moves afterwards and this
     * is what acts: a box whose junk folder somebody took out of the
     * watched list leaves the switch on over evidence that reports
     * junk-filed mail as « jamais arrivé ». The sweep would then reroute
     * a whole provider's traffic on exactly the reading the guard exists
     * to refuse — unattended, and on the day nobody was looking.
     */
    public function testABlindSeedBoxStandsTheAutomaticRoutingDown(): void
    {
        $this->twoRelays();
        $this->troubledProvider();
        $this->settings->setInternal(DomainRouting::SETTING_AUTOMATIC, '1');

        (new PurgeSeedCopiesHandler())->handle([], $this->contextWatching([['INBOX']]));

        $this->assertNull((new DomainPreferences($this->settings))->forDomain('gmail.com'));
        $this->assertSame(
            1,
            $this->scalar("SELECT COUNT(*) FROM event_log WHERE event_type = 'mail_seed_routing_stood_down'"),
            'and it is dated rather than silent: an operator wondering why nothing moved has an answer.'
        );
    }

    /**
     * **No box at all is the same answer**, and it used to be a different
     * one: `boxesBlindToSpam()` counts blind boxes, so with no boxes it
     * counts zero — « nothing wrong » and « nothing measured » giving
     * the same figure, which is the oldest trap on this page.
     */
    public function testNoSeedBoxAtAllStandsTheAutomaticRoutingDownToo(): void
    {
        $this->twoRelays();
        $this->troubledProvider();
        $this->settings->setInternal(DomainRouting::SETTING_AUTOMATIC, '1');

        (new PurgeSeedCopiesHandler())->handle([], $this->contextWatching([]));

        $this->assertNull((new DomainPreferences($this->settings))->forDomain('gmail.com'));
    }

    /** Standing the routing down never costs the purge its next run. */
    public function testTheSweepStillDoesItsOwnWorkWhenTheRoutingStandsDown(): void
    {
        $this->copies->claim('vieux', 'temoin0@gmail.com', new \DateTimeImmutable('-5 days'));
        $this->settings->setInternal(DomainRouting::SETTING_AUTOMATIC, '1');

        (new PurgeSeedCopiesHandler())->handle([], $this->contextWatching([['INBOX']]));

        $this->assertSame(SeedVerdict::Missing, $this->copies->forRun('vieux')[0]->verdict);
    }

    /**
     * **Once per domain, and this is the case that matters.** `apply()`
     * moves a domain to the NEXT relay of the chain, so a sweep that
     * applied again every day on a provider that stayed troubled would
     * walk that domain around the chain for ever — changing where a
     * unit's mail comes from daily, which destroys exactly the regular
     * traffic D13 weighs the remedy against.
     */
    public function testARoutedDomainIsNotRoutedAgainTheNextDay(): void
    {
        $second = $this->twoRelays();
        $this->troubledProvider();
        $this->settings->setInternal(DomainRouting::SETTING_AUTOMATIC, '1');

        // An EVEN number of further sweeps, deliberately. With two relays
        // the chain wraps, so three sweeps would land back on the second
        // one and the assertion would hold whether the guard was there or
        // not — a test that cannot fail. Two is the discriminating count.
        (new PurgeSeedCopiesHandler())->handle([], $this->context);
        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame(
            $second,
            (new DomainPreferences($this->settings))->forDomain('gmail.com'),
            'Two sweeps, one decision.'
        );
    }

    /** A provider that is fine is left where the lane put it. */
    public function testAProviderWithNothingWrongIsNotRouted(): void
    {
        $this->twoRelays();
        for ($i = 0; $i < DomainRouting::MINIMUM_RUNS; $i++) {
            $sent = new \DateTimeImmutable('-1 day');
            $this->copies->claim('envoi-' . $i, 'temoin@gmail.com', $sent);
            $this->copies->recordLanding('envoi-' . $i, 'temoin@gmail.com', 'INBOX', $sent);
        }
        $this->settings->setInternal(DomainRouting::SETTING_AUTOMATIC, '1');

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertNull((new DomainPreferences($this->settings))->forDomain('gmail.com'));
    }

    /**
     * The change is journalled at `security`, like every other change to
     * how mail leaves this site — and it names domains and relays, which
     * are companies, never an address, which is a person.
     */
    public function testRoutingIsJournalledAtSecurityWithoutAnyAddress(): void
    {
        $this->twoRelays();
        $this->troubledProvider();
        $this->settings->setInternal(DomainRouting::SETTING_AUTOMATIC, '1');

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $statement = $this->pdo->prepare(
            "SELECT level, context FROM event_log WHERE event_type = 'mail_seed_routing_applied'"
        );
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertSame('security', $row['level']);
        $this->assertStringContainsString('gmail.com', (string) $row['context']);
        $this->assertStringNotContainsString('temoin@', (string) $row['context']);
    }

    /** And a sweep that routed nothing says nothing. */
    public function testASweepThatRoutedNothingWritesNoRoutingLine(): void
    {
        $this->twoRelays();
        $this->settings->setInternal(DomainRouting::SETTING_AUTOMATIC, '1');

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'mail_seed_routing_applied'"
        );
        $statement->execute();

        $this->assertSame(0, (int) $statement->fetchColumn());
    }

    /**
     * **A unit with one relay is not routed anywhere**, switch or no
     * switch: there is nowhere to route to, and a sweep that wrote a
     * decision naming the relay already in use would leave a line on the
     * screen saying nothing changed.
     */
    public function testWithASingleRelayTheSwitchChangesNothing(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO mail_providers (name, secret_prefix, batch_size, batch_interval_minutes)
             VALUES (?, ?, 50, 10)'
        );
        $statement->execute(['Premier', 'mail_provider_premier']);
        $entry = $this->pdo->prepare(
            'INSERT INTO mail_lane_entries (lane, provider_id, position, is_enabled) VALUES (?, ?, 1, 1)'
        );
        $entry->execute([MailLane::Bulk->value, (int) $this->pdo->lastInsertId()]);

        $this->troubledProvider();
        $this->settings->setInternal(DomainRouting::SETTING_AUTOMATIC, '1');

        (new PurgeSeedCopiesHandler())->handle([], $this->context);

        $this->assertSame([], (new DomainPreferences($this->settings))->all());
    }
}
