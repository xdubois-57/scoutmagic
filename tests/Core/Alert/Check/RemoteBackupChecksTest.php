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
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Task\SendRemoteBackupHandler;
use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\QuotaReportingBackend;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use Core\Storage\Location\StorageQuota;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Remote\InMemorySettingService;
use Tests\Core\Storage\Location\Backend\RefusingBackend;
use Tests\DatabaseTestHelper;

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
    private InMemorySettingService $settings;
    private StorageLocationRepository $locations;
    private RemoteBackupDestination $destination;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new InMemorySettingService();
        $this->locations = new StorageLocationRepository($pdo, new EncryptionService(str_repeat('k', 32), str_repeat('b', 32)));
        $this->destination = new RemoteBackupDestination(
            $this->settings,
            $this->locations,
            new StorageBackendFactory($this->locations, sys_get_temp_dir())
        );
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
        $this->assertSame('non configuré', $reading->value);
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
        $this->connect($this->daysBefore(AlertThresholds::REMOTE_BACKUP_AGE_TRIGGER_DAYS + 4));

        $reading = $this->ageReading();

        $this->assertTrue($reading->overTrigger);
        $this->assertStringContainsString('jamais partie', $reading->title);
    }

    /**
     * **A destination whose grant Google withdrew is still measured**, and
     * reading it as « no destination » silences this alert on exactly the
     * installation it exists for: a destination configured, an operator
     * who believes it works, nothing having left the server for a month.
     *
     * This used to be a hard distinction to keep, because the code that
     * held the grant dropped it on a refusal — so anything asking « can we
     * still reach the destination » answered no and went quiet. IT-05
     * removed the trap rather than working around it: a destination is an
     * assignment, and only un-assigning it stops the measurement.
     */
    public function testAWithdrawnGrantIsStillMeasuredRatherThanTreatedAsNoDestination(): void
    {
        $this->connect();
        $this->settings->values[SendRemoteBackupHandler::LAST_SUCCESS_SETTING]
            = $this->daysBefore(AlertThresholds::REMOTE_BACKUP_AGE_TRIGGER_DAYS + 2);

        // The grant is gone: the row keeps its client id and its folder,
        // and no longer holds a usable token.
        $this->locations->update(
            $this->destination->locationId(),
            'Google Drive',
            new GoogleDriveLocationConfig('client-1', 'dossier-1', $this->daysBefore(40)),
            (string) json_encode(['client_secret' => 's', 'refresh_token' => '', 'account' => ''])
        );

        $reading = $this->ageReading();

        $this->assertTrue(
            $reading->overTrigger,
            'the alert went quiet on a site whose off-site backups have stopped — the one case it is for'
        );
        $this->assertFalse($reading->underRearm);
        $this->assertNotSame('non configuré', $reading->value);
    }

    /** Only dropping the destination re-arms it. */
    public function testOnlyDroppingTheDestinationRearmsTheAgeCheck(): void
    {
        $this->connect();
        $this->settings->values[SendRemoteBackupHandler::LAST_SUCCESS_SETTING]
            = $this->daysBefore(AlertThresholds::REMOTE_BACKUP_AGE_TRIGGER_DAYS + 2);
        $this->destination->choose(0);

        $reading = $this->ageReading();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
        $this->assertSame('non configuré', $reading->value);
    }

    /**
     * **A destination whose row has vanished is not a site with a broken
     * alert.** Deleting one is refused while the backup stands on it, but
     * a restore taken before the assignment was made can still leave the
     * setting pointing at nothing.
     */
    public function testADestinationThatNoLongerExistsReadsAsNoneRatherThanFailing(): void
    {
        $this->connect();
        $this->destination->choose(999);

        $reading = $this->ageReading();

        $this->assertTrue($reading->underRearm);
        $this->assertSame('non configuré', $reading->value);
    }

    /** And one connected this morning is not yet news. */
    public function testADestinationConnectedThisMorningIsNotYetAlarming(): void
    {
        $this->connect($this->daysBefore(0));

        $reading = $this->ageReading();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
    }

    // ————— The remote account's free space —————

    public function testAFullDestinationTriggersAndAnEmptierOneRearms(): void
    {
        $over = (new RemoteQuotaCheck(new QuotaBackendDouble(new StorageQuota(92, 100))))->read();
        $this->assertTrue($over->overTrigger);
        $this->assertFalse($over->underRearm);
        $this->assertSame('92 %', $over->value);

        $under = (new RemoteQuotaCheck(new QuotaBackendDouble(new StorageQuota(40, 100))))->read();
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
        $reading = (new RemoteQuotaCheck(new QuotaBackendDouble(null)))->read();

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
        $backend = new QuotaBackendDouble(null);
        $backend->failWith = 'Google n\'accepte plus l\'autorisation de ce site.';

        $reading = (new RemoteQuotaCheck($backend))->read();

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

    private function connect(?string $connectedAt = null): void
    {
        $id = $this->locations->create(
            StorageLocationType::GoogleDrive,
            'Google Drive',
            new GoogleDriveLocationConfig('client-1', 'dossier-1', $connectedAt ?? $this->daysBefore(0)),
            (string) json_encode([
                'client_secret' => 's',
                'refresh_token' => 'un-jeton-de-rafraichissement',
                'account' => 'unite@example.org',
            ])
        );
        $this->destination->choose($id);
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
            $this->destination,
            $this->settings,
            new \DateTimeImmutable('2026-09-10 12:00:00')
        ))->read();
    }
}

/**
 * A destination that answers about free space and nothing else.
 *
 * Everything else refuses ({@see RefusingBackend}): a check that reached
 * for any of it would be making a network call this suite has no way to
 * see, and the exception says so where a silent stub would not.
 */
final class QuotaBackendDouble extends RefusingBackend implements QuotaReportingBackend
{
    public string $failWith = '';

    public function __construct(private readonly ?StorageQuota $quota)
    {
    }

    public function quota(): ?StorageQuota
    {
        if ($this->failWith !== '') {
            throw new StorageLocationException($this->failWith);
        }

        return $this->quota;
    }
}
