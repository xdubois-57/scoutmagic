<?php

declare(strict_types=1);

namespace Tests\Core\Statistics;

use Core\Statistics\DestinationMatcher;
use PHPUnit\Framework\TestCase;

class DestinationMatcherTest extends TestCase
{
    public function testSchemeAndPortAndWwwPrefixAreIgnored(): void
    {
        $this->assertTrue(DestinationMatcher::isSameHost('https://www.scoutmagic.be', 'http://scoutmagic.be:443'));
    }

    public function testOtherSubdomainIsNotEquivalent(): void
    {
        $this->assertFalse(DestinationMatcher::isSameHost('https://stats.scoutmagic.be', 'https://scoutmagic.be'));
    }

    public function testComparisonIsCaseInsensitive(): void
    {
        $this->assertTrue(DestinationMatcher::isSameHost('https://scoutmagic.be', 'https://scoutmagic.BE'));
    }

    public function testEmptyUrlNeverMatches(): void
    {
        $this->assertFalse(DestinationMatcher::isSameHost('', 'https://scoutmagic.be'));
        $this->assertFalse(DestinationMatcher::isSameHost('https://scoutmagic.be', ''));
        $this->assertFalse(DestinationMatcher::isSameHost('', ''));
        $this->assertFalse(DestinationMatcher::isSameHost('   ', 'https://scoutmagic.be'));
    }

    public function testWwwIsOnlyStrippedOnAFullLabel(): void
    {
        $this->assertFalse(DestinationMatcher::isSameHost('https://wwwscoutmagic.be', 'https://scoutmagic.be'));
    }

    public function testHostWithoutSchemeStillCompares(): void
    {
        $this->assertTrue(DestinationMatcher::isSameHost('scoutmagic.be', 'https://www.scoutmagic.be'));
    }

    public function testTrailingDotIsIgnored(): void
    {
        $this->assertTrue(DestinationMatcher::isSameHost('https://scoutmagic.be./', 'https://scoutmagic.be'));
    }

    public function testPathAndQueryDoNotAffectTheHost(): void
    {
        $this->assertTrue(DestinationMatcher::isSameHost('https://scoutmagic.be/api/statistics?x=1', 'https://scoutmagic.be'));
    }

    public function testDifferentHostsDoNotMatch(): void
    {
        $this->assertFalse(DestinationMatcher::isSameHost('https://scoutmagic.be', 'https://example.be'));
    }

    public function testIsReceiverAppliesIsSameHost(): void
    {
        $this->assertTrue(DestinationMatcher::isReceiver('https://www.scoutmagic.be', 'https://scoutmagic.be'));
        $this->assertFalse(DestinationMatcher::isReceiver('https://unite-exemple.be', 'https://www.scoutmagic.be'));
    }

    public function testIsReceiverIsFalseWhenEitherSideIsNull(): void
    {
        $this->assertFalse(DestinationMatcher::isReceiver(null, 'https://scoutmagic.be'));
        $this->assertFalse(DestinationMatcher::isReceiver('https://scoutmagic.be', null));
        $this->assertFalse(DestinationMatcher::isReceiver(null, null));
    }

    public function testDegenerateInputsNeverMatch(): void
    {
        $this->assertFalse(DestinationMatcher::isSameHost('https://', 'https://'));
        $this->assertFalse(DestinationMatcher::isSameHost('///', 'scoutmagic.be'));
        $this->assertFalse(DestinationMatcher::isSameHost('http://:8080', 'scoutmagic.be'));
    }

    public function testLocalhostMatchesItself(): void
    {
        $this->assertTrue(DestinationMatcher::isSameHost('http://localhost:8080', 'http://localhost'));
    }
}
