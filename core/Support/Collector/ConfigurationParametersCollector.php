<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Config\SettingRepository;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use Core\Export\TabularSpreadsheet;

/**
 * `configuration-parameters.xlsx` — one row per `settings` entry, with the
 * current value next to the declared default and an explicit "differs from
 * default" flag, which is usually the fastest way to spot what a unit has
 * changed.
 *
 * **Redaction** works off `setting_type = 'secret'`, never a hardcoded list
 * of suspicious-looking key names. A keyword list is guaranteed to miss the
 * next secret somebody adds and to redact something harmless in the
 * meantime; declaring the type at the point of registration cannot. The key
 * and the label stay visible — knowing that a secret is *set* is itself
 * diagnostic — and only the value becomes `[REDACTED]`.
 *
 * No setting carries that type today: every real credential lives outside
 * `settings` (`secrets.enc` for database/SMTP/webhook/statistics, encrypted
 * BLOB columns for LLM and telephony credentials), and none of those tables
 * are read here at all. This is a net that exists before it is needed.
 */
class ConfigurationParametersCollector implements SupportCollectorInterface
{
    public const SECRET_SETTING_TYPE = 'secret';
    public const REDACTED = '[REDACTED]';

    public function name(): string
    {
        return 'configuration_parameters';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $settings = (new SettingRepository($context->pdo()))->findAll();

        $rows = [];
        foreach ($settings as $setting) {
            $type = (string) ($setting['setting_type'] ?? 'text');
            $isSecret = $type === self::SECRET_SETTING_TYPE;

            $currentValue = self::asString($setting['setting_value'] ?? null);
            $defaultValue = self::asString($setting['default_value'] ?? null);

            $rows[] = [
                (string) ($setting['setting_key'] ?? ''),
                self::asString($setting['module_id'] ?? null, 'core'),
                (string) ($setting['label'] ?? ''),
                $type,
                $isSecret ? self::REDACTED : $currentValue,
                $isSecret ? self::REDACTED : $defaultValue,
                $currentValue === $defaultValue ? 'non' : 'oui',
                ((int) ($setting['editable'] ?? 0)) === 1 ? 'oui' : 'non',
            ];
        }

        $context->addFileFromContent(
            'configuration-parameters.xlsx',
            TabularSpreadsheet::build(
                ['Clé', 'Module', 'Libellé', 'Type', 'Valeur courante', 'Valeur par défaut', 'Diffère du défaut', 'Éditable'],
                $rows,
                'Réglages'
            )
        );
    }

    private static function asString(mixed $value, string $fallback = ''): string
    {
        if ($value === null) {
            return $fallback;
        }

        return is_scalar($value) ? (string) $value : $fallback;
    }
}
