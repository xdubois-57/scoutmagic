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
 * The envelope, tested as a lock rather than as code that runs.
 *
 * Every assertion is about what somebody holding the sealed bytes can and
 * cannot do: open them with the right key, and fail with anything else — a
 * wrong key, a changed byte, a truncated file.
 *
 * Where the key comes from is `PortableKeysTest`'s subject, and the split
 * is the correction a review forced: this class used to derive its own key
 * from the passphrase, which made it look self-sufficient while the zip
 * around it was keyed by that same phrase through a 1000-iteration
 * derivation.
 */
final class SecretEnvelopeTest extends TestCase
{
    private function key(): string
    {
        return random_bytes(32);
    }

    public function testWhatIsSealedComesBackExactly(): void
    {
        // 32 random bytes: a master key, and deliberately not a printable
        // string. A cipher that mangles a NUL byte or a high byte would
        // pass a test written with 'hello'.
        $secret = random_bytes(32);
        $key = $this->key();

        $this->assertSame($secret, SecretEnvelope::open(SecretEnvelope::seal($secret, $key), $key));
    }

    public function testTheSealedBytesAreNotTheSecret(): void
    {
        $secret = str_repeat('K', 32);

        $sealed = SecretEnvelope::seal($secret, $this->key());

        $this->assertStringNotContainsString($secret, $sealed);
        // Longer than the plaintext by exactly the IV and the tag: 12 + 16.
        $this->assertSame(strlen($secret) + 28, strlen($sealed));
    }

    /**
     * Two members sealed under one key do not share an IV.
     *
     * The key is per archive and therefore shared by its two secrets; a
     * reused IV under one key is the classic way to make GCM leak.
     */
    public function testTwoMembersOfOneArchiveDoNotShareAnIv(): void
    {
        $secret = str_repeat('K', 32);
        $key = $this->key();

        $first = SecretEnvelope::seal($secret, $key);
        $second = SecretEnvelope::seal($secret, $key);

        $this->assertNotSame($first, $second);
        $this->assertNotSame(substr($first, 0, 12), substr($second, 0, 12));
    }

    /** One key opens every member it sealed. */
    public function testOneKeyOpensEveryMemberItSealed(): void
    {
        $key = $this->key();
        $masterKey = random_bytes(32);
        $blob = 'le blob des identifiants';

        $this->assertSame($masterKey, SecretEnvelope::open(SecretEnvelope::seal($masterKey, $key), $key));
        $this->assertSame($blob, SecretEnvelope::open(SecretEnvelope::seal($blob, $key), $key));
    }

    public function testAWrongKeyCannotOpenIt(): void
    {
        $sealed = SecretEnvelope::seal(random_bytes(32), $this->key());

        $this->expectException(BackupException::class);
        SecretEnvelope::open($sealed, $this->key());
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
        $key = $this->key();
        $bytes = SecretEnvelope::seal(random_bytes(32), $key);
        $bytes[40] = $bytes[40] === 'A' ? 'B' : 'A';

        $this->expectException(BackupException::class);
        SecretEnvelope::open($bytes, $key);
    }

    public function testATruncatedEnvelopeIsRefused(): void
    {
        $key = $this->key();
        $sealed = SecretEnvelope::seal(random_bytes(32), $key);

        $this->expectException(BackupException::class);
        SecretEnvelope::open(substr($sealed, 0, 20), $key);
    }

    /**
     * A key of the wrong length is refused loudly.
     *
     * OpenSSL pads or truncates one without complaining, which would seal
     * an archive under a key nobody meant — and it would still open, on
     * this machine, this once. That is a wiring mistake, and it has to
     * fail where it is made.
     */
    public function testAKeyOfTheWrongLengthIsRefusedRatherThanPadded(): void
    {
        $this->expectException(BackupException::class);
        SecretEnvelope::seal('secret', 'trop court');
    }

    public function testOpeningWithAKeyOfTheWrongLengthIsRefusedToo(): void
    {
        $sealed = SecretEnvelope::seal(random_bytes(32), $this->key());

        $this->expectException(BackupException::class);
        SecretEnvelope::open($sealed, str_repeat('k', 31));
    }
}
