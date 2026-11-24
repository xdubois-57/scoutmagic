<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Config\SettingException;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\FeeCategoryRepository;
use Modules\Fees\FeeTariff;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Service\HouseholdTariffService;
use Modules\Fees\Service\TariffConfigurationService;
use Modules\Fees\Support\ShippedFederalScale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

#[\PHPUnit\Framework\Attributes\Group('database')]
final class TariffConfigurationServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private FeeCategoryRepository $categories;
    private HouseholdTariffService $tariffs;
    private TariffConfigurationService $service;
    private int $normalId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules/fees/module.json'), true);
        foreach ($manifest['settings'] as $setting) {
            $this->settings->register(
                $setting['key'], $setting['default_value'], $setting['type'],
                $setting['label'], $setting['description'], 'fees',
                $setting['validation_regex'] ?? null, null, $setting['editable'] ?? true
            );
        }
        $this->categories = new FeeCategoryRepository($this->pdo);
        $this->normalId = $this->categories->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $this->tariffs = new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $this->categories);
        $this->service = new TariffConfigurationService($this->tariffs, $this->categories, $this->settings);
    }

    /** @return array<string, string> */
    private function input(): array
    {
        return [
            'scale_year' => '2026-2027', 'guest_from' => '1/6', 'guest_until' => '31/8',
            'amount_normal' => '57,50', 'amount_couple' => '', 'amount_family' => '0',
            'amount_iama' => '25', 'amount_guest' => '18.25',
            'fee_category_normal' => (string) $this->normalId, 'fee_category_couple' => '',
            'fee_category_family' => '', 'fee_category_iama' => '', 'fee_category_guest' => '',
        ];
    }

    public function testOneSavePersistsFiveRatesBoundsAndReferenceYear(): void
    {
        $this->service->save('2026-2027', $this->input());
        $fresh = new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $this->categories);

        self::assertSame([
            [5750, null, 0, 2500, 1825],
            ['from' => '01/06', 'until' => '31/08', 'year' => '2026-2027'],
            '2026-2027', FeeTariff::NORMAL,
        ], [
            array_map($fresh->amountCentsFor(...), FeeTariff::cases()),
            $this->service->fields(), $this->service->referenceYear(),
            $fresh->tariffForFeeCategoryId($this->normalId),
        ]);
    }

    public function testTheDeclaredAnnualDefaultsAgreeWithTheShippedReferenceAndAreInternal(): void
    {
        $shipped = ShippedFederalScale::load();
        self::assertSame([
            ['from' => $shipped->guestFromDayMonth, 'until' => $shipped->guestUntilDayMonth, 'year' => ''], null,
        ], [$this->service->fields(), $this->service->referenceYear()]);

        $this->expectException(SettingException::class);
        $this->settings->set(TariffConfigurationService::SETTING_GUEST_FROM, '02/06', 'fees');
    }

    public function testAnnualDatesUseTheEndingYearAndAcceptAConfiguredLeapDay(): void
    {
        $input = array_replace($this->input(), [
            'scale_year' => '2027-2028', 'guest_from' => '29/2', 'guest_until' => '15/9',
        ]);
        $this->service->save('2027-2028', $input);
        $period = $this->service->periodForYear('2027-2028');

        self::assertSame(['2028-02-29', '2028-09-15', null], [
            $period['from']->format('Y-m-d') ?? null,
            $period['until']->format('Y-m-d') ?? null,
            $this->service->periodForYear('2026-2027'),
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidForms(): iterable
    {
        yield 'old form year' => [['scale_year' => '2025-2026']];
        yield 'missing form year' => [['scale_year' => null]];
        yield 'impossible start' => [['guest_from' => '31/06']];
        yield 'impossible end' => [['guest_until' => '31/09']];
        yield 'reversed bounds' => [['guest_from' => '01/09']];
        yield 'leap day in non-leap ending year' => [['guest_from' => '29/02']];
        yield 'date with a year' => [['guest_from' => '01/06/2027']];
        yield 'missing new amount' => [['amount_guest' => null]];
        yield 'negative amount' => [['amount_iama' => '-1']];
        yield 'fractional cents' => [['amount_guest' => '18,255']];
        yield 'scientific notation' => [['amount_guest' => '1e2']];
        yield 'amount array' => [['amount_guest' => ['18.25']]];
        yield 'amount too wide' => [['amount_guest' => '12345678']];
        yield 'unknown Desk id' => [['fee_category_guest' => '999999']];
        yield 'negative Desk id' => [['fee_category_iama' => '-1']];
        yield 'Desk id array' => [['fee_category_guest' => ['1']]];
        yield 'missing Desk choice' => [['fee_category_iama' => null]];
    }

    #[DataProvider('invalidForms')]
    public function testInvalidFormsCannotPartiallyChangeTheExistingScale(array $changes): void
    {
        $this->service->save('2026-2027', $this->input());
        $before = $this->snapshot();
        $message = null;
        try {
            $this->service->save('2026-2027', array_replace(
                $this->input(), ['amount_normal' => '58,23'], $changes
            ));
        } catch (SettingException $error) {
            $message = $error->getMessage();
        }

        self::assertSame([true, $before], [is_string($message) && $message !== '', $this->snapshot()]);
    }

    public function testTwoExplicitMappingsCannotClaimOneDeskCategory(): void
    {
        $this->service->save('2026-2027', $this->input());
        $before = $this->snapshot();
        $message = null;
        try {
            $this->service->save('2026-2027', array_replace($this->input(), [
                'amount_guest' => '19', 'fee_category_guest' => (string) $this->normalId,
            ]));
        } catch (SettingException $error) {
            $message = $error->getMessage();
        }

        self::assertSame(['Un tarif Desk ne peut correspondre à deux lignes du barème.', $before], [
            $message, $this->snapshot(),
        ]);
    }

    public function testASettingsFailureRollsBackPricesAndSettingsAndClearsBothCaches(): void
    {
        $this->service->save('2026-2027', $this->input());
        $before = $this->snapshot();
        $settings = new class (new SettingRepository($this->pdo)) extends SettingService {
            public function setManyInternal(array $values, ?string $moduleId = null): void
            {
                parent::setManyInternal($values, $moduleId);
                throw new \PDOException('Synthetic failure after settings writes');
            }
        };
        $config = new TariffConfigurationService($this->tariffs, $this->categories, $settings);
        $config->fields();
        $this->tariffs->amountCentsFor(FeeTariff::IAMA);
        $failed = false;
        try {
            $config->save('2026-2027', array_replace($this->input(), [
                'amount_iama' => '26', 'guest_from' => '2/6',
            ]));
        } catch (\PDOException) {
            $failed = true;
        }

        self::assertSame([true, $before, 2500, '01/06'], [
            $failed, $this->snapshot(), $this->tariffs->amountCentsFor(FeeTariff::IAMA), $config->fields()['from'],
        ]);
    }

    /** @return array{array<mixed>, array<string, string>} */
    private function snapshot(): array
    {
        $rows = $this->pdo->query(
            'SELECT household_category, fee_category_id, amount_cents FROM fees_household_tariffs ORDER BY household_category'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $freshSettings = new SettingService(new SettingRepository($this->pdo));
        $config = new TariffConfigurationService($this->tariffs, $this->categories, $freshSettings);

        return [$rows, $config->fields()];
    }
}
