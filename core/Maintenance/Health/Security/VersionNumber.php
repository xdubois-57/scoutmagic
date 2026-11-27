<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * A version string as a host reports it, split into what can be compared
 * and what cannot (issue #1358).
 *
 * « 10.11.6-MariaDB-0+deb12u1 » is three things: the upstream release
 * (10.11.6), a word the server adds about itself (MariaDB), and a
 * distribution's package revision (0+deb12u1). Only the first is compared
 * with php.net or endoflife.date; the last is kept, because its presence
 * is exactly what says « a fix may have been backported » — see
 * {@see ComponentFamily::$backportsVisible}.
 *
 * Three shapes are handled rather than guessed at:
 *
 * - a **pre-release** (« 8.5.0RC1 », « 3.0.0-alpha1 », « 8.4.0-dev »), which
 *   no release data describes, so the check says « non vérifiable » instead
 *   of comparing it as if it were 8.5.0;
 * - OpenSSL's **patch letters** (« 1.1.1w », « 1.0.2zh »), which sort after
 *   « 1.1.1t » and « 1.0.2z »;
 * - MariaDB's **replication prefix** (« 5.5.5-10.11.6-MariaDB »), which old
 *   clients see in front of the real version.
 */
final class VersionNumber
{
    private const PRERELEASE = '/^[-.~_]?(alpha|beta|rc|dev|pre)[-.]?\d*/i';

    /**
     * @param list<int> $segments
     */
    private function __construct(
        public readonly string $raw,
        public readonly array $segments,
        public readonly string $letter,
        public readonly ?string $prerelease,
        public readonly ?string $buildSuffix,
    ) {
    }

    /** Null when the string does not even start with a number. */
    public static function parse(string $raw, ?ComponentFamily $family = null): ?self
    {
        $text = trim($raw);
        if ($family !== null && $family->id === ComponentFamily::MARIADB) {
            $text = (string) preg_replace('/^5\.5\.5-(?=\d)/', '', $text);
        }

        // OpenSSL's patch letters run past « z » (« 1.0.2zh », the fix of
        // CVE-2023-2650): two letters standing alone are part of ITS upstream
        // version, never of another family's — where « RC1 » or « a1 » is a
        // pre-release, and a letter followed by a digit never a patch.
        $letters = $family !== null && $family->id === ComponentFamily::OPENSSL
            ? '[a-z]{1,2}(?![a-z0-9])'
            : '[a-z](?![a-z])';
        if (preg_match('/^v?(\d+(?:\.\d+)*)(' . $letters . ')?(.*)$/is', $text, $matches) !== 1) {
            return null;
        }

        $rest = $matches[3];
        foreach ($family !== null ? $family->ignoredTokens : [] as $token) {
            $rest = (string) preg_replace('/[-_.]' . preg_quote($token, '/') . '(?=$|[-+~._])/i', '', $rest);
        }

        $prerelease = null;
        if (preg_match(self::PRERELEASE, $rest, $pre) === 1) {
            $prerelease = ltrim($pre[0], '-.~_');
            $rest = substr($rest, strlen($pre[0]));
        }

        $rest = trim($rest);

        return new self(
            $raw,
            array_map('intval', explode('.', $matches[1])),
            strtolower($matches[2]),
            $prerelease,
            $rest !== '' ? $rest : null
        );
    }

    /** The upstream release alone: « 8.4.1 », « 1.1.1w ». */
    public function upstream(): string
    {
        return implode('.', $this->segments) . $this->letter;
    }

    public function isPrerelease(): bool
    {
        return $this->prerelease !== null;
    }

    /**
     * Negative, zero or positive as this upstream release is older than,
     * equal to or newer than $other's. Missing segments count as zero, so
     * « 8.4 » equals « 8.4.0 »; the patch letter breaks a tie (« » < « a »).
     */
    public function compare(self $other): int
    {
        $length = max(count($this->segments), count($other->segments));
        for ($i = 0; $i < $length; $i++) {
            $difference = ($this->segments[$i] ?? 0) <=> ($other->segments[$i] ?? 0);
            if ($difference !== 0) {
                return $difference;
            }
        }

        return strcmp($this->letter, $other->letter) <=> 0;
    }

    /**
     * Whether this version belongs to the branch named $branch (« 8.4 »,
     * « 10.11 », « 1.1.1 »): every segment of the branch name is a leading
     * segment of this version.
     */
    public function isInBranch(string $branch): bool
    {
        $name = self::parse($branch);
        if ($name === null || count($name->segments) > count($this->segments)) {
            return false;
        }

        return array_slice($this->segments, 0, count($name->segments)) === $name->segments;
    }
}
