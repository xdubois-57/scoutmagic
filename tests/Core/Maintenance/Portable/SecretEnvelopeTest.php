<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Portable;

use Core\Maintenance\BackupException;
use Core\Maintenance\Portable\SecretEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * The second lock on the master key, tested as a lock rather than as code
 * that runs.
 *
 * Every assertion here is about what somebody holding the archive can and
 * cannot do with it: open it with the right passphrase, and fail with
 * anything else — a wrong phrase, a changed byte, a truncated file, a
 * manifest claiming a derivation nobody has heard of.
 */
final class SecretEnvelopeTest extends TestCase
{
    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';

    public function testWhatIsSealedComesBackExactly(): void
    {
        // 32 random bytes: a master key, and deliberately not a printable
        // string. A cipher that mangles a NUL byte or a high byte would
        // pass a test written with 'hello'.
        $secret = random_bytes(32);

        $params = SecretEnvelope::newDerivation();
        $sealed = SecretEnvelope::seal($secret, self::PASSPHRASE, $params);

        $this->assertSame($secret, SecretEnvelope::open($sealed, self::PASSPHRASE, $params));
    }

    public function testTheSealedBytesAreNotTheSecret(): void
    {
        $secret = str_repeat('K', 32);

        $sealed = SecretEnvelope::seal($secret, self::PASSPHRASE, SecretEnvelope::newDerivation());

        $this->assertStringNotContainsString($secret, $sealed);
        // Longer than the plaintext by exactly the IV and the tag: 12 + 16.
        $this->assertSame(strlen($secret) + 28, strlen($sealed));
    }

    /**
     * Two envelopes of the same secret differ, even under one derivation.
     *
     * The salt is per archive, so within one archive it is deliberately
     * shared — what still has to be fresh for each member is the IV, and
     * a reused one under the same key is the classic way to make GCM leak.
     */
    public function testTwoMembersOfOneArchiveDoNotShareAnIv(): void
    {
        $secret = str_repeat('K', 32);
        $params = SecretEnvelope::newDerivation();

        $first = SecretEnvelope::seal($secret, self::PASSPHRASE, $params);
        $second = SecretEnvelope::seal($secret, self::PASSPHRASE, $params);

        $this->assertNotSame($first, $second);
        $this->assertNotSame(substr($first, 0, 12), substr($second, 0, 12));
    }

    /** And two archives do not share a salt. */
    public function testTwoArchivesDoNotShareASalt(): void
    {
        $this->assertNotSame(
            SecretEnvelope::newDerivation()['salt'],
            SecretEnvelope::newDerivation()['salt']
        );
    }

    /**
     * Every member of one archive is sealed under the SAME derivation, and
     * the manifest's copy of it opens all of them.
     *
     * This is the regression an integration test found: `seal()` used to
     * mint its own salt per call, the caller recorded the first, and the
     * second sealed file could never be opened by anything reading the
     * manifest. Nothing failed while producing it.
     */
    public function testOneDerivationOpensEveryMemberItSealed(): void
    {
        $params = SecretEnvelope::newDerivation();
        $key = random_bytes(32);
        $blob = 'le blob des identifiants';

        $sealedKey = SecretEnvelope::seal($key, self::PASSPHRASE, $params);
        $sealedBlob = SecretEnvelope::seal($blob, self::PASSPHRASE, $params);

        $this->assertSame($key, SecretEnvelope::open($sealedKey, self::PASSPHRASE, $params));
        $this->assertSame($blob, SecretEnvelope::open($sealedBlob, self::PASSPHRASE, $params));
    }

    public function testAWrongPassphraseCannotOpenIt(): void
    {
        $params = SecretEnvelope::newDerivation();
        $sealed = SecretEnvelope::seal(random_bytes(32), self::PASSPHRASE, $params);

        $this->expectException(BackupException::class);
        SecretEnvelope::open($sealed, self::PASSPHRASE . 'x', $params);
    }

    /**
     * A changed byte is refused, not silently decrypted to rubbish.
     *
     * This is what AES-GCM buys over a bare cipher, and it is the property
     * that matters for an archive that has travelled: a corrupted master
     * key restored without complaint would produce an installation that
     * starts and cannot read a single encrypted column.
     */
    public function testATamperedEnvelopeIsRefused(): void
    {
        $params = SecretEnvelope::newDerivation();
        $bytes = SecretEnvelope::seal(random_bytes(32), self::PASSPHRASE, $params);
        $bytes[40] = $bytes[40] === 'A' ? 'B' : 'A';

        $this->expectException(BackupException::class);
        SecretEnvelope::open($bytes, self::PASSPHRASE, $params);
    }

