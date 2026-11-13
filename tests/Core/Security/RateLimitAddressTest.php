<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\RateLimitAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1128: a per-address counter counts the client, and an IPv6
 * client owns a whole /64.
 */
final class RateLimitAddressTest extends TestCase
{
    public function testAnIpv4AddressStaysWhole(): void
    {
        $this->assertSame('192.0.2.17', RateLimitAddress::key('192.0.2.17'));
        $this->assertNotSame(RateLimitAddress::key('192.0.2.17'), RateLimitAddress::key('192.0.2.18'));
    }

    public function testTwoAddressesOfOneIpv6SubscriberShareAKey(): void
    {
        $a = RateLimitAddress::key('2001:db8:aaaa:1::1');
        $b = RateLimitAddress::key('2001:db8:aaaa:1:dead:beef:cafe:42');

        $this->assertSame('2001:db8:aaaa:1::/64', $a);
        $this->assertSame($a, $b);
    }

    public function testTwoIpv6SubscribersKeepTheirOwnKey(): void
    {
        $this->assertNotSame(
            RateLimitAddress::key('2001:db8:aaaa:1::1'),
            RateLimitAddress::key('2001:db8:aaaa:2::1')
        );
    }

    public function testEverySpellingOfOneIpv6PrefixIsOneKey(): void
    {
        $this->assertSame(
            RateLimitAddress::key('2001:0DB8:AAAA:0001:0000:0000:0000:0001'),
            RateLimitAddress::key('2001:db8:aaaa:1::ffff')
        );
    }

    public function testAnIpv4MappedAddressCountsWithItsIpv4Address(): void
    {
        $this->assertSame('192.0.2.17', RateLimitAddress::key('::ffff:192.0.2.17'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notAnAddress(): array
    {
        return [
            'empty' => [''],
            'a word' => ['unknown'],
            'a truncated address' => ['2001:db8::1::2'],
        ];
    }

    #[DataProvider('notAnAddress')]
    public function testWhatIsNotAnAddressComesBackUnchanged(string $value): void
    {
        $this->assertSame($value, RateLimitAddress::key($value));
    }
}
