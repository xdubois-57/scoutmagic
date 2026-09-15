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
     */
    public function testAHostNothingCanAnswerForIsNotCalledPrivate(): void
    {
        $this->assertFalse(
            SsrfUrlValidator::resolvesOutsideThePublicInternet('partage-inexistant.example.org')
        );
        $this->assertTrue(
            SsrfUrlValidator::isStoredHttpsTargetStillSafe('https://partage-inexistant.example.org/dav')
        );

        // The save-time check stays strict about it: an address nobody can
        // resolve is not one to write down.
        $this->assertFalse(SsrfUrlValidator::isPublicHttpsUrl('https://partage-inexistant.example.org/dav'));
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