    public function testATruncatedEnvelopeIsRefused(): void
    {
        $params = SecretEnvelope::newDerivation();
        $sealed = SecretEnvelope::seal(random_bytes(32), self::PASSPHRASE, $params);

        $this->expectException(BackupException::class);
        SecretEnvelope::open(substr($sealed, 0, 20), self::PASSPHRASE, $params);
    }

    public function testAManifestWithoutASaltIsRefused(): void
    {
        $params = SecretEnvelope::newDerivation();
        $sealed = SecretEnvelope::seal(random_bytes(32), self::PASSPHRASE, $params);
        unset($params['salt']);

        $this->expectException(BackupException::class);
        SecretEnvelope::open($sealed, self::PASSPHRASE, $params);
    }

    /**
     * A derivation this version does not know is refused by name.
     *
     * The alternative — falling back to whichever derivation this server
     * would have chosen — would silently produce the wrong key and report
     * a wrong passphrase, sending an operator to hunt for a phrase that
     * was right all along.
     */
    public function testAnUnknownDerivationIsRefusedRatherThanGuessed(): void
    {
        $params = SecretEnvelope::newDerivation();
        $sealed = SecretEnvelope::seal(random_bytes(32), self::PASSPHRASE, $params);
        $params['kdf'] = 'scrypt-from-the-future';

        $this->expectException(BackupException::class);
        SecretEnvelope::open($sealed, self::PASSPHRASE, $params);
    }

    /**
     * The parameters say which derivation was used, and the archive is
     * opened with THAT one.
     *
     * The fallback is not dead code waiting for an exotic host: it is what
     * happens on any server without libsodium, and an archive sealed there
     * must open on a server that has it. Forcing PBKDF2 parameters through
     * a round trip on this machine — which does have libsodium — is the
     * only way to prove the reader follows the manifest rather than its
     * own capabilities.
     */
    public function testAnArchiveSealedWithoutLibsodiumOpensWhereItExists(): void
    {
        $secret = random_bytes(32);
        $salt = random_bytes(16);
        $params = [
            'kdf' => SecretEnvelope::KDF_PBKDF2_SHA256,
            'salt' => base64_encode($salt),
            // Not the production count: this test would then pay the
            // deliberate cost of the real derivation twice, for a property
            // that has nothing to do with how slow it is.
            'iterations' => 1000,
        ];

        $key = hash_pbkdf2('sha256', 'phrase de passe portable ordinaire', $salt, 1000, 32, true);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, SecretEnvelope::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        $this->assertNotFalse($ciphertext);
        $this->assertSame(
            $secret,
            SecretEnvelope::open($iv . $tag . $ciphertext, 'phrase de passe portable ordinaire', $params)
        );
    }

    /** Whatever this server has, the recorded parameters describe it. */
    public function testTheRecordedParametersDescribeTheDerivationActuallyUsed(): void
    {
        $params = SecretEnvelope::newDerivation();

        if (SecretEnvelope::hasSodium()) {
            $this->assertSame(SecretEnvelope::KDF_ARGON2ID, $params['kdf']);
            $this->assertArrayHasKey('opslimit', $params);
            $this->assertArrayHasKey('memlimit', $params);
        } else {
            $this->assertSame(SecretEnvelope::KDF_PBKDF2_SHA256, $params['kdf']);
            $this->assertSame(SecretEnvelope::PBKDF2_ITERATIONS, $params['iterations']);
        }

        $this->assertNotFalse(base64_decode((string) $params['salt'], true));
    }

    /**
     * The fallback is not weaker than the format it replaces by accident.
     *
     * A zip's AES derives its key with PBKDF2-HMAC-SHA1 at 1000
     * iterations, and the whole reason this envelope exists is that this
     * is not enough for the one archive holding the master key. A count
     * that drifted back down towards that figure would leave the class
     * standing and its purpose gone, without any test failing — so the
     * number is pinned against what it is supposed to beat.
     */
    public function testTheFallbackDerivationIsOrdersOfMagnitudeAboveTheZipFormats(): void
    {
        $this->assertGreaterThanOrEqual(600000, SecretEnvelope::PBKDF2_ITERATIONS);
    }
}
