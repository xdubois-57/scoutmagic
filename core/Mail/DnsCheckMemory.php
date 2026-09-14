<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

use Core\Config\SettingService;
use Core\Service\DateInput;

/**
 * What the last live DNS lookup said, and when (roadmap IT-03).
 *
 * **A remembered reading, and every reader of it says so.** The
 * alternative was a `dns_get_record()` on every load of the outbound-mail
 * dashboard and on every support package — on a resolver that is not
 * answering, that takes as long as it takes, and the dashboard is
 * precisely the page somebody opens when mail is already broken. A
 * reading that states its own age beats a reading that pretends to be
 * current.
 *
 * It lives in `Core\Mail` rather than on the controller that writes it
 * because the support collector reads it too, and a collector reaching
 * for a controller constant to parse a setting is two layers of the wrong
 * shape for one JSON blob.
 */
final class DnsCheckMemory
{
    public const SETTING_KEY = 'mail_dns_last_check';

    private function __construct(
        public readonly \DateTimeImmutable $takenAt,
        public readonly string $domain,
        /** Null means « not part of that reading » — no DKIM key yet, or no report address asked for. */
        public readonly ?bool $spf,
        public readonly ?bool $dkim,
        public readonly ?bool $dmarc
    ) {
    }

    /** Null when there has never been a lookup, or the stored value is unreadable. */
    public static function read(SettingService $settings): ?self
    {
        $raw = (string) ($settings->get(self::SETTING_KEY) ?? '');
        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        // Through DateInput and not the bare constructor: a truncated
        // value would otherwise answer *now* and date a check that never
        // happened to this very second.
        $takenAt = DateInput::fromStorage(isset($decoded['at']) ? (string) $decoded['at'] : null);
        if ($takenAt === null) {
            return null;
        }

        $tri = static fn(string $key): ?bool => isset($decoded[$key]) && is_bool($decoded[$key])
            ? $decoded[$key]
            : null;

        return new self(
            $takenAt,
            (string) ($decoded['domain'] ?? ''),
            $tri('spf'),
            $tri('dkim'),
            $tri('dmarc')
        );
    }

    /**
     * Keep a reading. `setInternal()` because this is written by the page
     * and never by hand — the setting is registered `editable: false`.
     */
    public static function remember(
        SettingService $settings,
        string $domain,
        ?bool $spf,
        ?bool $dkim,
        ?bool $dmarc,
        ?\DateTimeImmutable $now = null
    ): void {
        $encoded = json_encode([
            'at' => ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'domain' => $domain,
            'spf' => $spf,
            'dkim' => $dkim,
            'dmarc' => $dmarc,
        ]);

        self::store($settings, $encoded === false ? '' : $encoded);
    }

    /**
     * Forget the reading — what a lookup nobody could take leaves behind,
     * rather than a false negative nobody can tell from a real one.
     */
    public static function forget(SettingService $settings): void
    {
        self::store($settings, '');
    }

    private static function store(SettingService $settings, string $value): void
    {
        try {
            $settings->setInternal(self::SETTING_KEY, $value);
        } catch (\Throwable) {
            // A dashboard line reading « jamais vérifié » is a far smaller
            // problem than a DNS check that ends on an error page.
        }
    }

    /** « publié », « absent » or « non vérifié » — what a screen prints. */
    public static function label(?bool $state): string
    {
        return match ($state) {
            true => 'publié',
            false => 'absent',
            null => 'non vérifié',
        };
    }
}
