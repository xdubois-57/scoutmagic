<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\ExternalSource\ExternalSources;
use Core\Import\FeeCategoryRepository;
use Core\Member\HouseholdFeeCategory;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Service\HouseholdTariffService;
use Modules\Fees\Service\ShippedScaleService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * When the barème opens pre-filled with the scale shipped with the site
 * (issue #355): an empty barème AND the shipped year being the year on
 * screen — never one without the other, and never a write.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ShippedScaleServiceTest extends TestCase
{
    private \PDO $pdo;
    private HouseholdTariffService $tariffs;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->tariffs = new HouseholdTariffService(
            new HouseholdTariffRepository($this->pdo),
            new FeeCategoryRepository($this->pdo)
        );
    }

    public function testAnEmptyBaremeForTheShippedYearIsPreFilled(): void
    {
        $suggestion = (new ShippedScaleService($this->tariffs))->suggestionFor('2026-2027');

        $this->assertNotNull($suggestion);
        $this->assertSame(['normal' => 5750, 'couple' => 4600, 'family' => 3900], $suggestion['amount_cents']);
        $this->assertSame('2026-2027', $suggestion['year']);
        $this->assertSame('2026-09-24', $suggestion['verified_on']);
        $this->assertSame(ExternalSources::FEES_PAGE, $suggestion['url']);
        $this->assertSame('lesscouts.be', $suggestion['host']);
        $this->assertSame(ShippedScaleService::ORIGIN, $suggestion['origin']);
    }

    /** A label the way a scout year may be written still matches. */
    public function testTheYearIsComparedNormalised(): void
    {
        $this->assertNotNull((new ShippedScaleService($this->tariffs))->suggestionFor('2026-27'));
    }

    public function testAnotherScoutYearIsNotPreFilled(): void
    {
        $service = new ShippedScaleService($this->tariffs);

        $this->assertNull($service->suggestionFor('2025-2026'));
        $this->assertNull($service->suggestionFor('2027-2028'));
        $this->assertNull($service->suggestionFor(''));
    }

    /** One stored amount is enough: the barème is the unit's own. */
    public function testABaremeWithAnyAmountIsNotPreFilled(): void
    {
        $this->tariffs->save(HouseholdFeeCategory::FAMILY, null, 3800);

        $this->assertNull((new ShippedScaleService($this->tariffs))->suggestionFor('2026-2027'));
    }

    /**
     * Saved with its amounts left empty is a choice, not a blank: offered
     * again on every visit, the shipped figures would be saved by the next
     * unrelated change to the panel.
     */
    public function testABaremeSavedEmptyIsNotPreFilled(): void
    {
        $this->tariffs->save(HouseholdFeeCategory::COUPLE, null, null);

        $this->assertNull((new ShippedScaleService($this->tariffs))->suggestionFor('2026-2027'));
    }

    public function testNothingIsWritten(): void
    {
        (new ShippedScaleService($this->tariffs))->suggestionFor('2026-2027');

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_household_tariffs')->fetchColumn());
    }

    /** A file a partial deploy lost leaves the barème as it always was. */
    public function testAnUnreadableFileProposesNothing(): void
    {
        $missing = sys_get_temp_dir() . '/no-such-federal-scale-' . uniqid() . '.json';

        $this->assertNull((new ShippedScaleService($this->tariffs, $missing))->suggestionFor('2026-2027'));
    }
}
