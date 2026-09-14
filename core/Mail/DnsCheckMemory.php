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
 * What the last live DNS lookup found, and when (roadmap IT-03).
 *
 * **The page renders this and never a lookup of its own.** Two things
 * follow from that, and both were the reason to write it down rather than
 * hold the readings in a request.
 *
 * The first is that a `dns_get_record()` on a resolver that is not
 * answering takes as long as it takes — and the outbound-mail screens are
 * precisely the ones somebody opens when mail is already broken. The
 * lookup is therefore an explicit action, and every screen reads the
 * remembered answer WITH the date it was taken: a reading that states its
 * own age beats a reading that pretends to be current.
 *
 * The second is that the records are what the operator is copying into
 * their registrar's form. Holding them in the response would mean losing
 * them on the next page load, halfway through the copy — so what is kept
 * is the whole reading, suggested values included, and not merely three
 * booleans.
 *
 * It lives in `Core\Mail` rather than on the controller that writes it
 * because the support collector reads it too, and a collector reaching for
 * a controller constant to parse a setting is two layers of the wrong
 * shape for one JSON blob.
 */
final class DnsCheckMemory
{
    public const SETTING_KEY = 'mail_dns_last_check';

    public const SPF = 'spf';
    public const DKIM = 'dkim';
    public const DMARC = 'dmarc';

    /** The three, in the order a screen reads them. */
    public const RECORDS = [self::SPF, self::DKIM, self::DMARC];

    /**
     * @param array<string, array{exists: bool, expected: ?string, actual: ?string,
     *     key_missing: bool, not_requested: bool}> $records
     */
    private function __construct(
        public readonly \DateTimeImmutable $takenAt,
        public readonly string $spfDomain,
        public readonly string $dkimDomain,
        public readonly string $selector,
        public readonly array $records
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

        $stored = is_array($decoded['records'] ?? null) ? $decoded['records'] : [];
        $records = [];
        foreach (self::RECORDS as $key) {
            $records[$key] = self::normalise(is_array($stored[$key] ?? null) ? $stored[$key] : []);
        }

        return new self(
            $takenAt,
            (string) ($decoded['spf_domain'] ?? ''),
            (string) ($decoded['dkim_domain'] ?? ''),
            (string) ($decoded['selector'] ?? ''),
            $records
        );
    }

    /**
     * Keep a whole reading. `setInternal()` because this is written by the
     * page and never by hand — the setting is registered `editable: false`.
     *
     * @param array<string, array<string, mixed>> $records keyed by {@see self::RECORDS}
     */
    public static function remember(
        SettingService $settings,
        string $spfDomain,
        string $dkimDomain,
        string $selector,
        array $records,
        ?\DateTimeImmutable $now = null
    ): void {
        $normalised = [];
        foreach (self::RECORDS as $key) {
            $normalised[$key] = self::normalise(is_array($records[$key] ?? null) ? $records[$key] : []);
        }

        $encoded = json_encode([
            'at' => ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'spf_domain' => $spfDomain,
            'dkim_domain' => $dkimDomain,
            'selector' => $selector,
            'records' => $normalised,
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

    /**
     * Whether this reading still describes the identity configured now.
     *
     * **A reading is about a domain and a selector, not about « the
     * site ».** Change the expédition address to another domain, or the
     * DKIM selector, and every verdict in here answers a question nobody
     * is asking any more: the green tick would then sit over a zone that
     * has never been looked at, and the records offered for copying would
     * be the previous domain's. The three values the reading already
     * carries are exactly what is needed to notice, so the check is here
     * rather than in one caller — the dashboard, the Authentification
     * sub-page and the support package all read this memory, and a
     * staleness rule living in one of them is a staleness rule the other
     * two do not have.
     *
     * Deliberately NOT a `forget()` at save time. A reading dropped on
     * save is a reading lost for good, including the records somebody was
     * halfway through copying; and the address can move without passing
     * through that form at all — the setup wizard writes it too. Holding
     * the answer and ignoring it while it does not apply keeps both
     * honest.
     */
    public function describes(MailIdentity $identity, string $selector): bool
    {
        return $this->spfDomain === $identity->spfDomain()
            && $this->dkimDomain === $identity->dkimDomain()
            && $this->selector === $selector;
    }

    /**
     * Whether one record was published — `null` when the reading could not
     * answer for it at all.
     *
     * The three-state answer matters: « aucune clé DKIM à publier encore »
     * and « aucun rapport demandé » are not « l'enregistrement manque »,
     * and reporting them as a failure would send somebody to fix a zone
     * that is exactly as it should be.
     */
    public function state(string $key): ?bool
    {
        $record = $this->records[$key] ?? null;
        if ($record === null || $record['key_missing'] || $record['not_requested']) {
            return null;
        }

        return $record['exists'];
    }

    /**
     * @return array{exists: bool, expected: ?string, actual: ?string, key_missing: bool, not_requested: bool}
     */
    public function record(string $key): array
    {
        return $this->records[$key] ?? self::normalise([]);
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

    /**
     * @param array<string, mixed> $record
     * @return array{exists: bool, expected: ?string, actual: ?string, key_missing: bool, not_requested: bool}
     */
    private static function normalise(array $record): array
    {
        $text = static function (mixed $value): ?string {
            return is_string($value) && $value !== '' ? $value : null;
        };

        return [
            'exists' => ($record['exists'] ?? false) === true,
            'expected' => $text($record['expected'] ?? null),
            'actual' => $text($record['actual'] ?? null),
            'key_missing' => ($record['key_missing'] ?? false) === true,
            'not_requested' => ($record['not_requested'] ?? false) === true,
        ];
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
}
