<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Rental\Document\AssetConditions;
use Modules\Rental\Document\StandardTemplates;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalConditionsVersionRepository;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalConditionsService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The archive of an asset's conditions (issue #494): every wording that was
 * ever in force stays readable, and a booking's hash always has the text it
 * was taken from.
 */
#[Group('database')]
final class RentalConditionsServiceTest extends TestCase
{
    private \PDO $pdo;
    private EditableContentService $store;
    private RentalConditionsService $conditions;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->store = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->conditions = new RentalConditionsService(
            new RentalConditionsVersionRepository($this->pdo),
            $this->store
        );
        $this->assetId = $this->assets()
            ->create('Local', 'Local Saint-Georges', 'local-saint-georges', null, 1, null, null, null, true);
    }

    public function testTheShippedStandardIsArchivedOnTheFirstRead(): void
    {
        $current = $this->conditions->current($this->assetId);

        $this->assertSame(StandardTemplates::conditions(), $current->html);
        $this->assertSame(RentalBookingService::hashAcceptedText($current->html), $current->hash);
        $this->assertSame(substr($current->hash, 0, 12), $current->version);
        $this->assertSame(1, $this->versionCount());
    }

    /** Read a hundred times, saved twice with the same words: still one version. */
    public function testTheSameTextIsOneVersion(): void
    {
        $this->conditions->current($this->assetId);
        $this->conditions->current($this->assetId);
        $this->conditions->recordSave($this->assetId, '<p>Balayé.</p>', 1);
        $this->conditions->recordSave($this->assetId, '<p>Balayé.</p>', 1);

        $this->assertSame(2, $this->versionCount(), 'the standard text, then the unit\'s own — once each');
    }

    public function testTwoSuccessiveTextsAreTwoVersionsAndTheFirstStaysReadable(): void
    {
        $first = $this->conditions->recordSave($this->assetId, '<p>Balayé.</p>', 1);
        $second = $this->conditions->recordSave($this->assetId, '<p>Lavé.</p>', 1);

        $this->assertNotSame($first->version, $second->version);
        $this->assertSame('<p>Balayé.</p>', $this->conditions->find($this->assetId, $first->version)?->html);
        $this->assertSame($second->version, $this->conditions->current($this->assetId)->version);
    }

    /**
     * What covers the bookings made before the archive existed: a text
     * written the old way, straight into the store and never read since, is
     * archived by the save that replaces it — one statement before it would
     * have been lost.
     */
    public function testASaveArchivesTheOutgoingTextFirst(): void
    {
        $this->store->set(AssetConditions::key($this->assetId), '<p>Texte d\'avant l\'archive.</p>', 'rich_text', 1);
        $hashABookingHolds = RentalBookingService::hashAcceptedText('<p>Texte d\'avant l\'archive.</p>');

        $this->conditions->recordSave($this->assetId, '<p>Nouveau texte.</p>', 1);

        $old = $this->conditions->find($this->assetId, substr($hashABookingHolds, 0, 12));
        $this->assertNotNull($old, 'the outgoing text was lost with the save');
        $this->assertSame($hashABookingHolds, $old->hash);
    }

    public function testAVersionIsOnlyEverTwelveLowerCaseHexCharacters(): void
    {
        $version = $this->conditions->current($this->assetId)->version;

        $this->assertNotNull($this->conditions->find($this->assetId, $version));
        $this->assertNull($this->conditions->find($this->assetId, strtoupper($version)));
        $this->assertNull($this->conditions->find($this->assetId, $version . 'x'));
        $this->assertNull($this->conditions->find($this->assetId, "' OR 1=1 --"));
    }

    /** One asset's versions are not another's, even for the same words. */
    public function testVersionsBelongToTheirAsset(): void
    {
        $otherId = $this->assets()
            ->create('Local', 'Autre local', 'autre-local', null, 1, null, null, null, true);
        $mine = $this->conditions->recordSave($this->assetId, '<p>Balayé.</p>', 1);

        $this->assertNull($this->conditions->find($otherId, $mine->version));
    }

    private function assets(): RentalAssetRepository
    {
        return new RentalAssetRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
    }

    private function versionCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rental_conditions_versions WHERE asset_id = ?');
        $stmt->execute([$this->assetId]);

        return (int) $stmt->fetchColumn();
    }
}
