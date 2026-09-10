<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Portable;

use Core\Maintenance\Portable\PortablePassphrase;
use PHPUnit\Framework\TestCase;

final class PortablePassphraseTest extends TestCase
{
    public function testAShortPassphraseIsRefusedWithAReason(): void
    {
        $refusal = PortablePassphrase::refuse('trop court');

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('16', $refusal);
    }

    public function testExactlyTheMinimumIsAccepted(): void
    {
        $this->assertNull(PortablePassphrase::refuse(str_repeat('a', PortablePassphrase::MIN_LENGTH)));
    }

    public function testOneCharacterShortIsNot(): void
    {
        $this->assertNotNull(PortablePassphrase::refuse(str_repeat('a', PortablePassphrase::MIN_LENGTH - 1)));
    }

    public function testAnEmptyPassphraseIsRefused(): void
    {
        $this->assertNotNull(PortablePassphrase::refuse(''));
    }

    /**
     * Characters, not bytes — and this is the assertion that would catch
     * `strlen()`.
     *
     * Sixteen accented characters are 32 bytes in UTF-8, so a byte count
     * would accept them either way; the case that separates the two is the
     * SHORT one. Eight accented characters are sixteen bytes: `strlen()`
     * would wave them through as long enough, halving the rule for an
     * operator who writes French.
     */
    public function testAccentsAreCountedAsCharactersRatherThanBytes(): void
    {
        $eightAccentedCharacters = str_repeat('é', 8);

        $this->assertSame(16, strlen($eightAccentedCharacters));
        $this->assertSame(8, mb_strlen($eightAccentedCharacters));
        $this->assertNotNull(PortablePassphrase::refuse($eightAccentedCharacters));
    }

    /** A passphrase of accented characters that IS long enough passes. */
    public function testALongEnoughAccentedPassphraseIsAccepted(): void
    {
        $this->assertNull(PortablePassphrase::refuse(str_repeat('é', PortablePassphrase::MIN_LENGTH)));
    }

    /**
     * No character-class rule crept in.
     *
     * Four ordinary lowercase words is exactly the shape this policy means
     * to accept: it is what an operator will still be able to type in a
     * year, and length is the property that actually costs an attacker
     * something. A future "must contain a digit" would fail here, which is
     * the point — that rule produces `Scout2026!` and nothing better.
     */
    public function testFourOrdinaryWordsAreEnough(): void
    {
        $this->assertNull(PortablePassphrase::refuse('cheval agrafeuse fenetre tambour'));
    }
}
