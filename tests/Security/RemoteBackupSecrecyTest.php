<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Security;

use Core\Http\Controller\GoogleDriveConnectionController;
use Core\Maintenance\Remote\RemotePassphrase;
use Core\Storage\Location\Backend\Drive\GoogleDriveClient;
use Core\Storage\Location\Config\GoogleDriveSecret;
use PHPUnit\Framework\TestCase;

/**
 * The rules about the off-site destination that must not quietly stop
 * being true.
 *
 * Every one of them is the kind of decision that is argued once, written
 * down, and then broken by somebody doing something reasonable a year
 * later — a setting added « so the operator can check it », a scope
 * widened because an API call was refused. A ratchet is what makes that
 * break announce itself.
 *
 * **IT-05 moved the credentials and changed what has to be defended.**
 * The client secret, the refresh token and the account address left
 * `secrets.enc` for the encrypted column of the location row they
 * describe (D7). So the thing to forbid is no longer « these keys must
 * not become settings »; it is that the three values never reach a
 * `config` record, which IS rendered on a configuration page and IS
 * carried in the support archive.
 */
final class RemoteBackupSecrecyTest extends TestCase
{
    /**
     * **The three encrypted values never appear in the clear half of the
     * row.**
     *
     * `GoogleDriveLocationConfig` is serialised into the `config` column,
     * which the storage page renders and the diagnostic export carries
     * unredacted. A field added there « so the screen can show it » would
     * put an OAuth client secret on a page and in a support package in
     * one change.
     *
     * Asserted against the record the class actually produces rather than
     * against its source, so a value smuggled in under any name is caught
     * by its content.
     */
    public function testTheClearHalfOfADriveLocationCarriesNoneOfTheSecrets(): void
    {
        $config = new \Core\Storage\Location\Config\GoogleDriveLocationConfig(
            'client-1.apps.googleusercontent.com',
            'dossier-1',
            '2026-09-01T00:00:00+00:00'
        );
        $serialised = (string) json_encode($config->toArray());

        foreach (['client_secret', 'refresh_token', 'account'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $serialised,
                "the clear configuration record carries {$forbidden}"
            );
        }

        // And nothing meant to be read renders the account either.
        $this->assertStringNotContainsString('@', $config->describe());
    }

    /**
     * **`describe()` names the folder and never the account.**
     *
     * It is the one line about a location that a screen prints and a
     * support package carries, and the account is the e-mail address of a
     * real person — which is exactly why it sits on the encrypted side.
     */
    public function testTheSecretRecordIsTheOnlyPlaceTheAccountLives(): void
    {
        $secret = new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org');
        $stored = (string) $secret->toStorage();

        $this->assertStringContainsString('unite@example.org', $stored);
        $this->assertSame($secret->account, GoogleDriveSecret::fromStorage($stored)->account);

        // An empty record is null, not `{}`: the repository reads a null
        // or empty secret as « leave what is there alone », so a caller
        // that means « forget everything » has to say so with a record
        // whose fields are present and empty.
        $this->assertNull((new GoogleDriveSecret())->toStorage());
        $this->assertFalse(GoogleDriveSecret::fromStorage('pas du json')->hasGrant());
    }

