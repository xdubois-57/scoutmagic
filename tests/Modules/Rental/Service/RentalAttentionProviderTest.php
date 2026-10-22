<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Attention\AttentionPoint;
use Core\Config\AppClock;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Config\UnitAddresses;
use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalComplianceRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Service\RentalAttentionProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The Locations points of issue #718 § C: a landlord address that is empty
 * (point 1, one per asset) and a compliance entry already expired (point 7,
 * never before the date — the register's reminder covers that).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private RentalAssetRepository $assets;
    private RentalComplianceRepository $compliance;
    private EncryptionService $encryption;
    private RentalStayRepository $stay;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        RentalTestHelper::registerSettings($this->settings);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->assets = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->compliance = new RentalComplianceRepository($this->pdo);
        $this->stay = new RentalStayRepository($this->pdo, $this->encryption);
    }

    private function provider(): RentalAttentionProvider
    {
        return new RentalAttentionProvider($this->assets, $this->compliance, $this->settings, $this->stay);
    }

    private function asset(string $name, string $slug): int
    {
        return $this->assets->create('Local', $name, $slug, 20, 1, null, null, null, true);
    }

    /** @return AttentionPoint[] */
    private function pointsTitled(string $needle): array
    {
        return array_values(array_filter(
            $this->provider()->collect(1),
            static fn(AttentionPoint $p): bool => str_contains($p->title, $needle)
        ));
    }

    private function daysFromToday(int $days): string
    {
        return AppClock::now()->modify("{$days} days")->format('Y-m-d');
    }

    public function testNoAssetNoPoint(): void
    {
        $this->assertSame([], $this->provider()->collect(1));
    }

    public function testAnAssetWithNoLandlordAddressAnywhereIsUrgentAndLeadsToItsSettings(): void
    {
        $this->asset('Local Saint-Georges', 'local-saint-georges');

        $points = $this->pointsTitled('bailleur');

        $this->assertCount(1, $points);
        $this->assertSame("L'adresse du bailleur de « Local Saint-Georges » est vide", $points[0]->title);
        $this->assertStringContainsString('imprimeront « — »', $points[0]->why);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertSame('/mes-locations/local-saint-georges/reglages', $points[0]->actionUrl);
    }

    public function testTheUnitPostalAddressIsEnoughWhenNoOtherLandlordIsSet(): void
    {
        $this->asset('Local', 'local');
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, "Rue du Camp 1\n1000 Bruxelles");

        $this->assertSame([], $this->pointsTitled('bailleur'));
    }

    public function testTheAssetsOwnLandlordAddressIsEnough(): void
    {
        $id = $this->asset('Prairie', 'prairie');
        $this->assets->saveLandlord($id, 'ASBL Les Amis', 'Chemin 2, 5000 Namur', null);

        $this->assertSame([], $this->pointsTitled('bailleur'));
    }

    /**
     * `Landlord` takes a level whole once its name is written, so the unit's
     * address never completes an asset landlord that has a name and no
     * address: the point says to fill THAT address, not the unit's.
     */
    public function testANamedAssetLandlordWithoutAddressAsksForItsAddressNotTheUnits(): void
    {
        $id = $this->asset('Prairie', 'prairie');
        $this->assets->saveLandlord($id, 'ASBL Les Amis', null, null);
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, 'Rue du Camp 1');

        $points = $this->pointsTitled('bailleur');

        $this->assertCount(1, $points, 'the unit address does not fill an asset landlord that has a name');
        $this->assertStringContainsString('propre bailleur, sans adresse', $points[0]->why);
        $this->assertStringNotContainsString("l'adresse postale de l'unité", $points[0]->why);
    }

    public function testANamedModuleLandlordWithoutAddressAsksForTheModulesAddress(): void
    {
        $this->asset('Local', 'local');
        $this->settings->set('landlord_name', 'ASBL Les Amis', 'rental');
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, 'Rue du Camp 1');

        $points = $this->pointsTitled('bailleur');

        $this->assertCount(1, $points);
        $this->assertStringContainsString('commun à toutes les locations', $points[0]->why);
    }

    public function testWithNoLandlordAnywhereThePointNamesTheUnitAddress(): void
    {
        $this->asset('Local', 'local');

        $this->assertStringContainsString("l'adresse postale de l'unité", $this->pointsTitled('bailleur')[0]->why);
    }

    public function testOnePointPerAssetConcerned(): void
    {
        $this->asset('Local', 'local');
        $this->asset('Prairie', 'prairie');

        $this->assertCount(2, $this->pointsTitled('bailleur'));
    }

    public function testAnArchivedAssetRaisesNothing(): void
    {
        $id = $this->asset('Ancien local', 'ancien-local');
        $this->assets->setArchived($id, true);
        $this->compliance->create($id, 'Attestation incendie', null, $this->daysFromToday(-10), null);

        $this->assertSame([], $this->provider()->collect(1));
    }

    public function testAnExpiredComplianceEntryIsAnAttentionPointWithItsDate(): void
    {
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, 'Rue du Camp 1');
        $id = $this->asset('Local', 'local');
        $expiredOn = $this->daysFromToday(-3);
        $this->compliance->create($id, 'Attestation incendie', null, $expiredOn, null);

        $points = $this->pointsTitled('a expiré');

        $this->assertCount(1, $points);
        $this->assertSame('« Attestation incendie » de « Local » a expiré', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame($expiredOn, $points[0]->dueDate?->format('Y-m-d'));
        $this->assertSame('/mes-locations/local/conformite', $points[0]->actionUrl);
    }

    /**
     * Today and later belong to the register's own reminder, which already
     * warns before the date.
     */
    public function testAnEntryNotYetExpiredRaisesNothing(): void
    {
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, 'Rue du Camp 1');
        $id = $this->asset('Local', 'local');
        $this->compliance->create($id, 'Expire aujourd\'hui', null, $this->daysFromToday(0), null);
        $this->compliance->create($id, 'Expire bientôt', null, $this->daysFromToday(5), null);
        $this->compliance->create($id, 'Sans échéance', null, null, null);

        $this->assertSame([], $this->provider()->collect(1));
    }

    // ── A damage still assessed a month after the departure (#980) ──────

    /**
     * A booking of the asset, gone $daysAgo days ago, with one incident
     * still assessed.
     *
     * @return array{booking: int, incident: int}
     */
    private function assessedDamage(
        int $assetId,
        int $daysAgo,
        ?string $awaiting = null,
        ?string $fileReference = null,
        string $reference = 'LOC-A2B3C4',
        string $status = 'confirmed'
    ): array {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_bookings
                (asset_id, reference, arrival_date, departure_date, units, status,
                 renter_name_encrypted, renter_email_encrypted, renter_email_blind_index, tracking_token_encrypted)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assetId,
            $reference,
            $this->daysFromToday(-$daysAgo - 3),
            $this->daysFromToday(-$daysAgo),
            $status,
            $this->encryption->encrypt('Jeanne Martin', 'rental_bookings.renter_name'),
            $this->encryption->encrypt('jeanne@example.org', 'rental_bookings.renter_email'),
            $this->encryption->blindIndex('jeanne@example.org', 'email'),
            $this->encryption->encrypt('token', 'rental_bookings.tracking_token'),
        ]);
        $booking = (int) $this->pdo->lastInsertId();

        $incident = (int) $this->stay->createIncident($booking, 'Porte fendue par Jeanne Martin', 30000, [], 1);
        if ($awaiting !== null || $fileReference !== null) {
            $this->stay->decideIncident($incident, IncidentDecision::PENDING, null, 1, $awaiting, $fileReference);
        }

        return ['booking' => $booking, 'incident' => $incident];
    }

    /** A landlord for every asset, so only the damages speak. */
    private function landlordEverywhere(): void
    {
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, 'Rue du Camp 1');
    }

    public function testADamageAssessedLongAfterTheDepartureIsAPointWithoutTheRentersName(): void
    {
        $this->landlordEverywhere();
        $assetId = $this->asset('Chalet des Fagnes', 'chalet');
        $damage = $this->assessedDamage($assetId, 60, 'le devis du menuisier.', 'DOS-42');

        $points = $this->pointsTitled('Caution retenue');

        $this->assertCount(1, $points);
        $this->assertSame(
            'Caution retenue depuis 60 jours : dégât en attente du devis du menuisier',
            $points[0]->title
        );
        $this->assertStringContainsString('LOC-A2B3C4', $points[0]->why);
        $this->assertStringContainsString('« Chalet des Fagnes »', $points[0]->why);
        $this->assertStringContainsString('DOS-42', $points[0]->why);
        $this->assertStringContainsString(
            'partie le ' . AppClock::now()->modify('-60 days')->format('d/m/Y'),
            $points[0]->why
        );
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame("Trancher l'incident", $points[0]->actionLabel);
        $this->assertSame(
            '/mes-locations/chalet/reservations/' . $damage['booking'] . '/etat-des-lieux#incident-'
                . $damage['incident'],
            $points[0]->actionUrl
        );
        foreach ([$points[0]->title, $points[0]->why] as $text) {
            $this->assertStringNotContainsString('Jeanne', $text, 'never the renter, nor the description');
            $this->assertStringNotContainsString('Porte fendue', $text);
        }
    }

    public function testWithNothingAwaitedThePointSaysTheAssessment(): void
    {
        $this->landlordEverywhere();
        $this->assessedDamage($this->asset('Local', 'local'), 45);

        $points = $this->pointsTitled('Caution retenue');

        $this->assertCount(1, $points);
        $this->assertSame("Caution retenue depuis 45 jours : dégât en cours d'évaluation", $points[0]->title);
        $this->assertStringNotContainsString('référence du dossier', $points[0]->why);
    }

    public function testWithinTheMonthADecidedDamageOrAnAbandonedBookingRaiseNothing(): void
    {
        $this->landlordEverywhere();
        $assetId = $this->asset('Local', 'local');
        // 27 days: within a month whatever the month — and below the
        // repository's 28-day bound.
        $this->assessedDamage($assetId, 27, 'un devis', null, 'LOC-A2B3C4');
        $decided = $this->assessedDamage($assetId, 90, 'un devis', null, 'LOC-A2B3C5');
        $this->stay->decideIncident($decided['incident'], IncidentDecision::CHARGE, null, 1);
        $this->assessedDamage($assetId, 90, 'un devis', null, 'LOC-A2B3C6', 'cancelled');

        $this->assertSame([], $this->pointsTitled('Caution retenue'));
    }

    public function testAClosedBookingStillHoldingADamageIsAPointButAnArchivedAssetIsNot(): void
    {
        $this->landlordEverywhere();
        $this->assessedDamage($this->asset('Local', 'local'), 40, null, null, 'LOC-A2B3C4', 'closed');
        $archived = $this->asset('Ancien local', 'ancien');
        $this->assessedDamage($archived, 40, null, null, 'LOC-A2B3C5');
        $this->pdo->prepare('UPDATE rental_assets SET is_archived = 1 WHERE id = ?')->execute([$archived]);

        $points = $this->pointsTitled('Caution retenue');

        $this->assertCount(1, $points);
        $this->assertStringContainsString('LOC-A2B3C4', $points[0]->why);
    }

    public function testWithoutTheStayStorageNoDamageIsRead(): void
    {
        $this->landlordEverywhere();
        $this->assessedDamage($this->asset('Local', 'local'), 60);

        $provider = new RentalAttentionProvider($this->assets, $this->compliance, $this->settings);

        $this->assertSame([], $provider->collect(1));
    }

    /**
     * One calendar month after the departure, the point appears the day
     * after (Q8): never on the day itself, whatever the month's length.
     *
     * @return iterable<string, array{string, string, ?int}>
     */
    public static function calendarMonthBoundaries(): iterable
    {
        // A 30-day month: 30 days is the month itself, 31 is past it.
        yield '30 days in September' => ['2026-09-20', '2026-10-20', null];
        yield '31 days after 20 September' => ['2026-09-20', '2026-10-21', 31];
        // A 31-day month: 31 days is still the month itself.
        yield '31 days in January' => ['2026-01-01', '2026-02-01', null];
        yield '32 days after 1 January' => ['2026-01-01', '2026-02-02', 32];
        // February: 28 or 29 days are already past a month.
        yield '28 days in February' => ['2026-02-01', '2026-03-01', null];
        yield '29 days after 1 February' => ['2026-02-01', '2026-03-02', 29];
        // The last day of a longer month: the month ends on the last day
        // of the next, 28 February, not on 3 March.
        yield '31 January to 28 February' => ['2026-01-31', '2026-02-28', null];
        yield '31 January to 1 March' => ['2026-01-31', '2026-03-01', 29];
        yield '31 January to 29 February, leap year' => ['2028-01-31', '2028-02-29', null];
        yield '31 January to 1 March, leap year' => ['2028-01-31', '2028-03-01', 30];
        yield '31 August to 30 September' => ['2026-08-31', '2026-09-30', null];
        yield '31 August to 1 October' => ['2026-08-31', '2026-10-01', 31];
        yield 'across the year' => ['2026-12-31', '2027-01-31', null];
        yield 'across the year, a day later' => ['2026-12-31', '2027-02-01', 32];
        yield 'before the departure' => ['2026-09-20', '2026-09-01', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('calendarMonthBoundaries')]
    public function testThePointWaitsForAWholeCalendarMonthAfterTheDeparture(
        string $departure,
        string $today,
        ?int $expected
    ): void {
        $this->assertSame(
            $expected,
            RentalAttentionProvider::daysHeldPastAMonth(
                new \DateTimeImmutable($departure),
                new \DateTimeImmutable($today . ' 08:15:00')
            )
        );
    }

    public function testTheHourOfTheDayDoesNotMoveTheBoundary(): void
    {
        $this->assertNull(RentalAttentionProvider::daysHeldPastAMonth(
            new \DateTimeImmutable('2026-09-20 00:00:00'),
            new \DateTimeImmutable('2026-10-20 23:59:59')
        ));
        $this->assertSame(31, RentalAttentionProvider::daysHeldPastAMonth(
            new \DateTimeImmutable('2026-09-20 23:00:00'),
            new \DateTimeImmutable('2026-10-21 00:01:00')
        ));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function awaitedTexts(): iterable
    {
        yield 'nothing' => [null, "dégât en cours d'évaluation"];
        yield 'blank' => ['  … ', "dégât en cours d'évaluation"];
        yield 'le' => ['Le devis du menuisier…', 'dégât en attente du devis du menuisier'];
        yield 'les' => ['les photos', 'dégât en attente des photos'];
        yield 'un' => ['un devis', "dégât en attente d'un devis"];
        yield 'une' => ['une expertise', "dégât en attente d'une expertise"];
        yield 'la' => ['la facture', 'dégât en attente de la facture'];
        yield "l'" => ["l'assurance du locataire", "dégât en attente de l'assurance du locataire"];
        yield 'a vowel' => ['expertise', "dégât en attente d'expertise"];
        yield 'a consonant' => ['devis', 'dégât en attente de devis'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('awaitedTexts')]
    public function testWhatTheAssessmentWaitsForJoinsTheSentence(?string $awaiting, string $expected): void
    {
        $this->assertSame($expected, RentalAttentionProvider::damageWaiting($awaiting));
    }
}
