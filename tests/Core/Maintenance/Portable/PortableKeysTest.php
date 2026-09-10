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
        $params = [
            'kdf' => PortableKeys::KDF_PBKDF2_SHA256,
            'salt' => base64_encode(random_bytes(16)),
            // Not the production count: this test is about which branch
            // runs, not about how slow it is.
            'iterations' => 1000,
        ];

        $keys = PortableKeys::derive(self::PASSPHRASE, $params);

        $this->assertSame(32, strlen($keys->envelopeKey()));
        // Deterministic under those exact parameters, whatever this host has.
        $this->assertSame(
            $keys->archivePassword(),
            PortableKeys::derive(self::PASSPHRASE, $params)->archivePassword()
        );
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
