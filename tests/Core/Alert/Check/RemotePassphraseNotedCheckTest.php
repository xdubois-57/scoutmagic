<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\Check\RemotePassphraseNotedCheck;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Remote\RemotePassphrase;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Remote\InMemorySettingService;
use Tests\DatabaseTestHelper;

/**
 * The alert that catches archives leaving perfectly and arriving
 * unreadable (#496).
 *
 * The failure it exists for looks like success from every other angle: a
 * destination connected, a send every night for a year, no error in the
 * journal — and one key, on the server the archives are meant to outlive.
 * Nothing said so until the day it could no longer be said.
 *
 * Two situations must come back RE-ARMED rather than inconclusive, for
 * the reason {@see RemoteBackupChecksTest} gives about its own: an
 * inconclusive reading freezes the last one, so an operator who
 * disconnects a destination is left with a permanent alert about archives
 * that no longer exist.
 */
#[Group('database')]
final class RemotePassphraseNotedCheckTest extends TestCase
{
    private string $base;
    private InMemorySettingService $settings;
    private StorageLocationRepository $locations;
    private RemoteBackupDestination $destination;
    private RemotePassphrase $passphrase;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new InMemorySettingService();
        $this->locations = new StorageLocationRepository(
            $pdo,
            new EncryptionService(str_repeat('k', 32), str_repeat('b', 32))
        );
        $this->destination = new RemoteBackupDestination(
            $this->settings,
            $this->locations,
            new StorageBackendFactory($this->locations, sys_get_temp_dir())
        );

        $this->base = sys_get_temp_dir() . '/phrase_noted_' . uniqid();
        @mkdir($this->base . '/keys', 0700, true);
        @mkdir($this->base . '/config', 0700, true);
        $secrets = new SecretManager($this->base . '/keys/master.key', $this->base . '/config/secrets.enc');
        $secrets->generateMasterKey();
        $secrets->writeSecrets([]);

        $this->passphrase = new RemotePassphrase($this->settings, $secrets);
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

    /**
     * A unit that sends nothing off site has nothing to lose the key to.
     */
    public function testASiteWithNoDestinationIsNotMeasured(): void
    {
        $reading = $this->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue(
            $reading->underRearm,
            'disconnecting a destination would leave this lit with nothing left to clear it'
        );
        $this->assertSame('aucune destination', $reading->value);
    }

    /**
     * **Nothing before the first send**, and it costs no condition of its
     * own: the phrase is created by the send itself, never at connection
     * time, so a generation of 0 already means nothing has been encrypted
     * with anything.
     */
    public function testADestinationConnectedThisAfternoonIsNotYetAlarming(): void
    {
        $this->connect();

        $reading = $this->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
        $this->assertSame('aucune phrase', $reading->value);
    }

    /** One send, nobody's copy: this is the whole point of the check. */
    public function testAPhraseNobodyHasCopiedTrips(): void
    {
        $this->connect();
        $this->passphrase->current();

        $reading = $this->read();

        $this->assertTrue($reading->overTrigger, 'a key that exists only on this server raised nothing');
        $this->assertFalse($reading->underRearm);
        $this->assertSame('génération 1 non notée', $reading->value);
        $this->assertSame('/config/maintenance#remote-backup', $reading->actionUrl);
    }

    /** And the statement clears it. */
    public function testConfirmingItWasCopiedClearsTheAlert(): void
    {
        $this->connect();
        $this->passphrase->current();
        $this->passphrase->markNoted();

        $reading = $this->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
        $this->assertSame('notée', $reading->value);
    }

    /**
     * **It comes back after a regeneration**, because the phrase somebody
     * wrote down no longer opens what the site is about to send.
     */
    public function testRegeneratingBringsTheAlertBack(): void
    {
        $this->connect();
        $this->passphrase->current();
        $this->passphrase->markNoted();

        $this->passphrase->regenerate();

        $reading = $this->read();

        $this->assertTrue($reading->overTrigger, 'a note taken for the first phrase silenced the second');
        $this->assertSame('génération 2 non notée', $reading->value);
    }

    /**
     * Disconnecting stops the measurement rather than freezing it — the
     * trap this class shares with {@see RemoteBackupChecksTest}, checked
     * from a TRIGGERED state, which is the only state where freezing
     * would be visible.
     */
    public function testDisconnectingClearsATriggeredAlertRatherThanFreezingIt(): void
    {
        $this->connect();
        $this->passphrase->current();
        $this->assertTrue($this->read()->overTrigger);

        $this->destination->choose(0);

        $this->assertTrue($this->read()->underRearm);
    }

    private function connect(): void
    {
        $id = $this->locations->create(
            StorageLocationType::GoogleDrive,
            'Google Drive',
            new GoogleDriveLocationConfig('client-1', 'dossier-1', '2026-09-10 12:00:00'),
            (string) json_encode([
                'client_secret' => 's',
                'refresh_token' => 'un-jeton-de-rafraichissement',
                'account' => 'unite@example.org',
            ])
        );
        $this->destination->choose($id);
    }

    private function read(): AlertReading
    {
        return (new RemotePassphraseNotedCheck($this->destination, $this->passphrase))->read();
    }
}
