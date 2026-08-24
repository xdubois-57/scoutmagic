<?php

declare(strict_types=1);

namespace Tests\Modules\Camps;

use Modules\Camps\Support;
use PHPUnit\Framework\TestCase;

/**
 * "Is there a value here, or is this field simply not filled in", asked
 * from the two ends — six private copies before this.
 */
class SupportTest extends TestCase
{
    public function testCleanKeepsAValueAndTrimsIt(): void
    {
        $this->assertSame('Domaine de Mozet', Support::clean('  Domaine de Mozet '));
    }

    /**
     * A copy that forgets the trim() stores a single space: not null, not
     * empty, renders as nothing, and passes every "is it filled in" check
     * there is.
     */
    public function testCleanTreatsWhitespaceAsNotFilledIn(): void
    {
        $this->assertNull(Support::clean('   '));
        $this->assertNull(Support::clean("\n\t "));
        $this->assertNull(Support::clean(''));
        $this->assertNull(Support::clean(null));
    }

    public function testNullableStringReadsBothSpellingsOfNotKnown(): void
    {
        $this->assertNull(Support::nullableString(null));
        $this->assertNull(Support::nullableString(''));
        $this->assertSame('5000', Support::nullableString(5000));
        $this->assertSame('Namur', Support::nullableString('Namur'));
    }

    /**
     * Not the same function as clean(): a database value is taken as it
     * is. A column holding a space holds a space, and inventing a
     * normalisation on the read path would make the row and the object
     * disagree.
     */
    public function testNullableStringDoesNotTrim(): void
    {
        $this->assertSame(' ', Support::nullableString(' '));
    }

    public function testZeroIsAValueNotAnAbsence(): void
    {
        $this->assertSame('0', Support::nullableString('0'));
        $this->assertSame('0', Support::clean('0'));
    }
}
