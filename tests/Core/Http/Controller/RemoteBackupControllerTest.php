<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\RemoteBackupController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The two things about the off-site backup that are not a storage
 * location: the phrase, and where the archives go.
 *
 * **The OAuth round trip is not here any more.** It moved to
 * {@see \Tests\Core\Http\Controller\GoogleDriveConnectionControllerTest}
 * with the code, because a Drive folder is a storage location now and its
 * connection belongs beside the declaration of that location. What is
 * left is what genuinely belongs to the backup.
 *
 * `RemoteBackupRbacTest` covers who may reach these methods at all. What
 * is asserted here is what happens once they are reached.
 */
final class RemoteBackupControllerTest extends TestCase
{
    private string $base;
    private RemoteBackupSettingsDouble $settings;
    private RecordingJournalRepository $journal;
    private \Core\Maintenance\Remote\RemotePassphrase $passphrase;
    private SecretManager $secrets;
    private StorageLocationRepository $locations;
    private RemoteBackupDestination $destination;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];

        $this->base = sys_get_temp_dir() . '/remote_ctrl_' . uniqid();
        @mkdir($this->base . '/keys', 0700, true);
        @mkdir($this->base . '/config', 0700, true);
        $secrets = new SecretManager($this->base . '/keys/master.key', $this->base . '/config/secrets.enc');
        $secrets->generateMasterKey();
        $secrets->writeSecrets([]);
        $this->secrets = $secrets;

        $this->settings = new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example']);
        $this->passphrase = new \Core\Maintenance\Remote\RemotePassphrase($this->settings, $secrets);
        $this->locations = new StorageLocationRepository(
            DatabaseTestHelper::createTestDatabase(),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->destination = new RemoteBackupDestination(
            $this->settings,
            $this->locations,
            new StorageBackendFactory($this->locations, sys_get_temp_dir())
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        foreach (['/keys/master.key', '/config/secrets.enc'] as $file) {
            @unlink($this->base . $file);
        }
        @rmdir($this->base . '/keys');
        @rmdir($this->base . '/config');
        @rmdir($this->base);
    }

    // ————— The destination —————

    /**
     * **D4, spelled out.** Locations are declared centrally and chosen
     * locally; the choice lives in this consumer's own setting, and there
     * is deliberately no join table in the middle.
     */
    public function testChoosingADestinationRecordsItAndSaysWhere(): void
    {
        $id = $this->declareDrive();

        $response = $this->controller()->chooseDestination(
            $this->postRequest(['location_id' => (string) $id]),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($id, $this->destination->locationId());
        $this->assertStringContainsString(
            'Google Drive',
            $this->journal->textOf('remote_backup_destination_chosen')
        );
    }

    /**
     * **A destination that cannot resume an interrupted upload is refused
     * at the moment it is chosen**, not discovered at four in the
     * morning. An archive of several gibibytes over a domestic upstream
     * link does not finish in one run on any hosting this application
     * exists for, and the send would meet the same refusal nightly with
     * nobody reading the journal.
     */
    public function testADestinationThatCannotResumeAnUploadIsRefused(): void
    {
        // An object store: the one declared type that does not carry the
        // capability today. Keyed on the capability rather than on the
        // type, so this follows a backend that gains it.
        $id = $this->locations->create(
            StorageLocationType::ObjectStorage,
            'Bucket',
            new \Core\Storage\Location\Config\ObjectStorageLocationConfig(
                'https://s3.example',
                'eu',
                'seau',
                'cle'
            ),
            null
        );

        $this->controller()->chooseDestination($this->postRequest(['location_id' => (string) $id]), []);

        $this->assertSame(0, $this->destination->locationId());
    }

    /** A location that has gone is a sentence, not a stack trace. */
    public function testChoosingALocationThatNoLongerExistsIsRefused(): void
    {
        $this->controller()->chooseDestination($this->postRequest(['location_id' => '9999']), []);

        $this->assertSame(0, $this->destination->locationId());
    }

    /**
     * Choosing none is a legitimate answer — and a loud one: it means
     * nothing leaves this server, which is worth a security entry and a
     * warning rather than a quiet success.
     */
    public function testChoosingNoneStopsTheSendsAndSaysSo(): void
    {
        $this->destination->choose($this->declareDrive());

        $this->controller()->chooseDestination($this->postRequest(['location_id' => '0']), []);

        $this->assertSame(0, $this->destination->locationId());
        $this->assertNotSame('', $this->journal->textOf('remote_backup_destination_cleared'));
    }

    /** And none of it happens without the token. */
    public function testChoosingADestinationNeedsTheCsrfToken(): void
    {
        $id = $this->declareDrive();
        $_POST = [];

        $this->controller()->chooseDestination(
            new Request('POST', '/config/maintenance/remote/destination', [], ['location_id' => (string) $id], [], []),
            []
        );

        $this->assertSame(0, $this->destination->locationId());
    }

    // ————— The passphrase —————

    /**
     * **Shown on demand, and that is the deliberate departure.** The
     * webhook secret is shown once and never again; this phrase opens
     * archives that already exist on a service this site may not be
     * around to reach, and it has to live in `secrets.enc` anyway so the
     * scheduled send can encrypt without a human. Hiding it from the
     * administrator protects nothing and guarantees that one day nobody
     * can open a year of uploads.
     */
    public function testThePhraseCanBeRevealedAndIsCreatedOnFirstAsking(): void
    {
        $this->connectSite();

        $response = $this->controller()->revealPassphrase($this->jsonRequest(), []);
        $payload = json_decode($response->getBody(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame(
            $this->passphrase->stored(),
            $payload['passphrase'],
            'the screen was shown a phrase other than the one the sends encrypt with'
        );
        $this->assertNotSame('', $payload['passphrase']);
    }

    /**
     * Reading it is reading the key to every archive off this server, so
     * it leaves a security entry — without the phrase in it, which would
     * put the key in a log read on screen and carried in the support
     * archive.
     */
    public function testRevealingThePhraseIsJournaledWithoutThePhrase(): void
    {
        $this->connectSite();

        $response = $this->controller()->revealPassphrase($this->jsonRequest(), []);
        $phrase = json_decode($response->getBody(), true)['passphrase'];

        $recorded = $this->journal->textOf('remote_backup_passphrase_revealed');
        $this->assertNotSame('', $recorded, 'nothing recorded that somebody read the key to every remote archive');
        $this->assertStringNotContainsString($phrase, $recorded, 'the journal now carries the phrase itself');
    }

    /** No token, no phrase: it cannot be pulled out by a link. */
    public function testRevealingThePhraseNeedsTheCsrfToken(): void
    {
        $this->connectSite();
        // The superglobal cleared as well as the body: `isCsrfValid()`
        // accepts the token from either, so a test that only emptied the
        // body would prove nothing about a request that carries neither.
        unset($_POST['_csrf_token']);

        $request = new Request('POST', '/config/maintenance/remote/passphrase/reveal', [], [], [], []);
        $response = $this->controller()->revealPassphrase($request, []);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(
            '',
            $this->passphrase->stored(),
            'a refused request still created — and therefore could still have leaked — a phrase'
        );
    }

    /**
     * Regenerating draws a genuinely different phrase and moves the
     * generation on, which is what keeps the remote folder legible: the
     * number is in every file name, so an operator holding two phrases
     * can tell which opens which.
     */
    public function testRegeneratingChangesThePhraseAndAdvancesTheGeneration(): void
    {
        $this->connectSite();
        $first = $this->passphrase->current();
        $firstGeneration = $this->passphrase->generation();

        $this->controller()->regeneratePassphrase($this->postRequest([]), []);

        $this->assertNotSame($first, $this->passphrase->stored());
        $this->assertSame($firstGeneration + 1, $this->passphrase->generation());
    }

    /** And it is journaled with both numbers, never with either phrase. */
    public function testRegeneratingIsJournaledWithBothGenerationsAndNoPhrase(): void
    {
        $this->connectSite();
        $first = $this->passphrase->current();

        $this->controller()->regeneratePassphrase($this->postRequest([]), []);

        $recorded = $this->journal->textOf('remote_backup_passphrase_regenerated');
        $this->assertStringContainsString('"previous_generation":1', $recorded);
        $this->assertStringContainsString('"generation":2', $recorded);
        $this->assertStringNotContainsString($first, $recorded);
        $this->assertStringNotContainsString($this->passphrase->stored(), $recorded);
    }

    /** Without the token, nothing is drawn — the old archives stay open. */
    public function testRegeneratingNeedsTheCsrfToken(): void
    {
        $this->connectSite();
        $first = $this->passphrase->current();
        unset($_POST['_csrf_token']);

        $request = new Request('POST', '/config/maintenance/remote/passphrase/regenerate', [], [], [], []);
        $this->controller()->regeneratePassphrase($request, []);

        $this->assertSame($first, $this->passphrase->stored(), 'a request with no token still burned the phrase');
    }

    // ————— « Je l'ai recopiée hors du serveur » (#496) —————

    /**
     * The statement is recorded, and stamped with the generation it was
     * made for.
     */
    public function testConfirmingRecordsTheGenerationAndIsJournaled(): void
    {
        $this->connectSite();
        $this->passphrase->current();

        $response = $this->controller()->confirmPassphraseNoted($this->postRequest([]), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($this->passphrase->isNoted());
        $this->assertStringContainsString('"generation":1', $this->journal->textOf('remote_backup_passphrase_noted'));
    }

    /**
     * **Revealing the phrase is not copying it**, so the reveal route
     * must not clear the warning. An administrator who opened the screen
     * to read a generation number has seen thirty characters and written
     * none of them down — and clearing it there would silence the alert
     * for exactly the reader it is addressed to.
     */
    public function testRevealingThePhraseDoesNotCountAsNotingIt(): void
    {
        $this->connectSite();
        $this->passphrase->current();

        $this->controller()->revealPassphrase($this->postRequest([]), []);

        $this->assertFalse($this->passphrase->isNoted(), 'opening the screen counted as copying the phrase');
    }

    /** Without the token, nothing is recorded. */
    public function testConfirmingNeedsTheCsrfToken(): void
    {
        $this->connectSite();
        $this->passphrase->current();
        unset($_POST['_csrf_token']);

        $request = new Request('POST', '/config/maintenance/remote/passphrase/confirm', [], [], [], []);
        $this->controller()->confirmPassphraseNoted($request, []);

        $this->assertFalse($this->passphrase->isNoted());
    }

    /**
     * A site with no phrase yet is told so rather than left with a
     * confirmation that would satisfy the first real phrase silently.
     */
    public function testConfirmingBeforeAnyPhraseExistsRecordsNothing(): void
    {
        $this->connectSite();

        $this->controller()->confirmPassphraseNoted($this->postRequest([]), []);

        $this->assertSame(0, $this->passphrase->confirmedGeneration());
        $this->assertFalse($this->passphrase->isNoted());
    }

    private function connectSite(): void
    {
        $this->destination->choose($this->declareDrive());
    }

    /** A declared Google Drive location, which is what a destination is. */
    private function declareDrive(): int
    {
        return $this->locations->create(
            StorageLocationType::GoogleDrive,
            'Google Drive',
            new GoogleDriveLocationConfig('client-1', 'dossier-1', '2026-03-01T00:00:00+00:00'),
            (string) json_encode([
                'client_secret' => 's',
                'refresh_token' => 'refresh-abc',
                'account' => 'unite@example.org',
            ])
        );
    }

    private function controller(): RemoteBackupController
    {
        $this->journal = new RecordingJournalRepository();

        return new RemoteBackupController(
            new Environment(new ArrayLoader([])),
            $this->destination,
            $this->locations,
            new JournalService($this->journal),
            $this->passphrase
        );
    }

    /**
     * **The screen asks before the archives stop leaving the server.**
     *
     * `chooseDestination` accepts `0` — « Aucune » — and the controller's
     * own flash reports the loss AFTER it has happened, which is the one
     * thing a warning cannot undo. Nothing else on this page announces
     * itself either: no archive fails, no alert fires, and the site looks
     * exactly as it did until the day somebody needs a copy that was
     * never sent.
     *
     * Asserted on the form's opening tag rather than on the file, so that
     * the attribute is shown to sit UNDER the `remote_backup_location`
     * guard: the confirmation belongs to a site that has a destination to
     * lose, and a fresh site — where this form is the way to set the first
     * one up — must not meet a modal in front of it.
     */
    public function testTheDestinationFormAsksBeforeItCanStopEveryOffsiteBackup(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 4) . '/core/View/templates/config/maintenance.html.twig'
        );
        $this->assertIsString($template, 'The maintenance template is unreadable.');

        $at = strpos($template, '<form method="post" action="/config/maintenance/remote/destination"');
        $this->assertIsInt($at, 'The destination form is no longer on the maintenance page.');

        $openingTag = substr($template, $at, (int) strpos($template, '>', $at) - $at);

        $this->assertStringContainsString(
            'data-confirm',
            $openingTag,
            'Choosing « Aucune » stops every off-site backup without asking: the form carries no confirmation.'
        );
        $this->assertStringContainsString(
            '{% if remote_backup_location %}',
            $openingTag,
            'The confirmation is unconditional, so a site with no destination yet is asked to confirm '
            . 'setting its first one up — which teaches operators to click past this dialog.'
        );
    }

    /** @param array<string, string> $body */
    private function postRequest(array $body): Request
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return new Request('POST', '/config/maintenance/remote/x', [], $body + ['_csrf_token' => $token], [], []);
    }

    private function jsonRequest(): Request
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return new Request('POST', '/config/maintenance/remote/test', [], ['_csrf_token' => $token], [], []);
    }

}
