<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Config;

use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\GoogleDriveSecret;
use Core\Storage\Location\StorageCapability;
use Core\Storage\Location\StorageConsequence;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;

/**
 * The record a Google Drive location is, on both sides of the encryption
 * line.
 *
 * `Tests\Security\RemoteBackupSecrecyTest` pins what must never cross that
 * line; what is asserted here is that the halves survive a round trip and
 * that the new type is wired into everything a screen reads.
 */
final class GoogleDriveLocationTest extends TestCase
{
    public function testTheClearRecordSurvivesARoundTrip(): void
    {
        $config = new GoogleDriveLocationConfig('client-1', 'dossier-1', '2026-09-01T00:00:00+00:00');

        $back = GoogleDriveLocationConfig::fromArray($config->toArray());

        $this->assertSame('client-1', $back->clientId);
        $this->assertSame('dossier-1', $back->folderId);
        $this->assertSame('2026-09-01T00:00:00+00:00', $back->connectedAt);
        $this->assertTrue($back->isConnected());
    }

    /**
     * A row written by a newer version, or half-written by a flow that
     * failed, reads as « not connected » rather than as a fatal on the
     * configuration page somebody opened to repair it.
     */
    public function testAnUnreadableRecordReadsAsAnUnconnectedOne(): void
    {
        $config = GoogleDriveLocationConfig::fromArray(['client_id' => 42, 'inconnu' => 'x']);

        $this->assertSame('', $config->clientId);
        $this->assertFalse($config->isConnected());
    }

    /**
     * **Nothing here is readable by whoever holds a URL.** Everything in
     * a Drive folder is reached with a `Bearer` token under the
     * `drive.file` scope, so the question a consumer with its own access
     * control asks — « would storing here publish it » — has one answer,
     * and it is what lets a delegated album live here at all.
     */
    public function testADriveFolderNeverServesPubliclyWithoutExpiry(): void
    {
        $this->assertFalse((new GoogleDriveLocationConfig())->servesPubliclyWithoutExpiry());
    }

    public function testTheSecretKeepsTheClientWhenTheGrantChangesAndTheOtherWayRound(): void
    {
        $secret = (new GoogleDriveSecret())
            ->withClientSecret('secret-1')
            ->withGrant('refresh-1', 'unite@example.org');

        $this->assertSame('secret-1', $secret->clientSecret);
        $this->assertTrue($secret->hasGrant());

        // Re-entering the client credentials does not sign the operator
        // out of the account they already connected.
        $rotated = $secret->withClientSecret('secret-2');
        $this->assertSame('refresh-1', $rotated->refreshToken);
        $this->assertSame('unite@example.org', $rotated->account);
    }

    /**
     * **The type is wired into the enum, not written down beside it.** A
     * screen comparing destinations reads the capabilities from the
     * backend class, so a backend that gains an aptitude changes one list
     * and every screen follows.
     */
    public function testTheTypeAnswersForItsBackendRatherThanRestatingIt(): void
    {
        $type = StorageLocationType::tryFrom('google_drive');

        $this->assertSame(StorageLocationType::GoogleDrive, $type);
        $this->assertSame('Google Drive', $type->frenchLabel());
        $this->assertTrue($type->supports(StorageCapability::ResumableUpload));
        $this->assertTrue($type->supports(StorageCapability::Quota));
        $this->assertFalse($type->supports(StorageCapability::RangeRead));
        $this->assertInstanceOf(
            GoogleDriveLocationConfig::class,
            $type->configFromArray(['client_id' => 'client-1'])
        );
    }

    /**
     * **And the screen prints consequences, never capabilities** (D3).
     * The four sentences are computed from the declarations above, so a
     * new type gets them without anybody writing a table.
     */
    public function testTheScreenGetsItsFourSentencesWithoutATableToMaintain(): void
    {
        $consequences = StorageConsequence::forType(StorageLocationType::GoogleDrive);
        $byArea = [];
        foreach ($consequences as $consequence) {
            $byArea[$consequence->area] = $consequence->verdict;
        }

        $this->assertCount(4, $consequences);
        // No range read: a video can be started and never seeked in,
        // which is a refusal rather than a slowdown.
        $this->assertSame('non', $byArea[StorageConsequence::AREA_VIDEOS] ?? null);
        // Resumable upload: what an archive of gibibytes needs.
        $this->assertSame('oui', $byArea[StorageConsequence::AREA_BACKUPS] ?? null);
        // And it is the first declared type that can say how full it is.
        $this->assertSame('oui', $byArea[StorageConsequence::AREA_SPACE] ?? null);
    }
}
