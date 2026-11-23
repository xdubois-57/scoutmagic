<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Journal\JournalService;
use Modules\SosStaff\Api\ForwardingState;
use Modules\SosStaff\Api\PhoneProviderInterface;
use Modules\SosStaff\Service\OnCallService;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\RedirectReconciler;
use Modules\SosStaff\Service\RedirectService;
use Modules\SosStaff\Service\SosSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedirectReconcilerTest extends TestCase
{
    /** @return array<string, array{bool, bool, string}> */
    public static function providerStates(): array
    {
        return [
            'real aligned' => [false, true, RedirectReconciler::ALIGNED],
            'real corrected' => [false, false, RedirectReconciler::CORRECTED],
            'simulation aligned' => [true, true, 'simulated'],
            'simulation corrected' => [true, false, 'simulated'],
        ];
    }

    #[DataProvider('providerStates')]
    public function testSimulationIsNeverReportedAsAHealthyRealLine(
        bool $simulated,
        bool $aligned,
        string $expectedStatus
    ): void {
        $number = '+32470123456';
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->expects(self::once())->method('readForwardingState')
            ->willReturn(new ForwardingState(true, $aligned ? $number : '+32470987654'));
        $config = $this->createStub(ProviderConfigService::class);
        $config->method('getActiveProvider')->willReturn($provider);
        $config->method('isSimulatedProviderActive')->willReturn($simulated);
        $onCall = $this->createStub(OnCallService::class);
        $onCall->method('resolveExpectedTargetAt')->willReturn([
            'date' => '2026-11-23',
            'member_id' => null,
            'previous_member_id' => null,
        ]);
        $settings = $this->createStub(SosSettingsService::class);
        $settings->method('getStaffOptions')->willReturn([]);
        $settings->method('getDefaultNumber')->willReturn($number);
        $redirect = $this->createMock(RedirectService::class);
        $redirect->expects(self::once())->method('resolveDestination')
            ->with(null, 1)->willReturn(['number' => $number, 'unreachable_member_id' => null]);
        $redirect->expects(self::once())->method('noteUnreachablePlannedMember')->with(null, 1);
        $redirect->expects($aligned ? self::never() : self::once())->method('apply')->with(null, null, 1);

        $reconciler = new RedirectReconciler(
            $onCall,
            $redirect,
            $config,
            $settings,
            $this->createStub(JournalService::class)
        );
        $result = $reconciler->reconcile(1, 'hourly');

        self::assertSame($expectedStatus, $result['status']);
        self::assertSame($number, $result['expected_number']);
    }
}
