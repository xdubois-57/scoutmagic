<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Modules\InboundMail\Task\SyncMailboxesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * « Intervalle entre deux relèves » — the delay between one poll of a
 * mailbox and the next, as a setting rather than a constant.
 *
 * Two things are pinned here, and the second is the one that makes the
 * field believable. The interval has to be **clamped wherever it is read**,
 * because the row can be written by a restore or by hand as well as by the
 * form, and a task that re-arms itself from an unchecked number can re-arm
 * itself for the next century. And a **shortened** interval has to take
 * effect without waiting out the old one: the chain only ever re-arms
 * itself at the end of a run, so a unit going from six hours to fifteen
 * minutes would otherwise watch nothing happen for six hours and have no
 * way to tell the setting from a broken one.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SyncMailboxesIntervalTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SettingRepository $settingRepository;
    private SchedulerService $scheduler;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->settingRepository);
        $this->scheduler = new SchedulerService(new SchedulerRepository($this->pdo));

        $this->settings->register(
            SyncMailboxesHandler::SETTING_INTERVAL_MINUTES,
            (string) SyncMailboxesHandler::DEFAULT_INTERVAL_MINUTES,
            'number',
            'Intervalle entre deux relèves du courrier (minutes)',
            'Test',
            'inbound_mail'
        );
    }

    private function setInterval(string $minutes): void
    {
        $this->settingRepository->updateValue('inbound_mail', SyncMailboxesHandler::SETTING_INTERVAL_MINUTES, $minutes);
        $this->settings->clearCache();
    }

    /**
     * @return array<string, mixed>
     */
    private function pending(): array
    {
        $row = $this->scheduler->find('inbound_mail', SyncMailboxesHandler::TASK_KEY, 'quarter_hourly');
        $this->assertNotNull($row, 'The chain must always leave exactly one run queued.');

        return $row;
    }

    private function secondsUntilPendingRun(): int
    {
        return (new \DateTimeImmutable((string) $this->pending()['run_at']))->getTimestamp() - time();
    }

    // ── Reading the setting ─────────────────────────────────────────────

    public function testAnUnansweredSettingMeansTheFifteenMinuteDefault(): void
    {
        $this->assertSame(15 * 60, SyncMailboxesHandler::intervalSeconds($this->settings));
    }

    public function testABlankSettingMeansTheDefaultToo(): void
    {
        // The generic settings page accepts an empty value for a `number`,
        // so "cleared the field" must mean the default rather than zero
        // seconds — which would poll in a tight loop.
        $this->setInterval('');

        $this->assertSame(15 * 60, SyncMailboxesHandler::intervalSeconds($this->settings));
    }

    public function testTheConfiguredValueIsWhatIsUsed(): void
    {
        $this->setInterval('60');

        $this->assertSame(3600, SyncMailboxesHandler::intervalSeconds($this->settings));
    }

    public function testAValueBelowTheFloorIsRaisedToIt(): void
    {
        // Not a theoretical guard: reconnecting every minute is what makes
        // a mail host throttle or block the account, which costs the unit
        // every relève until the block lifts.
        $this->setInterval('1');

        $this->assertSame(SyncMailboxesHandler::MIN_INTERVAL_MINUTES * 60, SyncMailboxesHandler::intervalSeconds($this->settings));
    }

    public function testAValueAboveTheCeilingIsLoweredToIt(): void
    {
        // A stray keystroke turning 60 into 6000 is a mailbox that stops
        // being read for four months without saying so.
        $this->setInterval('100000');

        $this->assertSame(SyncMailboxesHandler::MAX_INTERVAL_MINUTES * 60, SyncMailboxesHandler::intervalSeconds($this->settings));
    }

    public function testANonNumericValueLandsOnTheFloorRatherThanPollingImmediately(): void
    {
        $this->setInterval('bientôt');

        $this->assertSame(SyncMailboxesHandler::MIN_INTERVAL_MINUTES * 60, SyncMailboxesHandler::intervalSeconds($this->settings));
    }

    // ── Arming the chain ────────────────────────────────────────────────

    public function testTheFirstRunIsQueuedAtTheConfiguredInterval(): void
    {
        $this->setInterval('30');
        SyncMailboxesHandler::bootstrap($this->scheduler, $this->settings);

        $this->assertEqualsWithDelta(30 * 60, $this->secondsUntilPendingRun(), 5);
    }

    public function testBootstrapIsIdempotentAndDoesNotQueueASecondRun(): void
    {
        SyncMailboxesHandler::bootstrap($this->scheduler, $this->settings);
        SyncMailboxesHandler::bootstrap($this->scheduler, $this->settings);
        SyncMailboxesHandler::bootstrap($this->scheduler, $this->settings);

        $pending = $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'sync_mailboxes' AND status = 'pending'"
        );
        $this->assertNotFalse($pending);
        $this->assertSame(1, (int) $pending->fetchColumn());
    }

    public function testShorteningTheIntervalPullsAnAlreadyQueuedRunForward(): void
    {
        // Six hours, then fifteen minutes — the case the whole
        // pull-forward exists for.
        $this->setInterval('360');
        SyncMailboxesHandler::bootstrap($this->scheduler, $this->settings);
        $this->assertEqualsWithDelta(360 * 60, $this->secondsUntilPendingRun(), 5);

        $this->setInterval('15');
        SyncMailboxesHandler::bootstrap($this->scheduler, $this->settings);

        $this->assertEqualsWithDelta(15 * 60, $this->secondsUntilPendingRun(), 5);
    }

    public function testLengtheningTheIntervalLeavesTheQueuedRunAlone(): void
    {
        // Nothing to correct: the queued run is already sooner than the new
        // interval asks. It fires, and the run it queues waits the new
        // delay. Re-arming here would push the next poll further out than
        // the unit ever asked for.
        $this->setInterval('15');
        SyncMailboxesHandler::bootstrap($this->scheduler, $this->settings);
        $queuedId = (int) $this->pending()['id'];

        $this->setInterval('360');
        SyncMailboxesHandler::bootstrap($this->scheduler, $this->settings);

        $this->assertSame($queuedId, (int) $this->pending()['id']);
        $this->assertEqualsWithDelta(15 * 60, $this->secondsUntilPendingRun(), 5);
    }

    public function testRepeatedBootstrapsNeverKeepPushingTheRunAway(): void
    {
        // The pull-forward only ever brings a run CLOSER, and only when it
        // is further out than a whole interval. A run queued under the
        // current setting never is — so a page view can never leave the
        // mailbox permanently one page view away from a poll.
        $this->setInterval('20');
        SyncMailboxesHandler::bootstrap($this->scheduler, $this->settings);
        $queuedId = (int) $this->pending()['id'];

        SyncMailboxesHandler::bootstrap($this->scheduler, $this->settings);
        SyncMailboxesHandler::bootstrap($this->scheduler, $this->settings);

        $this->assertSame($queuedId, (int) $this->pending()['id']);
    }

    public function testBootstrapWithoutSettingsStillArmsTheChainAtTheDefault(): void
    {
        SyncMailboxesHandler::bootstrap($this->scheduler);

        $this->assertEqualsWithDelta(15 * 60, $this->secondsUntilPendingRun(), 5);
    }

    // ── The manifest and the code must agree ────────────────────────────

    public function testTheManifestDeclaresTheSettingWithTheSameBoundsTheCodeClamps(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/inbound_mail/module.json'),
            true
        );
        $this->assertIsArray($manifest);

        $declared = null;
        foreach ($manifest['settings'] as $setting) {
            if ($setting['key'] === SyncMailboxesHandler::SETTING_INTERVAL_MINUTES) {
                $declared = $setting;
            }
        }

        $this->assertNotNull($declared, 'The interval must be a real setting, or the page cannot show it.');
        $this->assertSame('number', $declared['type']);
        $this->assertSame((string) SyncMailboxesHandler::DEFAULT_INTERVAL_MINUTES, $declared['default_value']);

        // The page rejects out-of-range values and the handler clamps
        // them. Both are needed — the form is not the only writer — but
        // they must not disagree about what is allowed, or the page
        // refuses a number the scheduler would happily have honoured.
        $pattern = '/' . $declared['validation_regex'] . '/';
        $this->assertSame(1, preg_match($pattern, (string) SyncMailboxesHandler::MIN_INTERVAL_MINUTES));
        $this->assertSame(1, preg_match($pattern, (string) SyncMailboxesHandler::MAX_INTERVAL_MINUTES));
        $this->assertSame(0, preg_match($pattern, (string) (SyncMailboxesHandler::MIN_INTERVAL_MINUTES - 1)));
        $this->assertSame(0, preg_match($pattern, (string) (SyncMailboxesHandler::MAX_INTERVAL_MINUTES + 1)));
    }
}
