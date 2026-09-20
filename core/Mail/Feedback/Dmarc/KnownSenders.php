<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Dmarc;

use Core\Config\SettingService;
use Core\Mail\Transport\MailProvider;
use Core\Service\DateInput;

/**
 * Which of the addresses in a report are this unit's own relays
 * (roadmap IT-06).
 *
 * **Without this the screen is a list of IP addresses, and a volunteer
 * closes it.** `203.0.113.77` answers no question anybody has. With it,
 * two questions are answered at a glance: is my own relay authenticating,
 * and who else is sending in my name.
 *
 * **The page renders a remembered reading and never a lookup of its own**,
 * which is the rule {@see \Core\Mail\DnsCheckMemory} already wrote down for
 * these very screens, and which the first version of this class broke. The
 * reasoning is that file's, unchanged: a `dns_get_record()` against a
 * resolver that is not answering takes as long as it takes, and the
 * outbound-mail screens are precisely the ones somebody opens when mail is
 * already broken. So the resolution is an explicit action — the same
 * « Vérifier le DNS » button that takes the SPF and DKIM readings, which is
 * where this site is allowed to block on a resolver — and the reading is
 * stored with the date it was taken.
 *
 * **A source we cannot place goes in « autres », which is the safe side.**
 * Wrong in that direction, a volunteer investigates their own relay and
 * finds nothing; wrong in the other, an unknown sender is labelled « votre
 * relais » and nobody ever looks at it again. The first wastes ten
 * minutes, the second is the whole point of the screen. A reading that was
 * never taken therefore places nothing, rather than guessing.
 *
 * **The host itself never reaches a screen.** What is shown is the
 * provider's name — « Brevo » — because a relay hostname is infrastructure
 * and SECURITY.md §11 keeps that out of anything a screenshot can carry.
 */
class KnownSenders
{
    public const SETTING_KEY = 'mail_dmarc_relay_addresses';

    /**
     * @param array<string, string> $addresses packed address => provider name
     */
    private function __construct(
        public readonly ?\DateTimeImmutable $takenAt,
        private array $addresses
    ) {
    }

    /**
     * The reading the last DNS check left behind, or an empty one.
     *
     * Never null: « nothing has been resolved » is an answer this class
     * gives correctly — everything is « autres » — and a null would push
     * that branch onto every caller.
     */
    public static function remembered(SettingService $settings): self
    {
        $raw = (string) ($settings->get(self::SETTING_KEY) ?? '');
        if ($raw === '') {
            return new self(null, []);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self(null, []);
        }

        if (!is_array($decoded)) {
            return new self(null, []);
        }

        $takenAt = DateInput::fromStorage(isset($decoded['at']) ? (string) $decoded['at'] : null);
        if ($takenAt === null) {
            return new self(null, []);
        }

        $addresses = [];
        foreach (is_array($decoded['addresses'] ?? null) ? $decoded['addresses'] : [] as $key => $name) {
            // **`(string) $key`, and it is not defensive typing.** These
            // keys are hex, so one whose digits happen to carry no letter
            // — `33440101`, which is 51.68.1.1 — comes back from
            // `json_decode` as an INT, PHP having decided a numeric string
            // key is a number. Read as a string it is dropped, and the
            // relay behind it quietly becomes « autre ». A test that
            // resolved two relays at once is what found it; one relay, or
            // two whose addresses both contained a letter, passes happily.
            $key = (string) $key;
            if (is_string($name) && $key !== '' && $name !== '') {
                $addresses[$key] = $name;
            }
        }

        return new self($takenAt, $addresses);
    }

    /**
     * Resolve the relays and keep the answer. **Blocks on DNS**, so it is
     * called from an explicit action and never from a page render.
     *
     * @param list<MailProvider> $relays they already carry both the host
     *                                   and the name, so nothing here
     *                                   reads a secret of its own
     * @param ?\Closure          $resolver injected so a test can answer
     *                                     without a resolver
     */
    public static function refresh(
        SettingService $settings,
        array $relays,
        ?\Closure $resolver = null,
        ?\DateTimeImmutable $now = null
    ): self {
        $addresses = [];

        foreach ($relays as $relay) {
            $host = trim($relay->host);
            // A relay with no host configured — the local send among them —
            // vouches for nothing, which is « autres » and correct.
            if ($host === '') {
                continue;
            }

            foreach (self::resolve($host, $resolver) as $address) {
                $packed = self::pack($address);
                if ($packed === null) {
                    continue;
                }

                // First provider wins a shared address rather than the
                // last: two providers behind one relay is a configuration
                // nobody can act on from this screen either way, and
                // stability beats whichever happened to be iterated last.
                $addresses[$packed] ??= $relay->name;
            }
        }

        $takenAt = $now ?? new \DateTimeImmutable();
        $encoded = json_encode([
            'at' => $takenAt->format('Y-m-d H:i:s'),
            'addresses' => $addresses,
        ]);

        // `setInternal()` because this is written by an action and never by
        // hand — the same posture `DnsCheckMemory` takes towards its blob.
        $settings->setInternal(self::SETTING_KEY, $encoded === false ? '' : $encoded);

        return new self($takenAt, $addresses);
    }

    /**
     * The provider this address belongs to, or null when it is somebody
     * else's — which includes every address the last reading did not place.
     */
    public function nameFor(string $sourceIp): ?string
    {
        $packed = self::pack($sourceIp);

        return $packed === null ? null : ($this->addresses[$packed] ?? null);
    }

    public function isOwn(string $sourceIp): bool
    {
        return $this->nameFor($sourceIp) !== null;
    }

    /** Whether a reading has ever been taken. */
    public function isEmpty(): bool
    {
        return $this->takenAt === null;
    }

    /**
     * The address as bytes, hex-encoded — a key, never a display value.
     *
     * **Two spellings of one IPv6 address are one address**, and comparing
     * the text would not say so: `2001:db8::1` and `2001:0db8:0000:…:0001`
     * are the same machine, and a reporter has no reason to write it the
     * way a resolver does. Compared as text, a unit's own IPv6 relay lands
     * in « autres » for ever — the exact mislabelling this class exists to
     * prevent, arriving through the door it was not watching. `inet_pton`
     * collapses every spelling onto the bytes; `bin2hex` makes those bytes
     * an array key that survives JSON.
     *
     * Null for anything that is not an address, which is « autres » and
     * correct: the parser already drops those, and a second refusal here
     * costs nothing.
     */
    private static function pack(string $address): ?string
    {
        $packed = @inet_pton(trim($address));

        return $packed === false ? null : bin2hex($packed);
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host, ?\Closure $resolver): array
    {
        if ($resolver !== null) {
            return ($resolver)($host);
        }

        // **Both families, and `dns_get_record` rather than
        // `gethostbyname`.** The latter is IPv4 only, and a relay reached
        // over IPv6 would be filed under « autres » for ever — the same
        // gap `Core\Security\SsrfUrlValidator` documents having been bitten
        // by.
        $records = array_merge(
            @dns_get_record($host, DNS_A) ?: [],
            @dns_get_record($host, DNS_AAAA) ?: []
        );

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
