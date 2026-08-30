<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\MigrationChain;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The chain that finishes a pending migration when nobody is watching.
 *
 * Most tests here leave `base_url` unset, so nothing is written to a
 * socket: what is under test is the bookkeeping that decides WHETHER to
 * emit — the ceiling, the "is a chain already running" guard, and the
 * staleness window that lets a dead chain be replaced. The two that must
 * distinguish "refused to emit" from "emitted and the socket refused"
 * point `base_url` at a real listening socket, because a dead port makes
 * a ceiling test pass for the wrong reason.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MigrationChainTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private MigrationChain $chain;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        foreach ([
            [MigrationChain::MAX_HOPS_SETTING, '800'],
            [MigrationChain::HOPS_SETTING, '0'],
            [MigrationChain::LAST_HOP_SETTING, '0'],
        ] as [$key, $default]) {
            $this->settings->register($key, $default, 'number', $key, 'test', null, null, null, false, 900);
        }
        $this->settings->register('base_url', '', 'text', 'base', 'test', null, null, null, false, 900);

        $this->chain = new MigrationChain($this->settings);
    }

    /** @return array{resource, int} */
    private function listeningSocket(): array
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "could not open a local listening socket: {$errstr}");
        $name = (string) stream_socket_get_name($server, false);

        return [$server, (int) substr($name, (int) strrpos($name, ':') + 1)];
    }

    private function pointBaseUrlAt(int $port): void
    {
        $this->settings->setInternal('base_url', 'http://127.0.0.1:' . $port);
    }

    /**
     * The whole point: a request that was going to show a progress page to
     * nobody starts the chain instead.
     */
    public function testItEmitsAHopWhenNoChainIsRunning(): void
    {
        [$server, $port] = $this->listeningSocket();
        $this->pointBaseUrlAt($port);

        try {
            $this->assertTrue($this->chain->ensureRunning());
            $this->assertSame(1, $this->chain->hopCount());
        } finally {
            fclose($server);
        }
    }

    /**
     * A burst of blocked requests must produce one chain, not one per
     * request — otherwise a shared host reads the result as a denial of
     * service, with account suspension at the end of it.
     */
    public function testASecondRequestDoesNotStartASecondChain(): void
    {
        [$server, $port] = $this->listeningSocket();
        $this->pointBaseUrlAt($port);

        try {
            $this->chain->ensureRunning();
            $this->assertFalse($this->chain->ensureRunning(), 'a chain is already running');
            $this->assertSame(1, $this->chain->hopCount(), 'and no second hop was emitted');
        } finally {
            fclose($server);
        }
    }

    /**
     * What keeps the mechanism self-healing. A chain killed mid-flight — a
     * recycled worker, a slice that threw — would otherwise leave the
     * counter above zero forever, and no later request would ever start
     * another one.
     */
    public function testAChainWithNoRecentHopMayBeReplaced(): void
    {
        [$server, $port] = $this->listeningSocket();
        $this->pointBaseUrlAt($port);
        $this->settings->setInternal(MigrationChain::HOPS_SETTING, '42');
        $this->settings->setInternal(MigrationChain::LAST_HOP_SETTING, (string) (time() - 600));

        try {
            $this->assertTrue($this->chain->ensureRunning());
            // The counter restarted rather than continuing from 42: this is
            // a new chain, and it gets the whole ceiling.
            $this->assertSame(1, $this->chain->hopCount());
        } finally {
            fclose($server);
        }
    }

    /**
     * A ceiling of zero turns the mechanism off — for an environment that
     * cannot host it, a `php -S` server above all. It must be honoured by
     * the ignition exactly as by any hop, against a socket that really is
     * listening so that ignoring it would visibly succeed.
     */
    public function testACeilingOfZeroEmitsNothingAtAll(): void
    {
        [$server, $port] = $this->listeningSocket();
        $this->pointBaseUrlAt($port);
        $this->settings->setInternal(MigrationChain::MAX_HOPS_SETTING, '0');

        try {
            $this->assertFalse($this->chain->ensureRunning());
            $this->assertSame(0, $this->chain->hopCount());
        } finally {
            fclose($server);
        }
    }

    public function testTheChainStopsAtTheCeiling(): void
    {
        [$server, $port] = $this->listeningSocket();
        $this->pointBaseUrlAt($port);
        $this->settings->setInternal(MigrationChain::MAX_HOPS_SETTING, '3');
        $this->settings->setInternal(MigrationChain::HOPS_SETTING, '3');

        try {
            $this->assertFalse($this->chain->continueChain(), 'the ceiling is a ceiling');
            $this->assertSame(3, $this->chain->hopCount());
        } finally {
            fclose($server);
        }
    }

    /**
     * An unset ceiling must not read as zero, or an installation that has
     * never opened the settings page would silently never chain — and the
     * migration would be back to waiting for a human.
     */
    public function testAnUnsetCeilingIsNotReadAsZero(): void
    {
        $this->pdo->exec("DELETE FROM settings WHERE setting_key = '" . MigrationChain::MAX_HOPS_SETTING . "'");
        $this->settings->clearCache();

        // No base_url, so nothing reaches a socket either way; what is
        // asserted is that it got PAST the ceiling and counted a hop.
        $this->assertFalse($this->chain->ensureRunning(), 'no base_url, nothing written');
        $this->assertSame(1, $this->chain->hopCount(), 'but the hop was counted, so the ceiling let it through');
    }

    /**
     * Counted before the attempt, not after: a chain that fails halfway
     * through every slice must still approach its ceiling, or it never
     * stops.
     */
    public function testAHopThatCannotBeWrittenStillConsumesItsBudget(): void
    {
        $this->settings->setInternal('base_url', 'http://127.0.0.1:1');

        $this->assertFalse($this->chain->continueChain());
        $this->assertSame(1, $this->chain->hopCount());
    }

    /**
     * The next migration must start from a clean counter instead of
     * inheriting this one's.
     */
    public function testFinishedResetsTheCounter(): void
    {
        $this->settings->setInternal(MigrationChain::HOPS_SETTING, '17');

        $this->chain->finished();

        $this->assertSame(0, $this->chain->hopCount());
    }

    /**
     * No base_url at all is a supported configuration, not an error: the
     * migration then advances on the progress page's own polling, exactly
     * as it did before this class existed.
     */
    public function testItDegradesQuietlyWithNoBaseUrl(): void
    {
        $this->assertFalse($this->chain->ensureRunning());
    }
}
