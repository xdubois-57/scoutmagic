<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Compliance;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalComplianceRepository;
use Modules\Rental\Service\RentalComplianceService;
use Modules\Rental\Service\RentalException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The asset paperwork register (§6.33).
 *
 * **The most important assertions here are about what the register refuses
 * to say.** It is a reminder list, not a compliance check: it knows no
 * regulation, computes no status and passes no judgement, because what a
 * hall needs differs by commune, by federation and by year — and a green
 * tick derived from a date would be a legal opinion this software has no
 * business giving.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalComplianceServiceTest extends TestCase
{
    private \PDO $pdo;
    private RentalComplianceRepository $repository;
    private RentalComplianceService $service;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->repository = new RentalComplianceRepository($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'rental',
            RentalComplianceService::SETTING_LABEL_SUGGESTIONS,
            'Attestation incendie, Assurance RC, Autorisation communale',
            'text',
            'Intitulés proposés',
            'Suggestions.',
        ]);

        $this->service = new RentalComplianceService(
            $this->repository,
            $settingService,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->assetId = (new RentalAssetRepository($this->pdo, $encryption))
            ->create('Local', 'Local Saint-Georges', 'local-saint-georges', 60, 1, '18:00', '11:00', null, true);
    }

    // ── The register itself ─────────────────────────────────────────────

    public function testAnEntryIsAdded(): void
    {
        $id = $this->service->add($this->assetId, 'Attestation incendie', '2028-03-01', 'Renouvelée par la commune');

        $items = $this->service->forAsset($this->assetId);
        $this->assertCount(1, $items);
        $this->assertSame($id, $items[0]->id);
        $this->assertSame('Attestation incendie', $items[0]->label);
        $this->assertSame('2028-03-01', $items[0]->expiresOn);
        $this->assertSame('Renouvelée par la commune', $items[0]->remark);
    }

    public function testAnEntryNeedsALabel(): void
    {
        $this->expectException(RentalException::class);
        $this->service->add($this->assetId, '   ', '2028-03-01', null);
    }

    public function testAnEntryNeedsNothingElse(): void
    {
        // A label alone is a valid entry. Plenty of paperwork has no expiry
        // and no scan, and demanding either would make the register the
        // thing nobody fills in.
        $id = $this->service->add($this->assetId, 'Numéro d\'enregistrement', null, null);

        $item = $this->repository->findById($id);
        $this->assertNotNull($item);
        $this->assertNull($item->expiresOn);
        $this->assertNull($item->fileId);
    }

    public function testAnEmptyDateMeansNoExpiryRatherThanToday(): void
    {
        // The difference between paperwork that never runs out and
        // paperwork that ran out this morning.
        $id = $this->service->add($this->assetId, 'Sans échéance', '', null);

        $item = $this->repository->findById($id);
        $this->assertNotNull($item);
        $this->assertNull($item->expiresOn);
    }

    public function testAnUnparseableDateIsDroppedRatherThanStored(): void
    {
        $id = $this->service->add($this->assetId, 'Bizarre', 'pas une date', null);

        $item = $this->repository->findById($id);
        $this->assertNotNull($item);
        $this->assertNull($item->expiresOn);
    }

    public function testEntriesComeBackSoonestFirstWithUndatedOnesLast(): void
    {
        $this->service->add($this->assetId, 'Sans échéance', null, null);
        $this->service->add($this->assetId, 'Plus tard', '2029-01-01', null);
        $this->service->add($this->assetId, 'Bientôt', '2027-02-01', null);

        $labels = array_map(static fn($item) => $item->label, $this->service->forAsset($this->assetId));

        $this->assertSame(['Bientôt', 'Plus tard', 'Sans échéance'], $labels);
    }

    public function testAnEntryIsUpdated(): void
    {
        $id = $this->service->add($this->assetId, 'Assurance RC', '2027-06-01', null);

        $this->service->update($this->assetId, $id, 'Assurance RC (renouvelée)', '2028-06-01', 'Contrat n°42');

        $item = $this->repository->findById($id);
        $this->assertNotNull($item);
        $this->assertSame('Assurance RC (renouvelée)', $item->label);
        $this->assertSame('2028-06-01', $item->expiresOn);
        $this->assertSame('Contrat n°42', $item->remark);
    }

    public function testAnEntryOfAnotherAssetIsNeverTouched(): void
    {
        // The asset check is the real guard: an item id alone must not let a
        // manager of one asset reach another asset's register.
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $otherAssetId = (new RentalAssetRepository($this->pdo, $encryption))
            ->create('Local', 'Hangar', 'hangar', 20, 1, null, null, null, true);

        $id = $this->service->add($otherAssetId, 'Assurance RC', '2027-06-01', null);

        $this->expectException(RentalException::class);
        $this->service->update($this->assetId, $id, 'Détourné', null, null);
    }

    public function testDeletingAnEntryOfAnotherAssetIsRefusedToo(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $otherAssetId = (new RentalAssetRepository($this->pdo, $encryption))
            ->create('Local', 'Hangar', 'hangar', 20, 1, null, null, null, true);

        $id = $this->service->add($otherAssetId, 'Assurance RC', '2027-06-01', null);

        $this->expectException(RentalException::class);
        $this->service->delete($this->assetId, $id);
    }

    public function testAnEntryIsDeleted(): void
    {
        $id = $this->service->add($this->assetId, 'Assurance RC', '2027-06-01', null);

        $this->service->delete($this->assetId, $id);

        $this->assertSame([], $this->service->forAsset($this->assetId));
    }

    // ── Suggestions live in configuration, never in code (§6.33) ─────────

    public function testTheSuggestedLabelsComeFromASetting(): void
    {
        $this->assertSame(
            ['Attestation incendie', 'Assurance RC', 'Autorisation communale'],
            $this->service->labelSuggestions()
        );
    }

    public function testASuggestionIsOnlyEverASuggestion(): void
    {
        // Nothing behaves differently for a suggested label than for one
        // somebody typed — which is what stops the list becoming a de facto
        // enum.
        $suggested = $this->service->add($this->assetId, 'Assurance RC', '2028-01-01', null);
        $invented = $this->service->add($this->assetId, 'Contrôle ascenseur', '2028-01-01', null);

        $first = $this->repository->findById($suggested);
        $second = $this->repository->findById($invented);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->expiresOn, $second->expiresOn);
    }

    // ── Dates, without a verdict ────────────────────────────────────────

    public function testAnExpiredEntryKnowsItsDatePassed(): void
    {
        $id = $this->service->add($this->assetId, 'Attestation incendie', '2027-01-01', null);
        $item = $this->repository->findById($id);
        $this->assertNotNull($item);

        $this->assertTrue($item->hasExpired(new \DateTimeImmutable('2027-06-01')));
        $this->assertSame(-151, $item->daysUntilExpiry(new \DateTimeImmutable('2027-06-01')));
    }

    public function testAnEntryWithNoDateAnswersNullRatherThanInventingANumber(): void
    {
        $id = $this->service->add($this->assetId, 'Sans échéance', null, null);
        $item = $this->repository->findById($id);
        $this->assertNotNull($item);

        $this->assertFalse($item->hasExpired(new \DateTimeImmutable('2027-06-01')));
        $this->assertNull($item->daysUntilExpiry(new \DateTimeImmutable('2027-06-01')));
    }

    public function testTheClassOffersNoComplianceVerdictAtAll(): void
    {
        // Pinned deliberately: the day somebody adds isValid() or a status
        // enum here, this software starts giving legal advice.
        $methods = get_class_methods(\Modules\Rental\Compliance\ComplianceItem::class);

        foreach (['isValid', 'isCompliant', 'status', 'isOk'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods);
        }
    }

    // ── Reminder selection (§6.29) ──────────────────────────────────────

    public function testAnEntryExpiringSoonIsDueForAReminder(): void
    {
        $this->service->add($this->assetId, 'Attestation incendie', '2027-07-15', null);

        $due = $this->service->dueForReminder(new \DateTimeImmutable('2027-07-01'));

        $this->assertCount(1, $due);
    }

    public function testAnEntryExpiringNextYearIsNotDueYet(): void
    {
        $this->service->add($this->assetId, 'Attestation incendie', '2029-07-15', null);

        $this->assertSame([], $this->service->dueForReminder(new \DateTimeImmutable('2027-07-01')));
    }

    public function testAnAlreadyExpiredEntryIsStillDue(): void
    {
        // A certificate that ran out three weeks ago while nobody was
        // looking is exactly what a reminder is for — the register must not
        // go quietest precisely when it matters most.
        $this->service->add($this->assetId, 'Attestation incendie', '2027-06-01', null);

        $this->assertCount(1, $this->service->dueForReminder(new \DateTimeImmutable('2027-07-01')));
    }

    public function testAnEntryAlreadyWarnedAboutTodayIsNotDueAgain(): void
    {
        $id = $this->service->add($this->assetId, 'Attestation incendie', '2027-07-15', null);
        $this->service->markReminded($id, new \DateTimeImmutable('2027-07-01'));

        $this->assertSame([], $this->service->dueForReminder(new \DateTimeImmutable('2027-07-01')));
    }

    public function testAnEntryWarnedAboutYesterdayIsDueAgainToday(): void
    {
        // The daily stamp is a per-day guard; the sent-reminders table is
        // what stops a genuine repeat.
        $id = $this->service->add($this->assetId, 'Attestation incendie', '2027-07-15', null);
        $this->service->markReminded($id, new \DateTimeImmutable('2027-06-30'));

        $this->assertCount(1, $this->service->dueForReminder(new \DateTimeImmutable('2027-07-01')));
    }

    public function testAnEntryWithNoDateIsNeverDue(): void
    {
        $this->service->add($this->assetId, 'Sans échéance', null, null);

        $this->assertSame([], $this->service->dueForReminder(new \DateTimeImmutable('2027-07-01')));
    }

    // ── The journal never carries a document's contents ─────────────────

    public function testTheJournalRecordsTheLabelAndNothingElse(): void
    {
        $this->service->add($this->assetId, 'Attestation incendie', '2028-01-01', 'Signée par le bourgmestre Dupont');

        $rows = $this->pdo->query("SELECT description, context FROM event_log WHERE event_type = 'rental_compliance_added'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Attestation incendie', (string) $rows[0]['description']);
        $this->assertStringNotContainsString('Dupont', (string) $rows[0]['description']);
        $this->assertStringNotContainsString('Dupont', (string) $rows[0]['context']);
    }
}
