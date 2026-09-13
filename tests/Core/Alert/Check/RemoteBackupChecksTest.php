<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\AlertThresholds;
use Core\Alert\Check\RemoteBackupAgeCheck;
use Core\Alert\Check\RemoteQuotaCheck;
use Core\Maintenance\Remote\RemoteBackupConnection;
use Core\Maintenance\Remote\RemoteBackupException;
use Core\Maintenance\Remote\RemoteQuota;
use Core\Maintenance\Task\SendRemoteBackupHandler;
use Core\Security\SecretManager;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Remote\InMemorySettingService;

/**
 * The two alerts that watch what happens off this server.
 *
 * Both have one trap in common and it is not the arithmetic: a site with
 * no destination connected must come back **re-armed**, never
 * inconclusive. Inconclusive leaves the last reading standing for ever,
 * so an operator who disconnects a destination precisely because they are
 * retiring it would be left with a permanent alert about a backup that is
 * never coming — and no way to clear it.
 */
final class RemoteBackupChecksTest extends TestCase
{
    private string $base;
    private InMemorySettingService $settings;
    private SecretManager $secrets;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/remote_checks_' . uniqid();
        @mkdir($this->base . '/keys', 0700, true);
        @mkdir($this->base . '/config', 0700, true);

        $this->secrets = new SecretManager($this->base . '/keys/master.key', $this->base . '/config/secrets.enc');
        $this->secrets->generateMasterKey();
        $this->secrets->writeSecrets(['smtp_password' => 'le-mot-de-passe-smtp']);