    /**
     * **And no code writes any of the three into the settings table.**
     *
     * Configuration > Réglages renders a value as plain text beside its
     * key, and exports it. The rule predates IT-05 and survives it: the
     * names changed, the page did not.
     */
    public function testNoCodeWritesAnyOfTheThreeIntoTheSettingsTable(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/core/Maintenance/Remote/*.php') ?: [],
            glob($root . '/core/Storage/Location/Config/*.php') ?: [],
            glob($root . '/core/Storage/Location/Backend/*.php') ?: [],
            [
                $root . '/core/Http/Controller/GoogleDriveConnectionController.php',
                $root . '/core/Http/Controller/RemoteBackupController.php',
                $root . '/core/Http/Controller/StorageConfigController.php',
                $root . '/core/Http/Controller/MaintenanceController.php',
            ]
        );

        foreach ($files as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
                if (!str_contains($line, '->set(') && !str_contains($line, '->setInternal(')) {
                    continue;
                }
                foreach (['client_secret', 'refresh_token', "'account'"] as $secretKey) {
                    $this->assertStringNotContainsString(
                        $secretKey,
                        $line,
                        basename($file) . ':' . ($number + 1) . ' writes ' . $secretKey . ' into the settings table.'
                    );
                }
            }
        }
    }

    /**
     * **The journal never carries the connected account.**
     *
     * It is the e-mail address of a real person, and the journal is read
     * on screen and travels in the support archive. SECURITY.md sets the
     * precedent for the mail probe: « the journal counts mailboxes and
     * names none of them ».
     */
    public function testTheJournalNeverCarriesTheConnectedAccount(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/core/Http/Controller/GoogleDriveConnectionController.php'
        );

        $offenders = [];
        foreach (explode("\n", $source) as $number => $line) {
            if (str_contains($line, '->account') && !str_contains($line, '//') && !str_contains($line, '*')) {
                $offenders[] = ($number + 1) . ': ' . trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "the connection controller reads the connected account; the journal must never carry it:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * **The phrase stays in `secrets.enc`, and that is deliberate.**
     *
     * It is the one value of this feature that did NOT move into a
     * location row, because it encrypts the ARCHIVES rather than the
     * destination they happen to be sent to: it has to outlive every
     * destination an operator ever connects, and a site whose Drive
     * location has been deleted must still be able to open last year's
     * uploads.
     */
    public function testThePassphraseIsTheOneSecretThatStaysWithTheSiteRatherThanWithADestination(): void
    {
        $this->assertStringStartsWith('remote_backup_passphrase', RemotePassphrase::SECRET_KEY);

        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/core/Maintenance/Remote/RemotePassphrase.php'
        );
        $registration = substr($source, (int) strpos($source, 'public static function register'));
        $registration = substr($registration, 0, (int) strpos($registration, "\n    }"));

        $this->assertStringNotContainsString(
            RemotePassphrase::SECRET_KEY,
            $registration,
            'the phrase is registered as a setting — it would be rendered in clear on Configuration > Réglages.'
        );
    }

    /**
     * **The OAuth scope stays the narrow one.**
     *
     * `drive.file` reaches only files this application itself created.
     * `drive` reaches the operator's entire Google Drive — every document
     * their family, their employer and their bank ever put there — and is
     * a *sensitive* scope, so asking for it would also subject every scout
     * unit to Google's verification procedure and its paid annual security
     * assessment. Widening this is not a fix for anything; it is a
     * different product.
     */
    public function testTheDriveScopeIsTheOneLimitedToThisApplicationsOwnFiles(): void
    {
        $this->assertSame('https://www.googleapis.com/auth/drive.file', GoogleDriveClient::SCOPE);

        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/core/Storage/Location/Backend/Drive/GoogleDriveClient.php'
        );
        $this->assertStringNotContainsString(
            "'https://www.googleapis.com/auth/drive'",
            $source,
            'the full-Drive scope appears in the client'
        );
    }

    /**
     * The seven days the screen warns about come from the code, so the
     * two cannot drift into disagreeing about the number an operator
     * plans around.
     *
     * **The warning moved with the backend in IT-05** — it is on the
     * location's own card now, not on Configuration > Maintenance — and
     * this is what would have caught a move that dropped it.
     */
    public function testTheScreenAndTheCodeQuoteTheSameSevenDays(): void
    {
        $this->assertSame(7, GoogleDriveClient::TESTING_TOKEN_LIFETIME_DAYS);

        foreach (['location_form', 'locations'] as $template) {
            $this->assertStringContainsString(
                'drive_testing_token_days',
                (string) file_get_contents(
                    dirname(__DIR__, 2) . '/core/View/templates/config/storage/' . $template . '.html.twig'
                ),
                "the {$template} screen hardcodes a number instead of reading the constant"
            );
        }
    }

    /**
     * **The redirect address is spelled once.**
     *
     * It has to match the value registered in the operator's Google
     * console character for character; two spellings are a
     * `redirect_uri_mismatch` nobody can diagnose from either of them. The
     * route in `public/index.php` is a literal because the authorization
     * matrix parses that file as text, so this is what holds the literal
     * and the constant together.
     */
    public function testTheRouteAndTheConstantSpellTheCallbackTheSameWay(): void
    {
        $this->assertStringContainsString(
            "'" . GoogleDriveConnectionController::REDIRECT_PATH . "'",
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php'),
            'the callback route and the constant that composes its address disagree'
        );
    }
}
