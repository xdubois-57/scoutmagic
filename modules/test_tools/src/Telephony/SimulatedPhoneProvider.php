<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Telephony;

use Core\Config\SettingService;
use Modules\SosStaff\Api\ForwardingState;
use Modules\SosStaff\Api\PhoneLine;
use Modules\SosStaff\Api\PhoneProviderInterface;
use Modules\TestTools\Service\MailSandboxService;

/**
 * One simulated line whose forwarding is a setting: what setForwarding()
 * wrote is what readForwardingState() reads back, across requests, so the
 * page shows a « vraie » state that moves when the site redirects. Nothing
 * leaves the server. Only ever built where SimulatedTelephony::isAllowed()
 * holds (ARCHITECTURE.md §8.63).
 */
final class SimulatedPhoneProvider implements PhoneProviderInterface
{
    /** The simulated SOS number: a Brussels-format number no line carries. */
    public const LINE_NUMBER = '+3220000000';

    public function __construct(private SettingService $settingService)
    {
    }

    public function readForwardingState(): ForwardingState
    {
        $number = (string) ($this->settingService->get(
            SimulatedTelephony::SETTING_FORWARDING,
            MailSandboxService::MODULE_ID
        ) ?? '');

        return new ForwardingState($number !== '', $number !== '' ? $number : null);
    }

    public function setForwarding(string $number): void
    {
        $this->settingService->setInternal(
            SimulatedTelephony::SETTING_FORWARDING,
            $number,
            MailSandboxService::MODULE_ID
        );
    }

    public function testConnection(): bool
    {
        return true;
    }

    public function listLines(): array
    {
        return [new PhoneLine('simulation', self::LINE_NUMBER, self::LINE_NUMBER)];
    }
}
