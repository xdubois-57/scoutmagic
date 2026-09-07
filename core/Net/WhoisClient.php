<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Net;

/**
 * Who registered a domain, asked of the registry that knows.
 *
 * **Why this exists at all.** A receiver looking at a fleet of
 * installations can see what each one reports about itself and nothing
 * about the name it answers on. « Ce domaine expire dans trois semaines »
 * and « ce domaine appartient à un registrar qui ne répond plus » are
 * support questions that arrive as « le site ne marche plus », and neither
 * is answerable from telemetry — the installation does not know.
 *
 * **Three hops, at most, and a clock over all of them.** WHOIS has no
 * directory: `whois.iana.org` says which registry serves a TLD, the
 * registry answers about the domain, and for the thin registries (`.com`
 * and its relatives) the answer is a pointer to the registrar that holds
 * the real record. Each hop is a socket to a stranger on port 43, which is
 * exactly the shape of thing that hangs, so the budget is checked before
 * every one of them and the last good answer is what comes back.
 *
 * **Every failure is null.** The two callers are intake endpoints that have
 * already accepted what they were sent; a registry that is down, a TLD with
 * no WHOIS service, a shared host with port 43 firewalled — all ordinary,
 * none of them a reason to refuse a report. The caller journals the miss.
 *
 * **What is sent is a validated host and nothing else.** A WHOIS query is a
 * line terminated by CRLF: a "domain" carrying a newline would be a second
 * command on somebody else's server, and the names here come out of a
 * remote installation's JSON. {@see DomainName::isQueryable()} is what makes
 * that impossible, which is why nothing below escapes anything.
 */
class WhoisClient
{
    /** Where every lookup starts: the registry of registries. */
    public const ROOT_SERVER = 'whois.iana.org';

    public const PORT = 43;

    /**
     * How long the whole lookup may take, across every hop.
     *
     * Ten seconds against the twenty a sender allows the request
     * (`Core\Statistics\StreamStatisticsTransport`). A responsive chain
     * costs well under a second; this is for the chain that is not.
     */
    public const BUDGET_SECONDS = 10.0;

    /** Per-socket, so one dead server cannot spend the whole budget. */
    public const CONNECT_TIMEOUT_SECONDS = 3;
    public const READ_TIMEOUT_SECONDS = 4;

    /**
     * How much of one answer is kept. A registry response is two or three
     * kilobytes of record and legal notice; anything past this is a server
     * that has decided to tell us about its entire zone.
     */
    public const MAX_RESPONSE_BYTES = 32768;

    /**
     * The answers that mean « ce nom n'est enregistré nulle part ».
     *
     * Matched case-insensitively against the whole response. Deliberately a
     * short list of what registries actually print: a longer one starts
     * matching the legal notice at the bottom of a perfectly good record,
     * and reading a real registration as « libre » is worse than missing
     * one, because it is confidently wrong.
     */
    private const NOT_FOUND_MARKERS = [
        'no match for',
        'not found',
        'no entries found',
        'no data found',
        'domain not found',
        'status: available',
        'status: free',
        'no object found',
    ];

    /**
     * Servers that answer about EVERY name containing the string unless the
     * query says otherwise. Verisign's is the one everybody meets: asking
     * it for `unite.be` plainly returns a list of matches rather than a
     * record, and the `domain ` keyword is what makes it an exact lookup.
     *
     * @var string[]
     */
    private const EXACT_MATCH_KEYWORD_SERVERS = [
        'whois.verisign-grs.com',
        'whois.crsnic.net',
    ];

    public function __construct(
        /**
         * Overridable so a test can prove the budget without spending it,
         * and so an operator on a slow link can widen it without editing
         * a constant.
         */
        private float $budgetSeconds = self::BUDGET_SECONDS
    ) {
    }

    /**
     * The registration of the first candidate a registry recognises.
     *
     * **Three outcomes, and the walk is what tells them apart.** A registry
     * that answered « no match » about every candidate means the name is
     * registered nowhere, which is a real answer. A registry that never
     * answered at all means this host cannot ask, which is a fact about
     * the receiver's network. Collapsing the two into a null was the first
     * version of this and it made the dashboard unable to say which.
     *
     * @param string $host a host as {@see DomainName::hostOf()} returns it
     */
    public function lookup(string $host): WhoisLookup
    {
        $deadline = microtime(true) + $this->budgetSeconds;
        $anybodyAnswered = false;

        foreach (DomainName::whoisCandidates($host) as $candidate) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $response = $this->lookupExact($candidate, $deadline, $anybodyAnswered);
            if ($response !== null) {
                return WhoisLookup::found($response);
            }
        }

