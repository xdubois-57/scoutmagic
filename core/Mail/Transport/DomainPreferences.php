<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Config\SettingService;

/**
 * Which relay to try first for a given recipient domain, on the mailing
 * lane and nowhere else (roadmap IT-07, D13).
 *
 * **This is a preference, never a lane.** `MailLane` states that a lane
 * is derived from `MailPurpose` and from nothing else — never from the
 * recipient — and that rule is untouched here: a message bound for
 * `gmail.com` travels the mailing lane exactly as every other mailing
 * does. What a preference changes is the ORDER of the relays inside that
 * one lane, and only when the preferred relay is already among the
 * candidates the lane produced. A relay that is disabled, out of quota or
 * behind an open breaker stays out; a preference cannot conjure it back.
 *
 * **Why the mailing lane only** (D13): a magic link must never take a
 * different road because of who is receiving it. Its fifteen minutes of
 * validity make « delivered tomorrow, through the relay that domain
 * prefers » indistinguishable from not delivered, and a login path that
 * varies per recipient is a login path nobody can reason about.
 *
 * **Why this lives in `Transport` and not next to the evidence.** The
 * thing that decides what the preference OUGHT to be is the seed boxes'
 * tally, which needs a repository, an encryption service and a thirty-day
 * window. Putting that on the send path would have every mailing pay for
 * a statistical reading. So the transport owns the answer — one setting
 * read — and `Core\Mail\Feedback\Seed\DomainRouting` owns the question.
 *
 * **A recipient is matched by its provider, not only by its domain**
 * (issue #422): « famille.be » served by Google follows the decision
 * taken for gmail.com. Which provider serves a domain is read from
 * `MailboxProviderRepository`, a cache a scheduled task fills from the MX
 * records — never resolved here, where a hanging resolver would hold
 * every message of a mailing.
 */
final class DomainPreferences
{
    /**
     * Registered `editable: false`: a JSON map is not something to type
     * into a text field, and every writer here goes through this class.
     */
    public const SETTING_KEY = 'mail_seed_routing_overrides';

    /**
     * How many domains may carry a preference.
     *
     * A ceiling rather than a promise of one: the setting is read on
     * every mailing, and a map that grew without bound — one entry per
     * recipient domain a unit has ever written to — would turn a cheap
     * lookup into a payload. Past it, the oldest written entry makes way.
     */
    public const MAXIMUM = 50;

    public function __construct(
        private SettingService $settings,
        /**
         * Which provider really hosts a domain, as the MX records said
         * when the scheduled task last asked (issue #422). Read from the
         * cache and nothing else — this class sits on the send path, and
         * a DNS query here would make every mailing wait on a resolver.
         * Null is « the address's own domain is its provider », which is
         * what the site did before and what a test that leaves it out
         * still gets.
         */
        private ?MailboxProviderRepository $mailboxProviders = null
    ) {
    }

    /**
     * The whole map, domain => provider id, in insertion order.
     *
     * A stored value that cannot be read is « no preference », never a
     * crash: the send path is the last place that may throw over a
     * setting somebody edited by hand.
     *
     * @return array<string, int>
     */
    public function all(): array
    {
        $raw = (string) ($this->settings->get(self::SETTING_KEY) ?? '');
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $domain => $providerId) {
            // `json_decode` turns an all-digit key into an int, which is
            // not a hazard for a domain — but the cast costs nothing and
            // the same omission silently dropped a relay from IT-06's
            // DMARC reading, so it is spelled out rather than reasoned
            // about a second time.
            if (!is_int($providerId) || $providerId < 0) {
                continue;
            }

            $map[(string) $domain] = $providerId;
        }

