<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Config;

use Core\Config\SettingException;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Config\UnitAddresses;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The unit's two addresses (issue #497): declared editable in the core
 * group of Configuration › Paramètres, and read back as nothing while
 * nobody has written them.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class UnitAddressesTest extends TestCase
{
    private SettingService $settings;
    private SettingRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new SettingRepository(DatabaseTestHelper::createTestDatabase());
        $this->settings = new SettingService($this->repository);
        UnitAddresses::register($this->settings);
    }

    /**
     * Core's, editable, and so listed on the generic settings page with no
     * screen of their own — which is what the ticket counts on.
     */
    public function testEachAddressIsAnEditableCoreSetting(): void
    {
        foreach ([
            UnitAddresses::POSTAL_ADDRESS,
            UnitAddresses::PREMISES_ADDRESS,
            UnitAddresses::PREMISES_LATITUDE,
            UnitAddresses::PREMISES_LONGITUDE,
        ] as $key) {
            $row = $this->repository->findByModuleAndKey(null, $key);
            $this->assertNotNull($row, $key);
            $this->assertTrue((bool) $row['editable'], $key);
            $this->assertSame('', $row['setting_value'], $key . ': empty until somebody writes it');
            $this->assertMatchesRegularExpression('/[éèàç]|l\'unité|des locaux/u', $row['label'] . $row['description']);
        }
    }

    public function testNothingWrittenReadsAsNothing(): void
    {
        $this->assertNull(UnitAddresses::postalAddress($this->settings));
        $this->assertNull(UnitAddresses::premisesAddress($this->settings));
        $this->assertNull(UnitAddresses::premisesCoordinates($this->settings));
    }

    public function testAWrittenAddressIsReadTrimmed(): void
    {
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, "  Rue du Local 1\n1000 Bruxelles \n");

        $this->assertSame("Rue du Local 1\n1000 Bruxelles", UnitAddresses::postalAddress($this->settings));
    }

    public function testCoordinatesAreReadAsAPair(): void
    {
        $this->settings->set(UnitAddresses::PREMISES_LATITUDE, '50.8466');
        $this->assertNull(UnitAddresses::premisesCoordinates($this->settings), 'half a point is not a place');

        $this->settings->set(UnitAddresses::PREMISES_LONGITUDE, '-4.3528');
        $this->assertSame(
            ['latitude' => 50.8466, 'longitude' => -4.3528],
            UnitAddresses::premisesCoordinates($this->settings)
        );
    }

    /**
     * `number` alone would take these — `is_numeric()` accepts 1e3 and 400
     * — and put the unit's meadow off the map.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function outOfRange(): iterable
    {
        yield 'latitude above 90' => [UnitAddresses::PREMISES_LATITUDE, '90.5'];
        yield 'latitude in scientific notation' => [UnitAddresses::PREMISES_LATITUDE, '5e1'];
        yield 'longitude above 180' => [UnitAddresses::PREMISES_LONGITUDE, '181'];
        yield 'a comma decimal separator' => [UnitAddresses::PREMISES_LONGITUDE, '4,35'];
        yield 'seven decimals' => [UnitAddresses::PREMISES_LATITUDE, '50.1234567'];
    }

    #[DataProvider('outOfRange')]
    public function testACoordinateOutOfRangeIsRefused(string $key, string $value): void
    {
        $this->expectException(SettingException::class);

        $this->settings->set($key, $value);
    }

    public function testTheBoundsThemselvesAreAccepted(): void
    {
        $this->settings->set(UnitAddresses::PREMISES_LATITUDE, '-90');
        $this->settings->set(UnitAddresses::PREMISES_LONGITUDE, '180.000000');

        $this->assertSame(
            ['latitude' => -90.0, 'longitude' => 180.0],
            UnitAddresses::premisesCoordinates($this->settings)
        );
    }
}
