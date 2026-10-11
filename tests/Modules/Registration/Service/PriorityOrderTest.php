<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Modules\Registration\Service\PriorityOrder;
use Modules\Registration\Service\PriorityOrderService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The « Priorités » structure (issue #843, D5): its defaults, the one
 * validation every stored or submitted order goes through, and how the
 * setting is read and written.
 */
class PriorityOrderTest extends TestCase
{
    public function testTheDefaultsAreSiblingAlwaysThenOldRefusalThenArrival(): void
    {
        $this->assertSame(
            ['always' => ['sibling'], 'rank' => ['previous_no_room', 'arrival'], 'off' => []],
            PriorityOrder::defaults()->toZones()
        );
    }

    /**
     * The manifest's default value is exactly the defaults.
     */
    public function testTheManifestDefaultIsTheDefaultOrder(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/registration/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $default = null;
        foreach ($manifest['settings'] as $setting) {
            if ($setting['key'] === PriorityOrderService::SETTING_PRIORITY_ORDER) {
                $default = $setting['default_value'];
                $this->assertFalse($setting['editable'], 'a JSON value is no field for the generic settings page');
            }
        }

        $this->assertIsString($default);
        $this->assertTrue(PriorityOrder::fromJson($default)?->equals(PriorityOrder::defaults()));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function verdicts(): array
    {
        $all = ['sibling', 'arrival', 'previous_no_room'];

        return [
            'the defaults' => [
                ['always' => ['sibling'], 'rank' => ['previous_no_room', 'arrival'], 'off' => []],
                'valid',
            ],
            'an empty zone left out' => [['rank' => $all], 'valid'],
            'everything disabled' => [['off' => $all], 'valid'],
            'arrival in always' => [
                ['always' => ['arrival'], 'rank' => ['sibling', 'previous_no_room']],
                'rank_only_in_always',
            ],
            'a criterion missing' => [['always' => ['sibling'], 'rank' => ['arrival']], 'shape'],
            'a criterion twice' => [['always' => ['sibling'], 'rank' => $all], 'shape'],
            'an unknown criterion' => [['rank' => [...$all, 'transfer']], 'shape'],
            'an unknown zone' => [['rank' => ['sibling', 'arrival'], 'maybe' => ['previous_no_room']], 'shape'],
            'a zone that is no list' => [['rank' => 'sibling'], 'shape'],
            'a zone keyed by criterion' => [
                ['rank' => ['a' => 'sibling', 'b' => 'arrival', 'c' => 'previous_no_room']],
                'shape',
            ],
            'a criterion that is no string' => [['rank' => ['sibling', 'arrival', 3]], 'shape'],
            'not an array' => ['sibling', 'shape'],
        ];
    }

    #[DataProvider('verdicts')]
    public function testValidateSaysWhetherAndWhyAnOrderIsRefused(mixed $zones, string $verdict): void
    {
        $this->assertSame($verdict, PriorityOrder::validate($zones));
        $this->assertSame($verdict === PriorityOrder::VALID, PriorityOrder::fromZones($zones) !== null);
    }

    public function testAnOrderSurvivesItsJsonAndUnreadableJsonIsNoOrder(): void
    {
        $order = PriorityOrder::fromZones(['always' => ['previous_no_room'], 'rank' => ['arrival', 'sibling']]);
        $this->assertNotNull($order);

        $this->assertSame('{"always":["previous_no_room"],"rank":["arrival","sibling"],"off":[]}', $order->toJson());
        $this->assertTrue(PriorityOrder::fromJson($order->toJson())?->equals($order));
        $this->assertSame(['previous_no_room'], $order->always());
        $this->assertSame(['arrival', 'sibling'], $order->rank());
        $this->assertSame([], $order->off());

        $this->assertNull(PriorityOrder::fromJson('{"always":'));
        $this->assertNull(PriorityOrder::fromJson('"sibling"'));
        $this->assertNull(PriorityOrder::fromJson('{"always":["arrival"],"rank":["sibling","previous_no_room"]}'));
    }

    public function testOnlyArrivalIsRankOnly(): void
    {
        $this->assertSame(
            ['sibling' => true, 'previous_no_room' => true, 'arrival' => false],
            array_map(static fn(array $criterion): bool => $criterion['can_always'], PriorityOrder::CRITERIA)
        );
        $this->assertSame("Ordre d'arrivée de la demande", PriorityOrder::label('arrival'));
    }

    /**
     * The setting: never written reads as the defaults, a valid one as
     * itself, an unreadable one as the defaults — and says so.
     */
    #[Group('database')]
    public function testTheServiceReadsAndWritesTheSetting(): void
    {
        $settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));
        RegistrationTestHelper::registerManifestSettings($settings);
        $service = new PriorityOrderService($settings);

        $this->assertTrue($service->current()->equals(PriorityOrder::defaults()));
        $this->assertFalse($service->storedOrderIsUnusable());

        $order = PriorityOrder::fromZones(['rank' => ['arrival'], 'off' => ['sibling', 'previous_no_room']]);
        $this->assertNotNull($order);
        $service->save($order);
        $this->assertTrue($service->current()->equals($order));

        $settings->setInternal(PriorityOrderService::SETTING_PRIORITY_ORDER, '{"rank":[]}', 'registration');
        $this->assertTrue($service->current()->equals(PriorityOrder::defaults()));
        $this->assertTrue($service->storedOrderIsUnusable());
    }
}
