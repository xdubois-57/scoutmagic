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
 * raccordement belongs beside the declaration of that location. What is
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

/** The settings table, in an array. */
final class RemoteBackupSettingsDouble extends SettingService
{
    /** @param array<string, string> $values */
    public function __construct(public array $values = [])
    {
        parent::__construct(new \Core\Config\SettingRepository(new \PDO('sqlite::memory:')));
    }

    public function get(string $key, ?string $moduleId = null, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function setInternal(string $key, string $value, ?string $moduleId = null): void
    {
        $this->values[$key] = $value;
    }
}

/**
 * A journal that writes to an array.
 *
 * **It used to write nowhere, and that was a hole.** The controller
 * journals a security event on every outcome, and one rule about those
 * entries is a rule about personal data: the connected Google account is
 * the e-mail address of a real person, so it belongs in `secrets.enc`
 * and nowhere near a journal line that is read on screen and carried
 * into a diagnostic archive. A repository that discarded its arguments
 * asserted that rule by never looking — so the entries are kept, and the
 * test reads them.
 */
final class RecordingJournalRepository extends JournalRepository
{
    /** @var list<array{type: string, description: string, context: string}> */
    public array $entries = [];

    public function __construct()
    {
        parent::__construct(new \PDO('sqlite::memory:'));
    }

    public function insert(
        string $category,
        string $type,
        string $level,
        string $description,
        ?string $contextJson,
        ?int $userId,
        ?string $ipAddress = null
    ): void {
        $this->entries[] = ['type' => $type, 'description' => $description, 'context' => (string) $contextJson];
    }

    /** Everything one entry could show a human, in one string. */
    public function textOf(string $type): string
    {
        $text = '';
        foreach ($this->entries as $entry) {
            if ($entry['type'] === $type) {
                $text .= $entry['description'] . ' ' . $entry['context'];
            }
        }

        return $text;
    }
}
