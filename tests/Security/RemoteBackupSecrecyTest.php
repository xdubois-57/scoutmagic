<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Security;

use Core\Maintenance\Remote\GoogleDriveClient;
use Core\Maintenance\Remote\RemoteBackupConnection;
use PHPUnit\Framework\TestCase;

/**
 * Two rules about the off-site destination that must not quietly stop
 * being true.
 *
 * Both are the kind of decision that is argued once, written down, and
 * then broken by somebody doing something reasonable a year later — a
 * setting added « so the operator can check it », a scope widened because
 * an API call was refused. A ratchet is what makes that break announce
 * itself.
 */
final class RemoteBackupSecrecyTest extends TestCase
{
    /**
     * **The credentials never become a `settings` row.**
     *
     * Configuration > Settings renders a value as plain text beside its
     * key. A refresh token there is a credential on a screen, and one that
     * travels into the support archive besides. Both live in `secrets.enc`
     * instead, encrypted with the site's master key, beside the SMTP
     * password and the VAPID private key.
     *
     * Asserted against the registration method rather than against a
     * database, so it holds on a machine with no server: what is being
     * forbidden is the DECLARATION, which is the thing a future change
     * would add.
     */
    public function testNeitherSecretIsEverDeclaredAsASetting(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/core/Maintenance/Remote/RemoteBackupConnection.php'
        );
        $registration = substr($source, (int) strpos($source, 'public static function register'));
        $registration = substr($registration, 0, (int) strpos($registration, "\n    }"));

        foreach (RemoteBackupConnection::SECRET_KEYS as $secretKey) {
            $this->assertStringNotContainsString(
                $secretKey,
                $registration,
                "{$secretKey} is registered as a setting — it would be rendered in clear on Configuration > Settings."
            );
        }
    }

    /**
     * **And the journal names none of them either — the account included.**
     *
     * It is the e-mail address of a real person, and the journal is read
     * on screen and travels in the support archive. SECURITY.md sets the
     * precedent for the mail probe: « the journal counts mailboxes and
     * names none of them ».
     */
    public function testTheJournalNeverCarriesTheConnectedAccount(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/core/Http/Controller/RemoteBackupController.php'
        );

        $offenders = [];
        foreach (explode("\n", $source) as $number => $line) {
            if (str_contains($line, 'account()') && !str_contains($line, '//')) {
                $offenders[] = ($number + 1) . ': ' . trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "RemoteBackupController reads the connected account; the journal must never carry it:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * And nowhere else either: a `setInternal()` naming one of them would
     * create the row the check above forbids declaring.
     */
    public function testNoCodeWritesEitherSecretIntoTheSettingsTable(): void
    {
        $files = array_merge(
            glob(dirname(__DIR__, 2) . '/core/Maintenance/Remote/*.php') ?: [],
            [dirname(__DIR__, 2) . '/core/Http/Controller/RemoteBackupController.php'],
            [dirname(__DIR__, 2) . '/core/Http/Controller/MaintenanceController.php']
        );

        foreach ($files as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
                if (!str_contains($line, '->set(') && !str_contains($line, '->setInternal(')) {
                    continue;
                }
                foreach (RemoteBackupConnection::SECRET_KEYS as $secretKey) {
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
            dirname(__DIR__, 2) . '/core/Maintenance/Remote/GoogleDriveClient.php'
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
     */
    public function testTheScreenAndTheCodeQuoteTheSameSevenDays(): void
    {
        $this->assertSame(7, GoogleDriveClient::TESTING_TOKEN_LIFETIME_DAYS);

        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/core/View/templates/config/maintenance.html.twig'
        );
        $this->assertStringContainsString(
            'remote_backup_testing_token_days',
            $template,
            'the warning hardcodes a number instead of reading the constant'
        );
    }
}
