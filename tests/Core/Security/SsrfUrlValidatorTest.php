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
}