        return $anybodyAnswered ? WhoisLookup::notFound() : WhoisLookup::unavailable();
    }

    /**
     * @param bool $anybodyAnswered set when a registry said anything at
     *   all, which is what separates « ce nom est libre » from « je n'ai
     *   pas pu demander »
     */
    private function lookupExact(string $domain, float $deadline, bool &$anybodyAnswered): ?WhoisResponse
    {
        $tld = DomainName::tldOf($domain);
        if ($tld === null) {
            return null;
        }

        $server = $this->registryFor($tld, $deadline);
        if ($server === null) {
            return null;
        }

        $raw = $this->askWithin($server, $this->queryFor($server, $domain), $deadline);
        if ($raw === null) {
            return null;
        }

        $anybodyAnswered = true;

        if (self::readsAsNotFound($raw)) {
            return null;
        }

        // The thin-registry hop. `.com` keeps the registrar's name and the
        // dates and nothing else; the record a maintainer needs — the
        // contacts, the exact status — is at the registrar. One hop, never
        // a chain: a referral loop is somebody else's problem to have.
        $referral = self::referralIn($raw);
        if ($referral !== null && $referral !== $server) {
            $deeper = $this->askWithin($referral, $this->queryFor($referral, $domain), $deadline);
            if ($deeper !== null && !self::readsAsNotFound($deeper)) {
                return new WhoisResponse($domain, $referral, $deeper);
            }
        }

        return new WhoisResponse($domain, $server, $raw);
    }

    /**
     * Which server answers for a TLD, asked of IANA once per TLD per
     * process — a fleet of installations shares a handful of TLDs, and
     * three hundred identical questions to the root is how an address gets
     * rate-limited out of the answer.
     *
     * @var array<string, string|null>
     */
    private array $registryCache = [];

    private function registryFor(string $tld, float $deadline): ?string
    {
        if (array_key_exists($tld, $this->registryCache)) {
            return $this->registryCache[$tld];
        }

        $raw = $this->askWithin(self::ROOT_SERVER, $tld, $deadline);
        $server = $raw === null ? null : self::whoisServerIn($raw);

        return $this->registryCache[$tld] = $server;
    }

    private function askWithin(string $server, string $query, float $deadline): ?string
    {
        if (microtime(true) >= $deadline || !DomainName::isQueryable($server)) {
            return null;
        }

        return $this->ask($server, $query, $deadline);
    }

    /** Verisign and its relatives need the keyword; everybody else does not. */
    private function queryFor(string $server, string $domain): string
    {
        return in_array(strtolower($server), self::EXACT_MATCH_KEYWORD_SERVERS, true)
            ? 'domain ' . $domain
            : $domain;
    }

    /**
     * The one socket, alone in its own method so a test replaces it without
     * a network and without stubbing the walk above it.
     *
     * The deadline is carried in rather than checked by the caller,
     * because `stream_set_timeout()` bounds each blocking `fread()` and
     * not the loop around them: a server that dribbles one byte just
     * before every timeout keeps every read "successful" and holds the
     * request until `MAX_RESPONSE_BYTES` or the end of the world,
     * whichever comes first. Checked between reads, the whole exchange
     * costs at most the budget plus one read timeout.
     *
     * @return string|null null on any transport failure, which is not an
     *   error here: port 43 is outbound TCP, and plenty of shared hosts
     *   simply do not have it.
     */
    protected function ask(string $server, string $query, float $deadline): ?string
    {
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            'tcp://' . $server . ':' . self::PORT,
            $errorNumber,
            $errorMessage,
            self::CONNECT_TIMEOUT_SECONDS
        );

        if (!is_resource($socket)) {
            return null;
        }

        try {
            stream_set_timeout($socket, self::READ_TIMEOUT_SECONDS);

            if (@fwrite($socket, $query . "\r\n") === false) {
                return null;
            }

            $raw = $this->readWithin($socket, $deadline);

            return trim($raw) === '' ? null : $raw;
        } finally {
            @fclose($socket);
        }
    }

    /**
     * Read until the response ends, the byte cap is reached, or the
     * deadline passes — whichever comes first.
     *
     * Its own method because the deadline is the whole point of it and a
     * test cannot reach it through `ask()`, which opens a socket on port
     * 43. Given a socket pair, this one is testable directly, which is
     * what the check below is worth: without it, `stream_set_timeout()`
     * bounds each `fread()` and nothing bounds the loop.
     *
     * @param resource $socket
     */
    protected function readWithin($socket, float $deadline): string
    {
        $raw = '';

        while (strlen($raw) < self::MAX_RESPONSE_BYTES && !feof($socket)) {
            if (microtime(true) >= $deadline) {
                // Whatever arrived is kept: a partial record still names
                // the registrar more often than not, and this reading is
                // never the evidence — the raw response beside it is.
                break;
            }

            $chunk = @fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                // Distinguishes end-of-stream from the read timeout,
                // which returns '' with `timed_out` set: keeping what
                // arrived is better than discarding a partial record.
                break;
            }

            $raw .= $chunk;
        }

        return substr($raw, 0, self::MAX_RESPONSE_BYTES);
    }

    /** The `whois:` line of an IANA TLD record. */
    public static function whoisServerIn(string $raw): ?string
    {
        if (preg_match('/^\s*whois:\s*([A-Za-z0-9.-]+)\s*$/mi', $raw, $matches) !== 1) {
            return null;
        }

        $server = strtolower(trim($matches[1]));

        return DomainName::isQueryable($server) ? $server : null;
    }

    /** The registrar's own server, when a thin registry names one. */
    public static function referralIn(string $raw): ?string
    {
        $patterns = [
            '/^\s*Registrar WHOIS Server:\s*([A-Za-z0-9.-]+)\s*$/mi',
            '/^\s*whois:\s*([A-Za-z0-9.-]+)\s*$/mi',
            '/^\s*ReferralServer:\s*whois:\/\/([A-Za-z0-9.-]+)\s*$/mi',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $raw, $matches) === 1) {
                $server = strtolower(trim($matches[1]));
                if (DomainName::isQueryable($server)) {
                    return $server;
                }
            }
        }

        return null;
    }

    /**
     * Whether a response says the name is registered nowhere.
     *
     * Whitespace is flattened before matching, because registries line
     * their records up with tabs: DNS Belgium prints `Status:\tAVAILABLE`,
     * and a marker written with a single space finds nothing in it — which
     * is how « ce nom est libre » first read as « le registre n'a pas
     * répondu ».
     */
    public static function readsAsNotFound(string $raw): bool
    {
        $haystack = strtolower((string) preg_replace('/[ \t]+/', ' ', $raw));

        foreach (self::NOT_FOUND_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
