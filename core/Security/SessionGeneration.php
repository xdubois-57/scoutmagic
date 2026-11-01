<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Config\SettingService;

/** The authentication epoch, independent of the statistics identity. */
class SessionGeneration
{
    public const SETTING = 'auth_session_generation';

    public function __construct(private SettingService $settings)
    {
    }

    public static function register(SettingService $settings): void
    {
        $settings->register(
            self::SETTING,
            '',
            'secret',
            'Génération des sessions',
            'Identifiant interne renouvelé à l’installation et après une restauration.',
            editable: false
        );
    }

    public function current(): string
    {
        self::register($this->settings);
        $value = $this->settings->get(self::SETTING);
        if ($value === '' || $value === null) {
            // A concurrent login adopts the winner rather than replacing it.
            $this->settings->claimIfEmpty(self::SETTING, bin2hex(random_bytes(32)));
            $value = $this->settings->get(self::SETTING);
        }
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \RuntimeException('The authentication generation is invalid.');
        }

        return $value;
    }

    public function rotate(): string
    {
        // A restore can have replaced the database beneath this service.
        $this->settings->clearCache();
        self::register($this->settings);
        $value = bin2hex(random_bytes(32));
        $this->settings->setInternal(self::SETTING, $value);

        return $value;
    }
}
