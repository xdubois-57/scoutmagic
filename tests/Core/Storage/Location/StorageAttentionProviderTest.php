<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location;

use Core\Attention\AttentionPoint;
use Core\Config\AppClock;
use Core\Security\EncryptionService;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Protection\StorageProtectionRepository;
use Core\Storage\Location\StorageAttentionProvider;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #718 § C point 10: one point per location whose last check failed,
 * and per Google Drive grant that ends or whose end nobody knows — read
 * from what is stored, never by testing a location.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class StorageAttentionProviderTest extends TestCase
{
    private StorageLocationRepository $locations;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->locations = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('k', 32), str_repeat('b', 32))
        );
    }

    /** @return AttentionPoint[] */
    private function points(?\DateTimeImmutable $now = null): array
    {
        return (new StorageAttentionProvider($this->locations, $now))->collect(1);
    }

    private function drive(string $label, bool $observed, string $expiresAt, bool $connected = true): int
    {
        return $this->locations->create(
            StorageLocationType::GoogleDrive,
            $label,
            new GoogleDriveLocationConfig('client-1', 'dossier-1', $connected ? '2026-09-01T00:00:00+00:00' : '', $observed, $expiresAt),
            (string) json_encode(['client_secret' => 's', 'refresh_token' => $connected ? 'r' : '', 'account' => 'a@example.org'])
        );
    }

    public function testHealthyLocationsRaiseNothing(): void
    {
        $id = $this->locations->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('photos'), null);
        $this->locations->recordCheckResult($id, true, null);
        $this->drive('Drive sans échéance', true, '');

        $this->assertSame([], $this->points());
    }

    public function testAFailedCheckIsUrgentAndQuotesTheRecordedError(): void
    {
        $id = $this->locations->create(StorageLocationType::Local, 'Disque', new LocalLocationConfig('photos'), null);
        $this->locations->recordCheckResult($id, false, 'Le dossier n\'est pas accessible en écriture.');

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertSame("L'emplacement de stockage « Disque » est en erreur", $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertStringContainsString("n'est pas accessible en écriture", $points[0]->why);
        $this->assertSame('/config/stockage/emplacements', $points[0]->actionUrl);
    }

    public function testAnAnnouncedEndIsAPointWithItsRealDueDate(): void
    {
        $now = AppClock::now();
        $end = $now->modify('+5 days');
        $this->drive('Photos Drive', true, $end->format('c'));

        $points = $this->points($now);

        $this->assertCount(1, $points);
        $this->assertSame(
            "L'autorisation Google Drive de « Photos Drive » prend fin le " . $end->format('d/m/Y'),
            $points[0]->title
        );
        $this->assertSame($end->format('c'), $points[0]->dueDate?->format('c'));
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
    }

    /** Past the end with a failed check since: one point, carrying the error — not two. */
    public function testAPassedEndWithAFailedCheckSinceIsOneUrgentPointWithTheError(): void
    {
        $id = $this->drive('Photos Drive', true, AppClock::now()->modify('-2 days')->format('c'));
        $this->locations->recordCheckResult($id, false, 'Google a retiré l\'autorisation.');

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertStringContainsString('a pris fin', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertStringContainsString("Google a retiré l'autorisation.", $points[0]->why);
    }

    /** Before its end, a Drive location failing for another reason still says so. */
    public function testAFailureBeforeTheEndIsItsOwnPoint(): void
    {
        $id = $this->drive('Photos Drive', true, AppClock::now()->modify('+20 days')->format('c'));
        $this->locations->recordCheckResult($id, false, 'Le dossier a été supprimé du Drive.');

        $points = $this->points();

        $this->assertCount(2, $points);
        $this->assertStringContainsString('est en erreur', $points[1]->title);
    }

    /** Earlier the same day: the end has passed, for the point as for the card. */
    public function testAnEndEarlierTodayHasPassed(): void
    {
        $now = new \DateTimeImmutable('2026-10-18 15:00:00', AppClock::zone());
        $this->drive('Photos Drive', true, '2026-10-18T03:00:00+02:00');

        $points = $this->points($now);

        $this->assertStringContainsString('a pris fin', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
    }

    /** A check that succeeded after the end proves it no longer applies. */
    public function testASuccessfulCheckAfterTheEndClearsThePoint(): void
    {
        $id = $this->drive('Photos Drive', true, AppClock::now()->modify('-2 days')->format('c'));
        $this->locations->recordCheckResult($id, true, null);

        $this->assertSame([], $this->points());
    }

    /** No check after the end proves nothing: the risk stays. */
    public function testWithoutACheckAfterTheEndThePointStays(): void
    {
        $this->drive('Photos Drive', true, AppClock::now()->modify('-2 days')->format('c'));

        $this->assertCount(1, $this->points());
    }

    public function testAnOldConnectionIsAnUndatedUnknownExpiry(): void
    {
        $this->drive('Ancien Drive', false, '');

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertStringContainsString('On ne sait pas quand', $points[0]->title);
        $this->assertNull($points[0]->dueDate);
    }

    /**
     * Declared, never connected: a setup to finish. Its check fails by
     * construction, and that is not an urgent « en erreur » — it is one
     * attention point asking for the connection, with no expiry either.
     */
    public function testADriveNotYetConnectedAsksForTheConnectionNotAnError(): void
    {
        $id = $this->drive('Drive débranché', false, '', false);
        $this->locations->recordCheckResult($id, false, "Aucun compte Google Drive n'est raccordé à cet emplacement.");

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertStringContainsString("n'est raccordé à aucun compte Google", $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
    }

    /** The state is carried per location, and the label read from the current row. */
    public function testEachDriveLocationIsJudgedOnItsOwnUnderItsCurrentName(): void
    {
        $id = $this->drive('Ancien nom', true, AppClock::now()->modify('+3 days')->format('c'));
        $this->drive('Autre Drive', true, '');
        $row = $this->locations->findById($id);
        $this->assertNotNull($row);
        $this->locations->update($id, 'Nouveau nom', $row->config, null);

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertStringContainsString('« Nouveau nom »', $points[0]->title);
    }

    // ————— Safety copies left on the site's own server (#718 § C point 11) —————

    private function local(string $label, string $path): int
    {
        $id = $this->locations->create(StorageLocationType::Local, $label, new LocalLocationConfig($path), null);
        $this->locations->recordCheckResult($id, true, null);

        return $id;
    }

    /** @return AttentionPoint[] */
    private function pointsWithCopies(): array
    {
        return (new StorageAttentionProvider($this->locations, null, new StorageProtectionRepository($this->pdo)))->collect(1);
    }

    /** Declared before such a pair was refused: said, since it protects nothing against losing the server. */
    public function testACopyFromTheServerToTheServerIsAnAttentionPoint(): void
    {
        $source = $this->local('Galerie', 'gallery');
        $destination = $this->local('NAS', '/mnt/nas');
        (new StorageProtectionRepository($this->pdo))->save($source, $destination, 30, 24, true);

        $points = $this->pointsWithCopies();

        $this->assertCount(1, $points);
        $this->assertSame('La copie de secours de « Galerie » reste sur le serveur du site', $points[0]->title);
        $this->assertStringContainsString('« NAS »', $points[0]->why);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame('/config/stockage/emplacements/' . $source . '/modification', $points[0]->actionUrl);
    }

    /** A copy from a remote location onto the server is a real protection: nothing to say. */
    public function testACopyFromARemoteLocationOntoTheServerRaisesNothing(): void
    {
        $drive = $this->drive('Drive', true, '');
        $this->locations->recordCheckResult($drive, true, null);
        (new StorageProtectionRepository($this->pdo))->save($drive, $this->local('Copie', 'copie'), 30, 24, true);

        $this->assertSame([], $this->pointsWithCopies());
    }

    /** A paused copy copies nothing: no point about where it would go. */
    public function testAPausedCopyRaisesNothing(): void
    {
        (new StorageProtectionRepository($this->pdo))->save($this->local('Galerie', 'gallery'), $this->local('NAS', 'nas'), 30, 24, false);

        $this->assertSame([], $this->pointsWithCopies());
    }
}
