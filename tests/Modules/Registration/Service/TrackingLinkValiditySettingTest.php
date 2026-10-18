<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingException;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Modules\Registration\Service\TrackingLinkValidity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * `registration_tracking_link_validity_months` as the module manifest
 * declares it (issue #932): an ordinary, editable setting of the generic
 * Configuration › Réglages › Inscriptions page, 18 by default, a whole
 * number of months from 1 to 120 — anything else refused, the stored value
 * untouched.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TrackingLinkValiditySettingTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($this->settings);
    }

    public function testTheManifestDeclaresItEditableWithTheTicketsWording(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/registration/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $declared = array_values(array_filter(
            $manifest['settings'],
            static fn(array $setting): bool => $setting['key'] === TrackingLinkValidity::SETTING
        ));

        $this->assertCount(1, $declared);
        $this->assertSame('18', $declared[0]['default_value']);
        $this->assertSame('number', $declared[0]['type']);
        $this->assertSame('Durée de validité du lien de suivi (mois)', $declared[0]['label']);
        $this->assertStringStartsWith(
            'Durée calculée depuis le dépôt de chaque demande ; ne modifie pas les demandes déjà déposées',
            $declared[0]['description']
        );
        $this->assertNotFalse($declared[0]['editable'] ?? true, 'it is edited on the generic settings page');
    }

    public function testItDefaultsToEighteenMonths(): void
    {
        $this->assertSame(18, TrackingLinkValidity::monthsFrom($this->settings->get(TrackingLinkValidity::SETTING, 'registration')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedValues(): array
    {
        return ['the lower bound' => ['1'], 'a year' => ['12'], 'the default' => ['18'], 'the upper bound' => ['120']];
    }

    #[DataProvider('acceptedValues')]
    public function testAValueWithinTheBoundsIsStoredAndRead(string $value): void
    {
        $this->settings->set(TrackingLinkValidity::SETTING, $value, 'registration');

        $this->assertSame(
            (int) $value,
            TrackingLinkValidity::monthsFrom($this->settings->get(TrackingLinkValidity::SETTING, 'registration'))
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedValues(): array
    {
        return [
            'zero' => ['0'],
            'beyond the bound' => ['121'],
            'far beyond' => ['1000'],
            'negative' => ['-1'],
            'a decimal' => ['1.5'],
            'words' => ['dix-huit'],
            'with a unit' => ['18 mois'],
        ];
    }

    #[DataProvider('refusedValues')]
    public function testAValueOutsideTheBoundsIsRefusedAndTheStoredOneKept(string $value): void
    {
        $this->settings->set(TrackingLinkValidity::SETTING, '12', 'registration');

        try {
            $this->settings->set(TrackingLinkValidity::SETTING, $value, 'registration');
            $this->fail("« {$value} » was accepted");
        } catch (SettingException) {
            // expected
        }

        $this->assertSame('12', $this->settings->get(TrackingLinkValidity::SETTING, 'registration'));
    }
}
