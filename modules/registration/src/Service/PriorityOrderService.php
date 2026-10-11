<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingService;

/**
 * Reads and writes the « Priorités » of the recommendation (issue #843,
 * D5) — a Service\PriorityOrder, stored as JSON in the
 * `registration_priority_order` setting.
 *
 * The setting is declared `editable: false` in module.json: a JSON value is
 * no field for Configuration > Réglages to offer, and the « Priorités » box
 * of Formulaire d'inscription › Réglages is the one screen that writes it,
 * through save() — after PriorityOrder has validated it, which is what
 * makes setInternal() (the application's own door) the right one.
 *
 * A stored value that is not a valid order — written by hand in the
 * database, or by a future version this one does not understand — is read
 * as the defaults, never half-applied, and storedOrderIsUnusable() lets the
 * box say so rather than show the defaults as if a chief had chosen them.
 * The same rule as Service\SlotService::levelThresholds().
 */
class PriorityOrderService
{
    public const SETTING_PRIORITY_ORDER = 'registration_priority_order';

    public function __construct(private SettingService $settingService)
    {
    }

    public function current(): PriorityOrder
    {
        return $this->stored() ?? PriorityOrder::defaults();
    }

    public function storedOrderIsUnusable(): bool
    {
        return $this->stored() === null;
    }

    public function save(PriorityOrder $order): void
    {
        $this->settingService->setInternal(self::SETTING_PRIORITY_ORDER, $order->toJson(), 'registration');
    }

    private function stored(): ?PriorityOrder
    {
        $raw = $this->settingService->get(self::SETTING_PRIORITY_ORDER, 'registration', null);
        if ($raw === null || $raw === '') {
            // Never written: the manifest's default, which IS the defaults.
            return PriorityOrder::defaults();
        }

        return PriorityOrder::fromJson((string) $raw);
    }
}