        $this->settings = new InMemorySettingService();
    }

    protected function tearDown(): void
    {
        foreach (['/config/secrets.enc', '/keys/master.key'] as $file) {
            @unlink($this->base . $file);
        }
        @rmdir($this->base . '/config');
        @rmdir($this->base . '/keys');
        @rmdir($this->base);
    }

    // ————— The age of the last send —————

    /**
     * **A unit that has not set up a destination is not failing at
     * anything.** Off-site sending is a thing a unit chooses; an alert
     * every installation would carry from its first day is an alert
     * nobody reads.
     */
    public function testASiteWithNoDestinationIsRearmedRatherThanAlerted(): void
    {
        $reading = $this->ageReading();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue(
            $reading->underRearm,
            'disconnecting a destination would leave a triggered alert lit with nothing left to clear it'
        );
        $this->assertSame('non raccordé', $reading->value);
    }

    /** Past the trigger, on a destination that has gone quiet. */
    public function testAnOldSuccessTriggersAndARecentOneRearms(): void
    {
        $this->connect();

        $this->settings->values[SendRemoteBackupHandler::LAST_SUCCESS_SETTING]
            = $this->daysBefore(AlertThresholds::REMOTE_BACKUP_AGE_TRIGGER_DAYS);
        $over = $this->ageReading();
        $this->assertTrue($over->overTrigger);
        $this->assertFalse($over->underRearm);
        $this->assertSame(AlertThresholds::REMOTE_BACKUP_AGE_TRIGGER_DAYS . ' jours', $over->value);

        $this->settings->values[SendRemoteBackupHandler::LAST_SUCCESS_SETTING] = $this->daysBefore(1);
        $under = $this->ageReading();
        $this->assertFalse($under->overTrigger);
        $this->assertTrue($under->underRearm);
    }

    /**
     * Between the two, neither — the gap that stops a value wobbling
     * across one threshold from notifying on every pass.
     */
    public function testBetweenTheThresholdsNeitherBooleanIsTrue(): void
    {
        $this->connect();
        $this->settings->values[SendRemoteBackupHandler::LAST_SUCCESS_SETTING]
            = $this->daysBefore(AlertThresholds::REMOTE_BACKUP_AGE_REARM_DAYS + 1);

        $reading = $this->ageReading();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
    }

    /**
     * **A destination connected weeks ago that has never received
     * anything is measured from the day it was connected.** Ten days is
     * ten days whether the sends failed or never started, and the second
     * is the more alarming of the two — reporting it as "unknown" would
     * hide exactly the site whose off-site backup was set up once and
     * never worked.
     */
    public function testADestinationThatNeverReceivedAnythingIsMeasuredFromTheDayItWasConnected(): void
    {
        $this->connect();
        $this->settings->values[RemoteBackupConnection::CONNECTED_AT_SETTING]
            = $this->daysBefore(AlertThresholds::REMOTE_BACKUP_AGE_TRIGGER_DAYS + 4);

        $reading = $this->ageReading();

        $this->assertTrue($reading->overTrigger);
        $this->assertStringContainsString('jamais partie', $reading->title);
    }

    /**
     * **A withdrawn grant is not a disconnected site**, and reading it as
     * one silences this alert on exactly the installation it exists for.
     *
     * `markNeedsReauthorisation()` clears the refresh token, so
     * `isConnected()` answers false for a site whose Google authorisation
     * died — the same shape that cost IT-08 a finding on
     * `testConnection()`. Keying on it here would mean: a destination
     * configured, an operator who believes it works, nothing having left
     * the server for a month, and an alert that says « tout va bien »
     * because it decided there was nothing to measure.
     */
    public function testAWithdrawnGrantIsStillMeasuredRatherThanTreatedAsNoDestination(): void
    {
        $this->connect();
        $this->settings->values[SendRemoteBackupHandler::LAST_SUCCESS_SETTING]
            = $this->daysBefore(AlertThresholds::REMOTE_BACKUP_AGE_TRIGGER_DAYS + 2);

        (new RemoteBackupConnection($this->settings, $this->secrets))
            ->markNeedsReauthorisation('Google n\'accepte plus l\'autorisation de ce site.');

        $reading = $this->ageReading();

        $this->assertTrue(
            $reading->overTrigger,
            'the alert went quiet on a site whose off-site backups have stopped — the one case it is for'
        );
        $this->assertFalse($reading->underRearm);
        $this->assertNotSame('non raccordé', $reading->value);
    }

    /** Only a deliberate disconnection re-arms it. */
    public function testOnlyADeliberateDisconnectionRearmsTheAgeCheck(): void
    {
        $this->connect();
        $this->settings->values[SendRemoteBackupHandler::LAST_SUCCESS_SETTING]
            = $this->daysBefore(AlertThresholds::REMOTE_BACKUP_AGE_TRIGGER_DAYS + 2);
        $this->settings->values[RemoteBackupConnection::STATE_SETTING]
            = RemoteBackupConnection::STATE_DISCONNECTED;

        $reading = $this->ageReading();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
        $this->assertSame('non raccordé', $reading->value);
    }

    /** And one connected this morning is not yet news. */
    public function testADestinationConnectedThisMorningIsNotYetAlarming(): void
    {
        $this->connect();
        $this->settings->values[RemoteBackupConnection::CONNECTED_AT_SETTING] = $this->daysBefore(0);

        $reading = $this->ageReading();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
    }

    // ————— The remote account's free space —————

    public function testAFullDestinationTriggersAndAnEmptierOneRearms(): void
    {
        $over = (new RemoteQuotaCheck(new QuotaTargetDouble(new RemoteQuota(92, 100))))->read();
        $this->assertTrue($over->overTrigger);
        $this->assertFalse($over->underRearm);
        $this->assertSame('92 %', $over->value);

        $under = (new RemoteQuotaCheck(new QuotaTargetDouble(new RemoteQuota(40, 100))))->read();
        $this->assertFalse($under->overTrigger);
        $this->assertTrue($under->underRearm);
    }

    /** Higher than the local disk's, and deliberately so. */
    public function testTheRemoteThresholdsSitAboveTheLocalOnes(): void
    {
        $this->assertGreaterThan(
            AlertThresholds::DISK_TRIGGER_PERCENT,
            AlertThresholds::REMOTE_QUOTA_TRIGGER_PERCENT,
            'a full destination costs the next send; a full server breaks the site — the two cannot be equal'
        );
        $this->assertLessThan(
            AlertThresholds::REMOTE_QUOTA_TRIGGER_PERCENT,
            AlertThresholds::REMOTE_QUOTA_REARM_PERCENT,
            'without a strictly lower re-arm, a value wobbling over the line notifies on every pass'
        );
    }

    /**
     * An account with no declared limit is not an empty one.
     *
     * A Workspace account that reports no quota would otherwise read as
     * « 0 % occupé » — a reassuring number where no measurement exists.
     */
    public function testAnAccountWithNoDeclaredLimitIsNotReportedAsEmpty(): void
    {
        $reading = (new RemoteQuotaCheck(new QuotaTargetDouble(null)))->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm, 'no measurement at all silently cleared a standing alert');
        $this->assertSame('', $reading->value);
    }

    /**
     * **A destination that refuses to answer is inconclusive, not
     * healthy.** The alternative — treating a failure as "fine" — clears
     * a standing alert on the very site whose connection has broken.
     */
    public function testADestinationThatRefusesToAnswerLeavesTheAlertWhereItWas(): void
    {
        $target = new QuotaTargetDouble(null);
        $target->failWith = 'Google n\'accepte plus l\'autorisation de ce site.';

        $reading = (new RemoteQuotaCheck($target))->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
    }

    public function testNoDestinationIsRearmedForTheQuotaCheckToo(): void
    {
        $reading = (new RemoteQuotaCheck(null))->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
    }

    // ————— Plumbing —————

    private function connect(): void
    {
        (new RemoteBackupConnection($this->settings, $this->secrets))
            ->saveConnection('un-jeton-de-rafraichissement', 'unite@example.org', 'dossier-distant');
        $this->settings->values[RemoteBackupConnection::CONNECTED_AT_SETTING] = $this->daysBefore(0);
    }

    private function daysBefore(int $days): string
    {
        return (new \DateTimeImmutable('2026-09-10 12:00:00'))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s');
    }

    private function ageReading(): AlertReading
    {
        return (new RemoteBackupAgeCheck(
            $this->settings,
            $this->secrets,
            new \DateTimeImmutable('2026-09-10 12:00:00')
        ))->read();
    }
}

