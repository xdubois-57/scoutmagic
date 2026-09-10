<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Portable;

use Core\Maintenance\BackupException;
use Core\Maintenance\Portable\PortableKeys;
use Core\Maintenance\Portable\PortableManifest;
use PHPUnit\Framework\TestCase;

/**
 * The derivation that decides what an attacker actually has to do.
 *
 * The property under test is not "the code runs" but "the zip never sees
 * the passphrase" — which is the entire correction a review forced here.
 * Hand a human phrase to `ZipArchive` and its 1000-iteration derivation
 * gives that phrase back to whoever cracks it; hand it 256 derived bits
 * and there is nothing to enumerate.
 */
final class PortableKeysTest extends TestCase
{
    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';

    /**
     * **The assertion this class exists for.**
     *
     * The archive password must not be the passphrase, nor contain it, nor
     * be any trivial transformation of it. Anything else and the zip's
     * cheap derivation is once again standing in front of a word list.
     */
    public function testTheArchivePasswordIsNotThePassphrase(): void
    {
        $keys = PortableKeys::derive(self::PASSPHRASE, PortableKeys::newDerivation());
        $password = $keys->archivePassword();

        $this->assertNotSame(self::PASSPHRASE, $password);
        $this->assertStringNotContainsString(self::PASSPHRASE, $password);
        $this->assertStringNotContainsString('quatre', $password);
        // 32 bytes, base64: 256 bits of entropy behind the zip's PBKDF2.
        $this->assertSame(44, strlen($password));
        $this->assertNotFalse(base64_decode($password, true));
    }

    /**
     * The archive password and the envelope key are not each other.
     *
     * The password is a string handed to a third-party library — it can
     * end up in a temporary file, a core dump, an error message. The key
     * that seals the master key must survive that, so neither may be
     * computable from the other.
     */
    public function testTheTwoKeysAreDomainSeparated(): void
    {
        $keys = PortableKeys::derive(self::PASSPHRASE, PortableKeys::newDerivation());

        $password = $keys->archivePassword();
        $envelopeKey = $keys->envelopeKey();

        $this->assertSame(32, strlen($envelopeKey));
        $this->assertNotSame($envelopeKey, base64_decode($password, true));
        $this->assertStringNotContainsString($envelopeKey, $password);
    }

    /** The same phrase and parameters always produce the same keys. */
    public function testTheDerivationIsDeterministic(): void
    {
        $params = PortableKeys::newDerivation();

        $first = PortableKeys::derive(self::PASSPHRASE, $params);
        $second = PortableKeys::derive(self::PASSPHRASE, $params);

        $this->assertSame($first->archivePassword(), $second->archivePassword());
        $this->assertSame($first->envelopeKey(), $second->envelopeKey());
    }

    public function testADifferentPassphraseProducesDifferentKeys(): void
    {
        $params = PortableKeys::newDerivation();

        $this->assertNotSame(
            PortableKeys::derive(self::PASSPHRASE, $params)->archivePassword(),
            PortableKeys::derive(self::PASSPHRASE . 'x', $params)->archivePassword()
        );
    }

    /** And a different archive, with the same phrase, has different keys. */
    public function testTwoArchivesDoNotShareKeys(): void
    {
        $this->assertNotSame(
            PortableKeys::derive(self::PASSPHRASE, PortableKeys::newDerivation())->archivePassword(),
            PortableKeys::derive(self::PASSPHRASE, PortableKeys::newDerivation())->archivePassword()
        );
    }

    public function testAnEmptyPassphraseIsRefused(): void
    {
        $this->expectException(BackupException::class);
        PortableKeys::derive('', PortableKeys::newDerivation());
    }

    public function testParametersWithoutASaltAreRefused(): void
    {
        $params = PortableKeys::newDerivation();
        unset($params['salt']);

        $this->expectException(BackupException::class);
        PortableKeys::derive(self::PASSPHRASE, $params);
    }

