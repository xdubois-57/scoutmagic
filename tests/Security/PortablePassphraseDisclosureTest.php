<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * What the site says about the portable passphrase, checked against what it
 * does with it.
 *
 * The passphrase cannot be stored nowhere, and the reason is structural
 * rather than accidental: the archive is built in the background, after the
 * operator has left the page, so something has to carry the phrase from the
 * form to the handler. An encrypted copy rides in the backup task's payload
 * and lives as long as that row.
 *
 * **This class exists because the three places that say so drifted apart
 * once already.** A review found the screen promising the phrase was stored
 * nowhere; the screen and the help topic were corrected, and `SECURITY.md`
 * — the document this repository treats as authoritative for exactly this
 * trade-off — was left behind, still claiming "stored nowhere" and, worse,
 * still asserting that the screen claimed it too. A security document that
 * describes a stronger guarantee than the code provides is not a stale
 * comment; it is the thing an operator reads before deciding where to put
 * an archive containing the site's master key.
 *
 * So the guard is keyed on what the controller DOES. Delete the persistence
 * and the first test fails, which is the signal to relax the prose. Delete
 * the prose while the persistence stands and the rest fail. Neither half
 * can move alone.
 */
final class PortablePassphraseDisclosureTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Source with comments and docblocks removed.
     *
     * Every textual assertion below runs on stripped source, because a
     * comment that mentions `encrypted_password` would otherwise satisfy a
     * test about code that stores it — and a docblock describing a
     * behaviour is the exact thing this class refuses to accept as
     * evidence.
     */
    private static function strippedSource(string $relativePath): string
    {
        $source = file_get_contents(self::root() . '/' . $relativePath);
        self::assertIsString($source, $relativePath . ' is unreadable.');

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * The premise: the passphrase really is persisted, encrypted.
     *
     * If this ever stops being true the documentation below is free to
     * make the stronger claim — but not before, and this is what says so.
     */
    public function testTheControllerPersistsAnEncryptedCopyOfThePassphrase(): void
    {
        $controller = self::strippedSource('core/Http/Controller/MaintenanceController.php');

        $this->assertStringContainsString(
            'encrypted_password',
            $controller,
            'MaintenanceController no longer writes an encrypted passphrase into a scheduled action. If the '
            . 'passphrase is genuinely no longer persisted, this test and the SECURITY.md wording it guards '
            . 'should be revisited together.'
        );

        $handler = self::strippedSource('core/Maintenance/Task/CreateBackupHandler.php');
        $this->assertStringContainsString(
            'encrypted_password',
            $handler,
            'CreateBackupHandler no longer reads the encrypted passphrase back out of the task payload.'
        );
    }

    /**
     * `SECURITY.md` must not promise more than the code delivers.
     *
     * The phrase this looks for is the one that was actually there, and the
     * one a future edit is most likely to reach for again.
     */
    public function testSecurityMdDoesNotClaimThePassphraseIsStoredNowhere(): void
    {
        $security = file_get_contents(self::root() . '/SECURITY.md');
        $this->assertIsString($security, 'SECURITY.md is unreadable.');

        $this->assertStringNotContainsString(
            'stored nowhere. An archive whose passphrase is lost',
            $security,
            'SECURITY.md claims the portable passphrase is stored nowhere, while MaintenanceController writes '
            . 'an encrypted copy into the backup task payload.'
        );
        $this->assertStringNotContainsString(
            'the screen says the phrase is stored nowhere',
            $security,
            'SECURITY.md asserts the Maintenance screen makes a claim it no longer makes.'
        );
    }

    /**
     * And it must state the caveat, not merely avoid the false sentence.
     *
     * Dropping the wrong claim and saying nothing in its place would pass a
     * test that only forbade the old words, and would leave the operator no
     * better informed than the lie did.
     */
    public function testSecurityMdStatesWhereTheEncryptedCopyLivesAndForHowLong(): void
    {
        $security = file_get_contents(self::root() . '/SECURITY.md');
        $this->assertIsString($security, 'SECURITY.md is unreadable.');

        foreach (['never stored in cleartext', 'scheduled_actions.payload', 'CreateBackupHandler'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $security,
                'SECURITY.md no longer says where the encrypted passphrase lives (missing: ' . $needle . ').'
            );
        }
    }

    /**
     * The screen and the help topic, which are what an operator actually
     * reads, must carry the same caveat in French.
     *
     * These two were corrected first; the assertion is here so that the
     * next correction cannot fix one document and forget the other, which
     * is precisely how this drift started.
     */
    public function testTheScreenAndTheHelpTopicBothCarryTheCaveat(): void
    {
        $sentence = 'jamais enregistrée en clair';

        foreach ([
            'core/View/templates/config/maintenance.html.twig',
            'docs/help/sauvegarde-portable.md',
        ] as $path) {
            $contents = file_get_contents(self::root() . '/' . $path);
            $this->assertIsString($contents, $path . ' is unreadable.');
            $this->assertStringContainsString(
                $sentence,
                $contents,
                $path . ' no longer tells the operator that the passphrase is never kept in cleartext.'
            );
        }
    }
}