/**
 * A destination that answers about free space and nothing else.
 *
 * Everything below `quota()` throws: a check that reached for any of it
 * would be making a network call this suite has no way to see, and the
 * exception says so where a silent stub would not.
 */
final class QuotaTargetDouble implements \Core\Maintenance\Remote\RemoteBackupTarget
{
    public string $failWith = '';

    public function __construct(private readonly ?RemoteQuota $quota)
    {
    }

    public function quota(): ?RemoteQuota
    {
        if ($this->failWith !== '') {
            throw RemoteBackupException::of($this->failWith);
        }

        return $this->quota;
    }

    public function upload(string $localPath, string $remoteName): string
    {
        throw new \LogicException('a quota check has no business uploading anything');
    }

    public function beginUpload(string $remoteName, int $size): string
    {
        throw new \LogicException('a quota check has no business uploading anything');
    }

    public function probeUpload(string $sessionUrl, int $size): \Core\Maintenance\Remote\RemoteUpload
    {
        throw new \LogicException('a quota check has no business uploading anything');
    }

    public function sendChunks(
        string $sessionUrl,
        string $localPath,
        int $size,
        int $offset,
        \Closure $hasTimeLeft
    ): \Core\Maintenance\Remote\RemoteUpload {
        throw new \LogicException('a quota check has no business uploading anything');
    }

    public function list(): array
    {
        throw new \LogicException('a quota check has no business listing the folder');
    }

    public function delete(string $remoteId): void
    {
        throw new \LogicException('a quota check has no business deleting anything');
    }

    public function testConnection(): \Core\Maintenance\Remote\RemoteConnectionCheck
    {
        throw new \LogicException('a quota check has no business writing a witness file');
    }
}
