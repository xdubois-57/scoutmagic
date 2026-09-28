<?php

declare(strict_types=1);

namespace Tests\Core\Member\Household;

use Core\Member\Household\HouseholdKey;
use PHPUnit\Framework\TestCase;

/**
 * The opaque household identity the core hands to modules (issue #630).
 */
final class HouseholdKeyTest extends TestCase
{
    public function testItGivesBackExactlyWhatIsStored(): void
    {
        $this->assertSame('abc123', HouseholdKey::fromStorable('abc123')->storable());
    }

    public function testTwoKeysOfTheSameHouseholdAreEqualAndOthersAreNot(): void
    {
        $this->assertTrue(HouseholdKey::fromStorable('abc')->equals(HouseholdKey::fromStorable('abc')));
        $this->assertFalse(HouseholdKey::fromStorable('abc')->equals(HouseholdKey::fromStorable('abd')));
    }

    /**
     * An address that does not normalize has no household — the core
     * returns null for it. An empty key would instead be one household
     * shared by every such address, the exact « household of one » mistake
     * Core\Member\Household\HouseholdService refuses to make.
     */
    public function testThereIsNoEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HouseholdKey::fromStorable('');
    }
}
