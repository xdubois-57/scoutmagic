<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Import\FeeCategoryRepository;
use Core\Member\Household\HouseholdRepository;
use Core\Member\Household\HouseholdService;
use Core\Security\EncryptionService;
use Modules\Fees\Repository\HouseholdDetailRepository;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Repository\IgnoredHouseholdRepository;
use Modules\Fees\Service\FeeAccuracyService;
use Modules\Fees\Service\FeesAttentionProvider;
use Modules\Fees\Service\HouseholdTariffService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * Cotisations is on by default since issue #718 § B, so its contributor now
 * runs on every new installation — one with a schedule that carries no
 * amount, no fee category and no member yet. The ticket asked for exactly
 * that to be checked: such a site has nothing to correct, and must say so
 * with no point at all rather than an error on the Points d'attention page.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FeesAttentionProviderTest extends TestCase
{
    public function testANewInstallationWithNoScheduleAndNoMemberRaisesNoPoint(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($pdo);
        $pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $scoutYearId = (int) $pdo->lastInsertId();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $feeCategories = new FeeCategoryRepository($pdo);
        $provider = new FeesAttentionProvider(new FeeAccuracyService(
            new HouseholdService(new HouseholdRepository($pdo, $encryption)),
            new HouseholdDetailRepository($pdo, $encryption),
            new HouseholdTariffService(new HouseholdTariffRepository($pdo), $feeCategories),
            new IgnoredHouseholdRepository($pdo, $encryption),
            $feeCategories
        ));

        $this->assertSame([], $provider->collect($scoutYearId));
    }
}