    /**
     * A derivation this version does not know is refused by name.
     *
     * Falling back to whichever derivation this server would have chosen
     * would silently produce the wrong key and report a wrong passphrase,
     * sending an operator to hunt for a phrase that was right all along.
     */
    public function testAnUnknownDerivationIsRefusedRatherThanGuessed(): void
    {
        $params = PortableKeys::newDerivation();
        $params['kdf'] = 'scrypt-from-the-future';

        $this->expectException(BackupException::class);
        PortableKeys::derive(self::PASSPHRASE, $params);
    }

    /**
     * The parameters decide, not this server's own capabilities.
     *
     * An archive sealed where there is no libsodium must open where there
     * is, and the reverse. Forcing PBKDF2 parameters on a machine that
     * HAS libsodium is the only way to prove the reader follows the
     * header rather than asking itself what it can do.
     */
    public function testPbkdf2ParametersAreHonouredEvenWhereLibsodiumExists(): void
    {
        // The production count, not a cheap one: the floor below refuses
        // anything less, and an archive claiming a fast derivation is
        // exactly what that floor exists to reject. Paying ~0.2 s once is
        // the honest price of testing the real branch.
        $params = [
            'kdf' => PortableKeys::KDF_PBKDF2_SHA256,
            'salt' => base64_encode(random_bytes(16)),
            'iterations' => PortableKeys::PBKDF2_ITERATIONS,
        ];

        $keys = PortableKeys::derive(self::PASSPHRASE, $params);

        $this->assertSame(32, strlen($keys->envelopeKey()));
        // Deterministic under those exact parameters, whatever this host has.
        $this->assertSame(
            $keys->archivePassword(),
            PortableKeys::derive(self::PASSPHRASE, $params)->archivePassword()
        );
    }

    /**
     * **An archive names its own cost, and an archive is a file somebody
     * else may have written.**
     *
     * IT-07 will hand one straight to a restore, so these numbers are
     * untrusted input. Two things must not happen: a cost so low that the
     * derivation is instant — the protection removed by the very file it
     * is written in — and a cost so high that a crafted header makes a
     * shared host allocate gigabytes or spin for minutes. Both are
     * refused by name rather than passed to libsodium, which would raise
     * a `SodiumException` outside this class's contract.
     */
    public function testAnArchiveCannotAskForATriviallyCheapDerivation(): void
    {
        $params = [
            'kdf' => PortableKeys::KDF_PBKDF2_SHA256,
            'salt' => base64_encode(random_bytes(16)),
            'iterations' => 1,
        ];

        $this->expectException(BackupException::class);
        PortableKeys::derive(self::PASSPHRASE, $params);
    }

    public function testAnArchiveCannotAskForARuinouslyExpensiveDerivation(): void
    {
        $params = [
            'kdf' => PortableKeys::KDF_PBKDF2_SHA256,
            'salt' => base64_encode(random_bytes(16)),
            'iterations' => 999999999,
        ];

        $this->expectException(BackupException::class);
        PortableKeys::derive(self::PASSPHRASE, $params);
    }

    /** Argon2id: a salt of the wrong length is refused before libsodium. */
    public function testAnArgon2idSaltOfTheWrongLengthIsRefusedNotPassedToSodium(): void
    {
        if (!PortableKeys::hasSodium()) {
            $this->markTestSkipped('No libsodium on this host, so there is no Argon2id branch to reach.');
        }

        $params = PortableKeys::newDerivation();
        $params['salt'] = base64_encode(random_bytes(8));

        $this->expectException(BackupException::class);
        PortableKeys::derive(self::PASSPHRASE, $params);
    }

    /** And a cost outside the accepted range, likewise. */
    public function testAnArgon2idCostOutsideTheAcceptedRangeIsRefused(): void
    {
        if (!PortableKeys::hasSodium()) {
            $this->markTestSkipped('No libsodium on this host, so there is no Argon2id branch to reach.');
        }

        $params = PortableKeys::newDerivation();
        // Far beyond SENSITIVE: a header asking a shared host for this
        // much memory is not one any ScoutMagic ever wrote.
        $params['memlimit'] = 64 * 1024 * 1024 * 1024;

        $this->expectException(BackupException::class);
        PortableKeys::derive(self::PASSPHRASE, $params);
    }

