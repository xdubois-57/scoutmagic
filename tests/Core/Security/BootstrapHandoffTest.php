<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\BootstrapHandoff;
use PHPUnit\Framework\TestCase;

/**
 * The proof the bootstrap hands the wizard (#719, D): an expiry and an
 * HMAC keyed by the installation token, recomputed from the token on disk.
 */
final class BootstrapHandoffTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NOW = 1_800_000_000;

    public function testAFreshProofIsAccepted(): void
    {
        $cookie = BootstrapHandoff::proofValue(self::TOKEN, self::NOW + BootstrapHandoff::PROOF_LIFETIME_SECONDS);

        $this->assertTrue(BootstrapHandoff::proofIsValid($cookie, self::TOKEN, self::NOW));
        $this->assertTrue(BootstrapHandoff::proofIsValid($cookie, self::TOKEN, self::NOW + 3600));
    }

    /** The token itself never travels: only its MAC does. */
    public function testTheCookieNeverCarriesTheToken(): void
    {
        $cookie = BootstrapHandoff::proofValue(self::TOKEN, self::NOW + 60);

        $this->assertStringNotContainsString(self::TOKEN, $cookie);
        $this->assertMatchesRegularExpression('/^\d+\.[0-9a-f]{64}$/', $cookie);
    }

    public function testAnExpiredProofIsRefused(): void
    {
        $cookie = BootstrapHandoff::proofValue(self::TOKEN, self::NOW + 60);

        $this->assertFalse(BootstrapHandoff::proofIsValid($cookie, self::TOKEN, self::NOW + 60));
        $this->assertFalse(BootstrapHandoff::proofIsValid($cookie, self::TOKEN, self::NOW + 3 * 3600));
    }

    /**
     * Nobody holding the token would mint a longer proof than the
     * bootstrap does — and refusing one bounds a leaked cookie's life.
     */
    public function testAProofClaimingALongerLifetimeIsRefused(): void
    {
        $cookie = BootstrapHandoff::proofValue(self::TOKEN, self::NOW + BootstrapHandoff::PROOF_LIFETIME_SECONDS + 1);

        $this->assertFalse(BootstrapHandoff::proofIsValid($cookie, self::TOKEN, self::NOW));
    }

    public function testAProofSignedWithAnotherTokenIsRefused(): void
    {
        $cookie = BootstrapHandoff::proofValue(str_repeat('b', 64), self::NOW + 60);

        $this->assertFalse(BootstrapHandoff::proofIsValid($cookie, self::TOKEN, self::NOW));
    }

    public function testAnEditedExpiryBreaksTheSignature(): void
    {
        $cookie = BootstrapHandoff::proofValue(self::TOKEN, self::NOW + 60);
        [, $mac] = explode('.', $cookie);

        $this->assertFalse(BootstrapHandoff::proofIsValid((self::NOW + 120) . '.' . $mac, self::TOKEN, self::NOW));
    }

    public function testMalformedCookiesAndAnEmptyTokenProveNothing(): void
    {
        $this->assertFalse(BootstrapHandoff::proofIsValid('', self::TOKEN, self::NOW));
        $this->assertFalse(BootstrapHandoff::proofIsValid('garbage', self::TOKEN, self::NOW));
        $this->assertFalse(BootstrapHandoff::proofIsValid((self::NOW + 60) . '.XYZ', self::TOKEN, self::NOW));
        $this->assertFalse(BootstrapHandoff::proofIsValid(
            BootstrapHandoff::proofValue('', self::NOW + 60),
            '',
            self::NOW
        ));
    }

    /**
     * The frozen values themselves (#719, D): a newer bootstrap talks to
     * every wizard from this release on, so these never change in place.
     */
    public function testTheHandoffContractIsFrozen(): void
    {
        $this->assertSame('storage/restore/portable-restore.zip', BootstrapHandoff::ARCHIVE_PATH);
        $this->assertSame('storage/restore/incoming', BootstrapHandoff::INCOMING_DIR);
        $this->assertSame('scoutmagic_setup_proof', BootstrapHandoff::PROOF_COOKIE);
        $this->assertSame(7200, BootstrapHandoff::PROOF_LIFETIME_SECONDS);
        $this->assertSame('/setup?restauration=1', BootstrapHandoff::RESTORE_MODE_URL);
        $this->assertSame(
            '1800007200.' . hash_hmac('sha256', 'scoutmagic-setup-proof-v1|1800007200', self::TOKEN),
            BootstrapHandoff::proofValue(self::TOKEN, 1_800_007_200)
        );
    }
}
