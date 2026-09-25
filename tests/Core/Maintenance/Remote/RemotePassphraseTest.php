<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Remote;

use Core\Maintenance\Portable\PortablePassphrase;
use Core\Maintenance\Remote\RemoteBackupException;
use Core\Maintenance\Remote\RemotePassphrase;
use Core\Security\SecretManager;
use PHPUnit\Framework\TestCase;

/**
 * The phrase the scheduled send encrypts with, and the number that says
 * which archives it opens.
 *
 * A real `SecretManager` over a temporary directory rather than a double:
 * what matters here is that the phrase lands in `secrets.enc` and nowhere
 * else, and a double would assert that by construction instead of by
 * observation.
 */
// Through `RefusingSettingService`, whose parent's constructor opens an
// in-memory database: this class builds none itself and inherits none.
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RemotePassphraseTest extends TestCase
{
    private string $base;
    private RefusingSettingService $settings;
    private SecretManager $secrets;
    private RemotePassphrase $passphrase;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/remote_phrase_' . uniqid();
        @mkdir($this->base . '/keys', 0700, true);
        @mkdir($this->base . '/config', 0700, true);

        $this->secrets = new SecretManager($this->base . '/keys/master.key', $this->base . '/config/secrets.enc');
        $this->secrets->generateMasterKey();
        $this->secrets->writeSecrets(['smtp_password' => 'le-mot-de-passe-smtp']);

        $this->settings = new RefusingSettingService();
        $this->passphrase = new RemotePassphrase($this->settings, $this->secrets);
    }

    protected function tearDown(): void
    {
        foreach (['/config/secrets.enc', '/keys/master.key'] as $file) {
            @unlink($this->base . $file);
        }
        @rmdir($this->base . '/config');
        @rmdir($this->base . '/keys');
        @rmdir($this->base);
    }

    /**
     * **It is generated on first use, not when the destination is
     * connected.**
     *
     * A site that has never sent anything has no phrase to lose, and the
     * first scheduled send creates one — no step for an operator to
     * forget, and nothing to regenerate before the first archive exists.
     */
    public function testTheFirstUseCreatesAPhraseAndItsGeneration(): void
    {
        $this->assertSame('', $this->passphrase->stored(), 'a phrase existed before anything asked for one');
        $this->assertSame(0, $this->passphrase->generation());

        $phrase = $this->passphrase->current();

        $this->assertNotSame('', $phrase);
        $this->assertSame($phrase, $this->passphrase->stored());
        $this->assertSame(1, $this->passphrase->generation(), 'the first phrase is not generation 1');
        $this->assertNotSame('', $this->passphrase->createdAt());
    }

    /** Asking twice returns the same phrase: only regenerate() replaces it. */
    public function testAskingTwiceDoesNotQuietlyReplaceTheArchivesKey(): void
    {
        $first = $this->passphrase->current();

        $this->assertSame($first, $this->passphrase->current());
        $this->assertSame(1, $this->passphrase->generation());
    }

    /**
     * **The phrase goes to `secrets.enc`, and the rest of it survives.**
     *
     * That file is one JSON document carrying the SMTP password and the
     * site's other secrets; a write that replaced it wholesale would be
     * the defect IT-08 already met once.
     */
    public function testThePhraseJoinsTheOtherSecretsWithoutDisplacingThem(): void
    {
        $phrase = $this->passphrase->current();

        $onDisk = $this->secrets->readSecrets();
        $this->assertSame($phrase, $onDisk[RemotePassphrase::SECRET_KEY] ?? null);
        $this->assertSame('le-mot-de-passe-smtp', $onDisk['smtp_password'] ?? null);

        // And never in a settings row, which renders on Configuration >
        // Réglages and travels in the diagnostic archive.
        $this->assertNotContains($phrase, array_values($this->settings->values));
    }

    /**
     * Regenerating replaces the phrase and advances the generation — the
     * number that tells an operator which archives the current phrase
     * still opens, because nothing re-encrypts the older ones.
     */
    public function testRegeneratingAdvancesTheGenerationThatNamesTheFiles(): void
    {
        $first = $this->passphrase->current();

        $second = $this->passphrase->regenerate();

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $this->passphrase->stored());
        $this->assertSame(2, $this->passphrase->generation());
    }

    /**
     * **Unreadable secrets refuse rather than silently mint a phrase.**
     *
     * Answering with a fresh one would encrypt the next archive under a
     * key that was never stored — an upload that succeeds and can never
     * be opened, which is the failure shape a backup must not have.
     */
    public function testUnreadableSecretsRefuseInsteadOfInventingAPhrase(): void
    {
        file_put_contents($this->base . '/config/secrets.enc', 'ceci n\'est pas un coffre');

        try {
            $this->passphrase->current();
            $this->fail('A phrase was minted over unreadable secrets.');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('illisible', $e->getMessage());
        }
    }

    /**
     * **Shaped to be copied onto paper and typed back.**
     *
     * This phrase is written down and re-entered months later, possibly
     * by somebody who did not write it, in front of a server that no
     * longer exists. The characters that get transcribed wrong are always
     * the same ones, so they are not in the alphabet at all.
     */
    public function testThePhraseIsLongEnoughAndCarriesNoAmbiguousCharacters(): void
    {
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $phrase = RemotePassphrase::generate();

            $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}(-[A-Z2-9]{5}){5}$/', $phrase);
            $this->assertDoesNotMatchRegularExpression('/[ILO01]/', $phrase, "ambiguous character in {$phrase}");
            // Comfortably past the floor for a phrase a human invented:
            // nobody has to remember this one, only to copy it.
            $this->assertNull(PortablePassphrase::refuse($phrase));
        }
    }

    /** Two phrases in a row are not the same phrase. */
    public function testTwoGeneratedPhrasesDiffer(): void
    {
        $seen = [];
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $seen[] = RemotePassphrase::generate();
        }

        $this->assertCount(20, array_unique($seen));
    }
    /**
     * **A half-finished regeneration puts the phrase back.**
     *
     * Two stores and no transaction across them: the phrase is a file and
     * the generation is a settings row. If the number cannot follow the
     * secret, every later send would encrypt with generation 2 while
     * `remoteName()` still read 1 — an archive named `…-g1.zip` that the
     * first phrase does not open, which is the exact confusion the
     * generation exists to prevent.
     */
    public function testAGenerationThatCannotBeRecordedPutsTheOldPhraseBack(): void
    {
        $first = $this->passphrase->current();
        $this->assertSame(1, $this->passphrase->generation());

        $this->settings->refuseKey = RemotePassphrase::GENERATION_SETTING;

        try {
            $this->passphrase->regenerate();
            $this->fail('a regeneration that could not record its generation reported success');
        } catch (RemoteBackupException $e) {
            $this->assertStringContainsString('ancienne reste en vigueur', $e->getMessage());
        }

        $this->settings->refuseKey = '';
        $this->assertSame($first, $this->passphrase->stored(), 'the site now encrypts with a phrase nothing names');
        $this->assertSame(1, $this->passphrase->generation());
    }

    /**
     * The very first phrase is no exception: a failure there leaves the
     * site with no phrase at all rather than one no file name describes.
     */
    public function testAFailedFirstGenerationLeavesNoPhraseBehind(): void
    {
        $this->settings->refuseKey = RemotePassphrase::GENERATION_SETTING;

        $this->expectException(RemoteBackupException::class);

        try {
            $this->passphrase->regenerate();
        } finally {
            $this->settings->refuseKey = '';
            $this->assertSame('', $this->passphrase->stored());
            $this->assertSame(0, $this->passphrase->generation());
            // And the neighbours in secrets.enc are untouched.
            $this->assertSame('le-mot-de-passe-smtp', $this->secrets->readSecrets()['smtp_password'] ?? '');
        }
    }

    // ————— « Je l'ai recopiée hors du serveur » (#496) —————

    /**
     * Nothing to note before there is a phrase.
     *
     * A confirmation recorded against generation 0 would silently satisfy
     * the first real phrase the day the first send generates it — the
     * warning would never appear on exactly the site that needs it.
     */
    public function testThereIsNothingToConfirmBeforeThereIsAPhrase(): void
    {
        $this->assertFalse($this->passphrase->isNoted());
        $this->assertFalse($this->passphrase->markNoted(), 'a phrase that does not exist was declared noted');
        $this->assertSame(0, $this->passphrase->confirmedGeneration());
        $this->assertFalse($this->passphrase->isNoted());
    }

    /** The statement is recorded against the generation it was made for. */
    public function testConfirmingRecordsTheGenerationInForceAndTheDay(): void
    {
        $this->passphrase->current();

        $this->assertFalse($this->passphrase->isNoted(), 'a phrase nobody has copied was already noted');
        $this->assertTrue($this->passphrase->markNoted());

        $this->assertTrue($this->passphrase->isNoted());
        $this->assertSame(1, $this->passphrase->confirmedGeneration());
        $this->assertNotSame('', $this->passphrase->confirmedAt());
    }

    /**
     * **Regenerating asks again, and nothing had to remember to reset.**
     *
     * The new phrase is a new thing to write down, and the note somebody
     * made now opens only the archives already sent. Holding the
     * generation rather than a flag is what makes a stale confirmation
     * fail the comparison on its own.
     */
    public function testANewPhraseIsUnnotedEvenThoughTheOldOneWasNoted(): void
    {
        $this->passphrase->current();
        $this->passphrase->markNoted();

        $this->passphrase->regenerate();

        $this->assertSame(2, $this->passphrase->generation());
        $this->assertSame(1, $this->passphrase->confirmedGeneration(), 'the earlier statement was rewritten');
        $this->assertFalse(
            $this->passphrase->isNoted(),
            'a note taken for the first phrase counted for a second one it cannot open'
        );
    }

    /**
     * A confirmation of a generation that is no longer in force does not
     * count — which is the same fact as above, read from the row rather
     * than through a regeneration.
     */
    public function testAConfirmationLeftBehindByTheGenerationDoesNotCount(): void
    {
        $this->passphrase->current();
        $this->passphrase->regenerate();
        $this->passphrase->regenerate();
        $this->settings->values[RemotePassphrase::CONFIRMED_GENERATION_SETTING] = '2';

        $this->assertSame(3, $this->passphrase->generation());
        $this->assertFalse($this->passphrase->isNoted());
    }
}