        return $map;
    }

    /** Null when this domain has no preference, which is the normal case. */
    public function forDomain(string $domain): ?int
    {
        return $this->all()[$this->normalise($domain)] ?? null;
    }

    /**
     * Remember that this domain's mail should try that relay first.
     *
     * @return bool whether anything actually changed, so a caller can
     *              journal a change and stay quiet about a no-op
     */
    public function prefer(string $domain, int $providerId): bool
    {
        $domain = $this->normalise($domain);
        if (!self::isPlausibleDomain($domain)) {
            return false;
        }

        $map = $this->all();
        if (($map[$domain] ?? null) === $providerId) {
            return false;
        }

        // Re-inserted at the end, so a domain that was just decided is
        // the last to be dropped by the ceiling below.
        unset($map[$domain]);
        $map[$domain] = $providerId;

        while (count($map) > self::MAXIMUM) {
            array_shift($map);
        }

        $this->store($map);

        return true;
    }

    /** @return bool whether there was anything to forget */
    public function forget(string $domain): bool
    {
        $domain = $this->normalise($domain);
        $map = $this->all();
        if (!array_key_exists($domain, $map)) {
            return false;
        }

        unset($map[$domain]);
        $this->store($map);

        return true;
    }

    /**
     * The candidates of one lane, reordered so this recipient's preferred
     * relay is tried first.
     *
     * **Reorders, never filters.** The rest of the chain keeps its order
     * and its place: a preferred relay that then refuses the message
     * falls through to exactly the same fallbacks it would have had. A
     * preference that names a relay the lane did not offer — disabled,
     * out of quota, breaker open — changes nothing at all, which is the
     * only safe reading of « prefer »: the reason that relay is missing
     * is a reason no preference outranks.
     *
     * @param array<int, MailProvider> $candidates
     * @return array<int, MailProvider>
     */
    public function reorder(array $candidates, MailLane $lane, string $recipient): array
    {
        if ($lane !== MailLane::Bulk) {
            return $candidates;
        }

        $domain = self::domainOf($recipient);
        $this->note($domain);

        if (count($candidates) < 2 || $this->all() === []) {
            return $candidates;
        }

        // The domain's own decision first — somebody may have routed
        // « famille.be » by hand — then its provider's.
        $wanted = $this->forDomain($domain) ?? $this->forDomain($this->providerOf($domain));
        if ($wanted === null || $candidates[0]->id === $wanted) {
            return $candidates;
        }

        $preferred = [];
        $rest = [];
        foreach ($candidates as $provider) {
            if ($provider->id === $wanted) {
                $preferred[] = $provider;
            } else {
                $rest[] = $provider;
            }
        }

        return array_merge($preferred, $rest);
    }

    /**
     * The provider a recipient domain is counted under: the one its MX
     * records named when the scheduled task last read them, or the domain
     * itself (issue #422).
     *
     * **From the cache only, never a lookup**, and a cache that cannot be
     * read is « the domain itself » rather than an exception — this is
     * asked on the send path, where nothing about a preference may cost a
     * message.
     */
    public function providerOf(string $domain): string
    {
        $domain = $this->normalise($domain);

        try {
            return $this->mailboxProviders?->providerOf($domain) ?? $domain;
        } catch (\Throwable) {
            return $domain;
        }
    }

    /**
     * Tell the cache this domain is being written to, so the scheduled
     * task knows to read its MX records. A write the database refuses is
     * swallowed: noting a domain is worth far less than the message.
     */
    private function note(string $domain): void
    {
        try {
            $this->mailboxProviders?->note($domain, new \DateTimeImmutable());
        } catch (\Throwable) {
            // Nothing: the next message will note it.
        }
    }

    /**
     * The domain half of an address, lowercased — and the empty string
     * for anything that is not one.
     *
     * The empty string rather than a guess: a malformed address must
     * match no preference, and `''` is a key `prefer()` refuses to write,
     * so the two ends agree without either trusting the other.
     */
    public static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return '';
        }

        return strtolower(trim(substr($address, $at + 1)));
    }

    /**
     * Whether this is something that could be the right-hand side of an
     * address at all.
     *
     * **Checked at the single writer, not at the form.** The decision
     * arrives from a page, is journalled, is printed into the support
     * archive a third party reads, and is matched against every mailing's
     * recipient — four places that would each have to distrust it
     * separately. One guard here is the one that cannot be forgotten by a
     * fifth caller.
     *
     * Deliberately a shape check and not a resolution: a unit routing
     * mail for a domain that does not resolve today is not making a
     * mistake this class is entitled to correct, and a DNS lookup on a
     * setting write is a lookup that fails on the day the network does.
     */
    public static function isPlausibleDomain(string $domain): bool
    {
        return $domain !== ''
            && strlen($domain) <= 253
            && preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domain) === 1;
    }

    private function normalise(string $domain): string
    {
        return strtolower(trim($domain));
    }

    /** @param array<string, int> $map */
    private function store(array $map): void
    {
        $this->settings->setInternal(
            self::SETTING_KEY,
            $map === [] ? '' : (string) json_encode($map, JSON_THROW_ON_ERROR)
        );
    }
}
