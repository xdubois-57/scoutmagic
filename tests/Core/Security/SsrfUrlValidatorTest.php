<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\SsrfUrlValidator;
use Core\Security\SsrfValidationException;
use PHPUnit\Framework\TestCase;

class SsrfUrlValidatorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unsafeIpLiterals(): array
    {
        return [
            'loopback v4' => ['https://127.0.0.1/x'],
            'loopback v6' => ['https://[::1]/x'],
            'rfc1918 10/8' => ['https://10.0.0.5/x'],
            'rfc1918 192.168' => ['https://192.168.1.1/x'],
            'rfc1918 172.16' => ['https://172.16.0.1/x'],
            'link-local' => ['https://169.254.169.254/latest/meta-data'],
            'ipv4-mapped loopback' => ['https://[::ffff:127.0.0.1]/x'],
            'multicast v4' => ['https://239.0.0.1/x'],
            'unique-local v6' => ['https://[fd00::1]/x'],
        ];
    }

    /**
     * @dataProvider unsafeIpLiterals
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeIpLiterals')]
    public function testRejectsNonPublicHosts(string $url): void
    {
        $this->assertFalse(SsrfUrlValidator::isPublicHttpsUrl($url));
        $this->assertFalse(SsrfUrlValidator::isPublicHttpsUrl($url, true));
    }

    public function testRejectsNonHttpsSchemes(): void
    {
        $this->assertFalse(SsrfUrlValidator::isPublicHttpsUrl('http://93.184.216.34/x'));
        $this->assertFalse(SsrfUrlValidator::isPublicHttpsUrl('ftp://93.184.216.34/x'));
    }

    public function testRejectsEmbeddedCredentials(): void
    {
        $this->assertFalse(SsrfUrlValidator::isPublicHttpsUrl('https://user:pass@93.184.216.34/x'));
    }

    public function testRejectsANonDefaultPortUnlessAllowed(): void
    {
        $this->assertFalse(SsrfUrlValidator::isPublicHttpsUrl('https://93.184.216.34:9000/x'));
        // S3-compatible providers vary — a custom port on a public host is
        // allowed when the caller opts in, but the host must still be public.
        $this->assertTrue(SsrfUrlValidator::isPublicHttpsUrl('https://93.184.216.34:9000/x', true));
        $this->assertFalse(SsrfUrlValidator::isPublicHttpsUrl('https://127.0.0.1:9000/x', true));
    }

    public function testAcceptsAPublicHttpsIpLiteral(): void
    {
        // A documentation/public address — no DNS lookup needed for a literal.
        $this->assertTrue(SsrfUrlValidator::isPublicHttpsUrl('https://93.184.216.34/x'));
    }

    public function testIsPublicIpMatchesTheRangeRules(): void
    {
        $this->assertTrue(SsrfUrlValidator::isPublicIp('93.184.216.34'));
        $this->assertTrue(SsrfUrlValidator::isPublicIp('2606:2800:220:1::1'));
        $this->assertFalse(SsrfUrlValidator::isPublicIp('127.0.0.1'));
        $this->assertFalse(SsrfUrlValidator::isPublicIp('169.254.169.254'));
        $this->assertFalse(SsrfUrlValidator::isPublicIp('::ffff:10.0.0.1'));
    }

    public function testAssertThrowsWithAReason(): void
    {
        $this->expectException(SsrfValidationException::class);
        SsrfUrlValidator::assertPublicHttpsUrl('http://127.0.0.1/x');
    }

    /**
     * **`dns_get_record()` speaks DNS and nothing else**, while the
     * clients that make the actual request go through `getaddrinfo()` and
     * therefore `/etc/hosts`. A name known only to the system resolver —
     * `localhost`, a container alias — used to answer « nothing resolves »
     * here while the request that followed connected to it, which is
     * precisely the address this guard exists to refuse. Under the stored
     * check, where an unresolvable host is deliberately not a refusal,
     * that gap is what let it through.
     */
    public function testAHostOnlyTheSystemResolverKnowsIsStillRefused(): void
    {
        // Nothing answers for this in DNS; every machine's /etc/hosts
        // answers 127.0.0.1.
        $this->assertTrue(SsrfUrlValidator::resolvesOutsideThePublicInternet('localhost'));
        $this->assertFalse(SsrfUrlValidator::isStoredHttpsTargetStillSafe('https://localhost/dav'));
        $this->assertFalse(SsrfUrlValidator::isPublicHttpsUrl('https://localhost/dav'));
    }

    /**
     * And a name nothing at all can answer for stays « unresolved » rather
     * than becoming a refusal, which is the whole point of the stored
     * check being softer than the save-time one: the request it runs
     * before cannot reach anything either.
     *
     * **That leniency is conditional, and the condition is stated here
     * rather than left to the machine.** « Nothing resolved » is only
     * evidence when the lookup was the one the request will make, i.e.
     * `getaddrinfo()` through ext-sockets. Without that extension the two
     * remaining lookups are narrower than cURL's, the silence means
     * nothing, and the stored check refuses instead of guessing.
     */
    public function testAHostNothingCanAnswerForIsNotCalledPrivate(): void
    {
        $this->assertFalse(
            SsrfUrlValidator::resolvesOutsideThePublicInternet('partage-inexistant.example.org')
        );
        $this->assertSame(
            extension_loaded('sockets'),
            SsrfUrlValidator::isStoredHttpsTargetStillSafe('https://partage-inexistant.example.org/dav'),
            'an unresolvable host is tolerated only where we resolve as the client does'
        );

        // The save-time check stays strict about it: an address nobody can
        // resolve is not one to write down.
        $this->assertFalse(SsrfUrlValidator::isPublicHttpsUrl('https://partage-inexistant.example.org/dav'));
    }

    /**
     * **The IPv6 half of the same gap, which was a live hole.**
     *
     * `gethostbyname()` closed the NSS gap for IPv4 only. A name carrying
     * nothing but an IPv6 address in `/etc/hosts` was invisible to it AND
     * to `dns_get_record()`, so the stored check saw no address at all,
     * called that « unresolved », and returned safe — after which cURL
     * resolved the same name through `getaddrinfo()` and sent the share's
     * password to it in an `Authorization: Basic` header. Reproduced with
     * `fd00::1` before the fix.
     *
     * Only `getaddrinfo()` sees every source the client will, so that is
     * what the validator asks now.
     */
    public function testANameCarryingOnlyAPrivateIpv6InNssIsRefused(): void
    {
        $name = self::aNameOnlyReachableAsPrivateIpv6();
        if ($name === null) {
            self::markTestSkipped(
                'no IPv6-only NSS name on this machine to exercise it with '
                . '(the fix is in addressesOf(); this asserts it end to end)'
            );
        }

        $this->assertTrue(SsrfUrlValidator::resolvesOutsideThePublicInternet($name));
        $this->assertFalse(SsrfUrlValidator::isStoredHttpsTargetStillSafe('https://' . $name . '/dav'));
    }

    /**
     * **A bracketed IPv6 literal is an address, not a name.**
     *
     * `parse_url()` keeps the brackets, `FILTER_VALIDATE_IP` refuses that
     * spelling, and no resolver can answer for `[fd00::1]` either — so the
     * host fell through every branch and arrived at « nothing resolves »,
     * which the stored check reads as permission. cURL needs no lookup for
     * a bracketed URL: it dials the address. A row carrying
     * `https://[::1]/dav` — a restore from another installation, a
     * hand-edited column, exactly what the request-time re-check exists
     * for — therefore sent the share's password to loopback.
     *
     * @param string $literal the host as it appears inside the brackets
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('privateIpv6Literals')]
    public function testABracketedPrivateIpv6LiteralIsRefusedByBothChecks(string $literal): void
    {
        $url = 'https://[' . $literal . ']/dav';

        $this->assertFalse(
            SsrfUrlValidator::isStoredHttpsTargetStillSafe($url),
            $url . ' must not pass the stored re-check'
        );
        $this->assertFalse(
            SsrfUrlValidator::isPublicHttpsUrl($url),
            $url . ' must not pass the save-time check'
        );
    }

    /** @return array<string, array{string}> */
    public static function privateIpv6Literals(): array
    {
        return [
            'unique local' => ['fd00::1'],
            'loopback' => ['::1'],
            'link-local' => ['fe80::1'],
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
        ];
    }

    /**
     * And the same blind spot refused every **public** IPv6 literal, which
     * is the half that cost availability rather than safety: a share
     * legitimately addressed by IPv6 could not be saved at all.
     */
    public function testABracketedPublicIpv6LiteralIsAccepted(): void
    {
        $url = 'https://[2606:4700:10::6814:1a88]/dav';

        $this->assertTrue(SsrfUrlValidator::isPublicHttpsUrl($url));
        $this->assertTrue(SsrfUrlValidator::isStoredHttpsTargetStillSafe($url));
    }

    /**
     * A host this machine resolves ONLY to a non-public IPv6 address, and
     * only through the system resolver — the exact shape the bug needed.
     * Null when this machine has none, which is the ordinary case.
     */
    private static function aNameOnlyReachableAsPrivateIpv6(): ?string
    {
        if (!function_exists('socket_addrinfo_lookup')) {
            return null;
        }

        foreach (['ip6-localhost', 'ip6-loopback'] as $candidate) {
            if (@dns_get_record($candidate, DNS_A) || @dns_get_record($candidate, DNS_AAAA)) {
                continue;
            }
            if (@gethostbyname($candidate) !== $candidate) {
                continue;
            }

            $found = @socket_addrinfo_lookup($candidate, '443', [
                'ai_family' => AF_INET6,
                'ai_socktype' => SOCK_STREAM,
            ]);
            foreach (is_array($found) ? $found : [] as $one) {
                $address = socket_addrinfo_explain($one)['ai_addr'] ?? null;
                $ip = is_array($address) ? ($address['sin6_addr'] ?? null) : null;
                if (is_string($ip) && $ip !== '' && !SsrfUrlValidator::isPublicIp($ip)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** The structural refusals are the same on both checks. */
    public function testTheStoredCheckRefusesEverythingStructuralThatTheStrictOneDoes(): void
    {
        foreach ([
            'http://example.org/dav',
            'https://user:pass@example.org/dav',
            'https://10.0.0.5/dav',
            'https://169.254.169.254/dav',
            '',
            'pas-une-adresse',
        ] as $url) {
            $this->assertFalse(
                SsrfUrlValidator::isStoredHttpsTargetStillSafe($url, true),
                $url . ' must be refused by the stored check too'
            );
        }
    }
}
