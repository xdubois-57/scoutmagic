<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\CampsException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

class CampServiceTest extends TestCase
{
    private \PDO $pdo;
    private CampRepository $camps;
    private AuditService $audit;
    private CampService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->camps = new CampRepository($this->pdo, $encryption);
        $this->audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $this->service = new CampService($this->camps, $this->audit);
        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");

        // Real sections and a real member: both columns are foreign keys,
        // and validate() now refuses an id nobody offers rather than
        // letting MySQL answer with a 500.
        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'ECL', 'Éclaireurs', 1)");
        $this->pdo->exec("INSERT INTO sections (id, age_branch_id, desk_code, name) VALUES (3, 1, 'ECL', 'Éclaireurs')");
        $this->pdo->exec("INSERT INTO sections (id, age_branch_id, desk_code, name) VALUES (4, 1, 'PIO', 'Pionniers')");
        $this->pdo->exec("INSERT INTO members (id, desk_id) VALUES (7, 'D-0000007')");
    }

    // ── The one structural rule: dates XOR year ──────────────────────

    public function testDatesAndAYearTogetherAreRefused(): void
    {
        $this->expectException(CampsException::class);
        $this->service->validate([
            'start_date' => '2028-07-12', 'end_date' => '2028-07-19', 'year_only' => '2028',
        ]);
    }

    public function testNeitherDatesNorAYearIsRefused(): void
    {
        $this->expectException(CampsException::class);
        $this->service->validate(['start_date' => '', 'end_date' => '', 'year_only' => '']);
    }

    public function testHalfADateRangeIsRefused(): void
    {
        $this->expectException(CampsException::class);
        $this->service->validate(['start_date' => '2028-07-12', 'end_date' => '', 'year_only' => '']);
    }

    public function testAStayEndingBeforeItStartsIsRefused(): void
    {
        $this->expectException(CampsException::class);
        $this->service->validate(['start_date' => '2028-07-19', 'end_date' => '2028-07-12']);
    }

    public function testARealDateRangeIsAccepted(): void
    {
        $values = $this->service->validate(['start_date' => '2028-07-12', 'end_date' => '2028-07-19']);

        $this->assertSame('2028-07-12', $values['start_date']);
        $this->assertNull($values['year_only']);
    }

    public function testAYearAloneIsAccepted(): void
    {
        $values = $this->service->validate(['year_only' => '2029']);

        $this->assertSame(2029, $values['year_only']);
        $this->assertNull($values['start_date']);
    }

    public function testAnImpossibleDateIsRefused(): void
    {
        $this->expectException(CampsException::class);
        $this->service->validate(['start_date' => '2028-02-31', 'end_date' => '2028-03-01']);
    }

    public function testAnUnknownStayTypeOrStatusIsRefused(): void
    {
        try {
            $this->service->validate(['year_only' => '2029', 'stay_type' => 'croisiere']);
            $this->fail('an unknown stay type should be refused');
        } catch (CampsException) {
        }

        $this->expectException(CampsException::class);
        $this->service->validate(['year_only' => '2029', 'status' => 'peut_etre']);
    }

    // ── Prices as a chief actually types them ────────────────────────

    /**
     * @dataProvider priceInputs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('priceInputs')]
    public function testPricesAreReadTheWayTheyAreWrittenOnAQuote(string $typed, ?int $expected): void
    {
        $this->assertSame($expected, $this->service->validate(['year_only' => '2029', 'price' => $typed])['price_cents']);
    }

    /**
     * @return array<string, array{string, ?int}>
     */
    public static function priceInputs(): array
    {
        return [
            'plain' => ['2450', 245000],
            'thin space' => ['2 450', 245000],
            'comma decimals' => ['2450,50', 245050],
            'dot decimals' => ['2450.50', 245050],
            'with currency' => ['2450,50 €', 245050],
            'empty is not zero' => ['', null],
        ];
    }

    public function testANonsensePriceIsRefused(): void
    {
        $this->expectException(CampsException::class);
        $this->service->validate(['year_only' => '2029', 'price' => 'gratuit']);
    }

    public function testANegativePriceIsRefused(): void
    {
        $this->expectException(CampsException::class);
        $this->service->validate(['year_only' => '2029', 'price' => '-100']);
    }

    // ── Foreign keys are checked here, not by MySQL ──────────────────

    public function testASectionThatDoesNotExistIsRefusedRatherThanSentToTheDatabase(): void
    {
        // camp_camp_sections.section_id is a foreign key. A `<select>`
        // somebody edited used to reach MySQL and come back as a
        // PDOException — a 500 on a chief's form.
        $this->expectException(CampsException::class);
        $this->expectExceptionMessageMatches('/section/');

        $this->service->validate(['year_only' => '2029', 'section_ids' => [3, 999]]);
    }

    public function testTheRealSectionsAreKept(): void
    {
        $values = $this->service->validate(['year_only' => '2029', 'section_ids' => [3, 4]]);

        $this->assertSame([3, 4], $values['section_ids']);
    }

    public function testAnInactiveSectionIsRefusedToo(): void
    {
        $this->pdo->exec('UPDATE sections SET is_active = 0 WHERE id = 4');

        $this->expectException(CampsException::class);
        $this->service->validate(['year_only' => '2029', 'section_ids' => [4]]);
    }

    public function testAMemberThatDoesNotExistIsRefused(): void
    {
        $this->expectException(CampsException::class);
        $this->expectExceptionMessageMatches('/membre/');

        $this->service->validate(['year_only' => '2029', 'booked_by_member_id' => '999']);
    }

    public function testARealMemberIsKept(): void
    {
        $values = $this->service->validate(['year_only' => '2029', 'booked_by_member_id' => '7']);

        $this->assertSame(7, $values['booked_by_member_id']);
    }

    // ── Creation and the history it writes ───────────────────────────

    public function testCreateStoresTheStayAndOpensItsHistory(): void
    {
        $id = $this->service->create(1, [
            'start_date' => '2028-07-12', 'end_date' => '2028-07-19',
            'status' => Camp::STATUS_CONFIRMED, 'price' => '2450',
        ], 42, fn(array $ids): ?string => null);

        $camp = $this->camps->findById($id);
        $this->assertNotNull($camp);
        $this->assertSame(245000, $camp->priceCents);
        $this->assertSame(Camp::STATUS_CONFIRMED, $camp->status);

        $history = $this->audit->page(CampService::ENTITY_TYPE, $id, 1, 10);
        $this->assertSame(1, $history->total);
        $this->assertSame('camp', $history->entries[0]->fieldKey);
        $this->assertSame('12–19 juillet 2028', $history->entries[0]->toValue);
        $this->assertSame(42, $history->entries[0]->actorUserAccountId);
    }

    public function testSectionsChosenAtCreationAreRecorded(): void
    {
        $id = $this->service->create(1, [
            'year_only' => '2029', 'section_ids' => [3, 4],
        ], 42, fn(array $ids): ?string => 'Éclaireurs, Pionniers');

        $fields = array_map(
            static fn($e) => $e->fieldKey,
            $this->audit->page(CampService::ENTITY_TYPE, $id, 1, 10)->entries
        );
        $this->assertContains('sections', $fields);
    }

    public function testUpdateRecordsEachChangedFieldSeparately(): void
    {
        $id = $this->service->create(1, ['year_only' => '2029'], 42, fn(array $ids): ?string => null);
        $camp = $this->camps->findById($id);
        $this->assertNotNull($camp);

        $this->service->update($camp, [
            'year_only' => '2029',
            'status' => Camp::STATUS_CONFIRMED,
            'price' => '2450',
        ], 42, fn(array $ids): ?string => null);

        $entries = $this->audit->page(CampService::ENTITY_TYPE, $id, 1, 20)->entries;
        $byField = [];
        foreach ($entries as $entry) {
            $byField[$entry->fieldKey] = $entry;
        }

        // Field by field, not one "camp modifié": a history is read to
        // find out what a value USED to be.
        $this->assertArrayHasKey('status', $byField);
        $this->assertSame('À confirmer', $byField['status']->fromValue);
        $this->assertSame('Confirmé', $byField['status']->toValue);
        $this->assertArrayHasKey('price', $byField);
        $this->assertNull($byField['price']->fromValue);
        $this->assertSame('2 450,00 €', $byField['price']->toValue);
    }

    public function testSavingAnUntouchedFormWritesNoHistory(): void
    {
        $id = $this->service->create(1, [
            'start_date' => '2028-07-12', 'end_date' => '2028-07-19', 'price' => '2450',
        ], 42, fn(array $ids): ?string => null);
        $camp = $this->camps->findById($id);
        $this->assertNotNull($camp);
        $before = $this->audit->page(CampService::ENTITY_TYPE, $id, 1, 20)->total;

        $this->service->update($camp, [
            'start_date' => '2028-07-12', 'end_date' => '2028-07-19', 'price' => '2450',
        ], 42, fn(array $ids): ?string => null);

        // Core\Audit stores whatever it is handed, so "did anything
        // change" is this service's job — otherwise every save of an
        // untouched form would add seven lines saying nothing.
        $this->assertSame($before, $this->audit->page(CampService::ENTITY_TYPE, $id, 1, 20)->total);
    }

    public function testTheBookingPersonsNameIsEncryptedAtRest(): void
    {
        $this->service->create(1, [
            'year_only' => '2029', 'booked_by_name' => 'Thomas Dupont',
        ], 42, fn(array $ids): ?string => null);

        $stored = (string) $this->pdo->query('SELECT booked_by_name FROM camp_camps')->fetchColumn();
        $this->assertStringNotContainsString('Dupont', $stored);
        $this->assertSame('Thomas Dupont', $this->camps->findById(1)?->bookedByName);
    }

    // ── Splitting upcoming from past ─────────────────────────────────

    public function testSplitOrdersUpcomingSoonestFirstAndPastNewestFirst(): void
    {
        $far = $this->makeCamp('2030-07-19');
        $soon = $this->makeCamp('2027-07-19');
        $old = $this->makeCamp('2020-07-19');
        $recent = $this->makeCamp('2024-07-19');

        $split = $this->service->split(
            [$far, $soon, $old, $recent],
            new \DateTimeImmutable('2026-08-24')
        );

        $this->assertSame([$soon->id, $far->id], array_map(static fn(Camp $c): int => $c->id, $split['upcoming']));
        $this->assertSame([$recent->id, $old->id], array_map(static fn(Camp $c): int => $c->id, $split['past']));
    }

    public function testAYearOnlyStaySortsAfterEveryDatedStayOfItsOwnYear(): void
    {
        $dated = $this->makeCamp('2024-07-19');
        $yearOnly = $this->makeCamp(null, 2024);

        $split = $this->service->split([$dated, $yearOnly], new \DateTimeImmutable('2026-08-24'));

        // Both are "2024", but a bare year means "somewhere in that year",
        // so it lands after what is precisely dated rather than jumping
        // ahead of it.
        $this->assertSame([$yearOnly->id, $dated->id], array_map(static fn(Camp $c): int => $c->id, $split['past']));
    }

    private function makeCamp(?string $endDate, ?int $yearOnly = null, string $status = Camp::STATUS_CONFIRMED): Camp
    {
        $id = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP,
            $endDate, $endDate, $yearOnly,
            $status, null, null, null, null, []
        );
        $camp = $this->camps->findById($id);
        $this->assertNotNull($camp);

        return $camp;
    }
}
