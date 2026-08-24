<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\CapabilityToken;
use PHPUnit\Framework\TestCase;

/**
 * The contract five modules now depend on. Every case below is a way a
 * bearer token in a URL has gone wrong somewhere before.
 */
class CapabilityTokenTest extends TestCase
{
    public function testGenerateReturnsSixtyFourHexCharacters(): void
    {
        $token = CapabilityToken::generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGenerateNeverRepeats(): void
    {
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $tokens[] = CapabilityToken::generate();
        }

        $this->assertCount(50, array_unique($tokens));
    }

    public function testTokenIsUrlSafeAsIssued(): void
    {
        $token = CapabilityToken::generate();

        $this->assertSame($token, rawurlencode($token), 'a link must survive an email client untouched');
    }

    public function testHashIsDeterministicAndDoesNotContainTheToken(): void
    {
        $token = CapabilityToken::generate();

        $this->assertSame(CapabilityToken::hash($token), CapabilityToken::hash($token));
        $this->assertStringNotContainsString($token, CapabilityToken::hash($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', CapabilityToken::hash($token));
    }

    public function testVerifyAgainstHashAcceptsTheRightTokenOnly(): void
    {
        $token = CapabilityToken::generate();
        $hash = CapabilityToken::hash($token);

        $this->assertTrue(CapabilityToken::verifyAgainstHash($token, $hash));
        $this->assertFalse(CapabilityToken::verifyAgainstHash(CapabilityToken::generate(), $hash));
    }

    /** Contract point 1: absent is the same refusal as wrong. */
    public function testVerifyAgainstHashRefusesWhenNothingWasEverIssued(): void
    {
        $this->assertFalse(CapabilityToken::verifyAgainstHash(CapabilityToken::generate(), null));
        $this->assertFalse(CapabilityToken::verifyAgainstHash(CapabilityToken::generate(), ''));
    }

    public function testVerifyAgainstHashRefusesAnEmptyPresentedToken(): void
    {
        $this->assertFalse(CapabilityToken::verifyAgainstHash('', CapabilityToken::hash('')));
    }

    public function testEqualsConstantTimeAcceptsTheRightTokenOnly(): void
    {
        $token = CapabilityToken::generate();

        $this->assertTrue(CapabilityToken::equalsConstantTime($token, $token));
        $this->assertFalse(CapabilityToken::equalsConstantTime($token, CapabilityToken::generate()));
        $this->assertFalse(
            CapabilityToken::equalsConstantTime($token, substr($token, 0, 63)),
            'a prefix is not a token'
        );
    }

    /**
     * The failure an unguarded `===` would allow: a row with no token
     * stored, opened by presenting no token at all.
     */
    public function testEqualsConstantTimeNeverAcceptsNothingAgainstNothing(): void
    {
        $this->assertFalse(CapabilityToken::equalsConstantTime(null, null));
        $this->assertFalse(CapabilityToken::equalsConstantTime('', ''));
        $this->assertFalse(CapabilityToken::equalsConstantTime(null, ''));
        $this->assertFalse(CapabilityToken::equalsConstantTime('', null));
        $this->assertFalse(CapabilityToken::equalsConstantTime(CapabilityToken::generate(), null));
        $this->assertFalse(CapabilityToken::equalsConstantTime(null, CapabilityToken::generate()));
    }
}