    /** An opslimit of 1 is refused too — instant is not a cost. */
    public function testAnArgon2idOpslimitBelowTheFloorIsRefused(): void
    {
        if (!PortableKeys::hasSodium()) {
            $this->markTestSkipped('No libsodium on this host, so there is no Argon2id branch to reach.');
        }

        $params = PortableKeys::newDerivation();
        $params['opslimit'] = 1;

        $this->expectException(BackupException::class);
        PortableKeys::derive(self::PASSPHRASE, $params);
    }

    /** What this server itself writes is, of course, inside the range. */
    public function testWhatThisServerWritesIsAcceptedByItsOwnReader(): void
    {
        $params = PortableKeys::newDerivation();

        $this->assertSame(32, strlen(PortableKeys::derive(self::PASSPHRASE, $params)->envelopeKey()));
    }

    public function testTheFallbackIterationCountStaysFarAboveTheZipFormats(): void
    {
        // The zip format's own derivation is 1000 iterations of
        // PBKDF2-HMAC-SHA1, and the whole point of this class is not to be
        // that. A count drifting back down towards it would leave every
        // name in place and the protection gone.
        $this->assertGreaterThanOrEqual(600000, PortableKeys::PBKDF2_ITERATIONS);
    }

    /** Whatever this host has, the recorded parameters describe it. */
    public function testTheRecordedParametersDescribeWhatWillBeUsed(): void
    {
        $params = PortableKeys::newDerivation();

        if (PortableKeys::hasSodium()) {
            $this->assertSame(PortableKeys::KDF_ARGON2ID, $params['kdf']);
            $this->assertArrayHasKey('opslimit', $params);
            $this->assertArrayHasKey('memlimit', $params);
        } else {
            $this->assertSame(PortableKeys::KDF_PBKDF2_SHA256, $params['kdf']);
            $this->assertSame(PortableKeys::PBKDF2_ITERATIONS, $params['iterations']);
        }

        $this->assertNotFalse(base64_decode((string) $params['salt'], true));
    }

    /**
     * The comment round-trips, because it is the only way in.
     *
     * The archive password is derived from what this carries, so an
     * archive whose comment cannot be read back is an archive nobody can
     * open — including the site that wrote it.
     */
    public function testTheArchiveCommentCarriesTheDerivationBackIntact(): void
    {
        $params = PortableKeys::newDerivation();

        $this->assertSame($params, PortableKeys::parseComment(PortableKeys::comment($params)));
    }

    /** And it says, in French, what the file is — a human opens these too. */
    public function testTheCommentTellsAHumanWhatTheyAreLookingAt(): void
    {
        $comment = PortableKeys::comment(PortableKeys::newDerivation());

        $this->assertStringContainsString(PortableManifest::FORMAT, $comment);
        $this->assertStringContainsString('Sauvegarde portable ScoutMagic', $comment);
    }

    public function testACommentFromSomethingElseIsRefused(): void
    {
        $this->expectException(BackupException::class);
        PortableKeys::parseComment('{"format":"some-other-tool"}');
    }

    public function testACommentThatIsNotJsonAtAllIsRefused(): void
    {
        $this->expectException(BackupException::class);
        PortableKeys::parseComment('');
    }

    /**
     * A future format is refused rather than half-read.
     *
     * The version is in the header from the first archive ever written
     * precisely so that an older site meeting a newer archive says so,
     * instead of deriving a key from fields it has misunderstood.
     */
    public function testAFutureFormatVersionIsRefused(): void
    {
        $document = json_decode(PortableKeys::comment(PortableKeys::newDerivation()), true);
        $this->assertIsArray($document);
        $document['format_version'] = PortableManifest::FORMAT_VERSION + 1;

        $this->expectException(BackupException::class);
        PortableKeys::parseComment((string) json_encode($document));
    }

    public function testACommentWithoutADerivationIsRefused(): void
    {
        $this->expectException(BackupException::class);
        PortableKeys::parseComment((string) json_encode([
            'format' => PortableManifest::FORMAT,
            'format_version' => PortableManifest::FORMAT_VERSION,
        ]));
    }
}
