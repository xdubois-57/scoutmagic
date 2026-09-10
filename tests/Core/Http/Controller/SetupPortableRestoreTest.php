<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\File\ChunkedUploadStore;
use Core\Http\Controller\SetupController;
use Core\Http\Request;
use Core\Mail\DkimManager;
use Core\Maintenance\BackupService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\View\TwigFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * Who may reach the wizard's portable restore, and who may not.
 *
 * **This is the most dangerous pair of endpoints in the application**, and
 * saying so plainly is the point of this class. `POST /setup/restore-portable`
 * takes an uploaded file and replaces the database with it. It is reachable
 * without logging in — it has to be, because it exists for the moment when
 * there is no account to log into and no site to log into it with. What
 * stands in its place is the installation token, the same gate that guards
 * the wizard itself, plus the fact that the whole route block disappears the
 * instant the site is initialised.
 *
 * So the two refusals below are not paperwork. Each of them is the only
 * thing between a stranger who found `/setup` and a database of members'
 * addresses, dates of birth and telephone numbers.
 */
final class SetupPortableRestoreTest extends TestCase
{
    private const PASSPHRASE = 'quatre mots parfaitement ordinaires';
    private const ORIGIN_ID = 'aaaabbbbccccddddeeeeffff00001111';
    private const ORIGIN_ENCRYPTION_KEY = 'la-clef-de-colonne-de-l-origine=';

    private string $tempDir;

    /**
     * The install root the controller is pointed at.
     *
     * A real temporary directory with a `public/` inside, never the
     * repository: `SetupController` derives the install root from its
     * `publicDir`, and a restore run against the checkout would write the
     * archive's `storage/` over the working tree.
     */
    private string $installRoot;

    /** @var string[] archives and dumps to remove afterwards */
    private array $cleanupPaths = [];

    private SecretManager $secretManager;
    private DkimManager $dkimManager;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/setup_portable_test_' . uniqid();
        $this->installRoot = $this->tempDir . '/site';
        mkdir($this->installRoot . '/public', 0755, true);
        mkdir($this->installRoot . '/storage/keys', 0700, true);
        mkdir($this->installRoot . '/storage/config', 0700, true);

        $this->secretManager = new SecretManager(
            $this->installRoot . '/storage/keys/master.key',
            $this->installRoot . '/storage/config/secrets.enc'
        );
        $this->dkimManager = new DkimManager($this->installRoot . '/storage/keys');
        $this->twig = TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates', true);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];
        foreach ($this->cleanupPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->removeDirectory($this->tempDir);
    }

    private function controller(): SetupController
    {
        return new SetupController(
            $this->twig,
            $this->secretManager,
            $this->dkimManager,
            dirname(__DIR__, 4) . '/schema/core.sql',
            $this->installRoot . '/public'
        );
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path): array
    {
        $response = $this->controller()->{$method}(new Request('POST', $path, [], [], [], []), []);
        $decoded = json_decode($response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /**
     * **Without the installation token, nothing.**
     *
     * The token file is what proves whoever is at `/setup` has filesystem
     * access to the server — the only credential that exists before the
     * site does. Without it this endpoint would let anybody who reached an
     * uninstalled ScoutMagic upload a database and own the result.
     */
    public function testTheRestoreIsRefusedWithoutAVerifiedInstallationToken(): void
    {
        unset($_SESSION['setup_token_verified']);

        [$status, $body] = $this->call('restorePortable', '/setup/restore-portable');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('Jeton', (string) ($body['message'] ?? ''));
    }

    /**
     * And the upload endpoint too, which is the half people forget.
     *
     * Gating only the restore would leave a route that writes attacker-chosen
     * bytes to the server's disk, in chunks, to a path derived from an id
     * they choose — protected by nothing at all.
     */
    public function testTheChunkedUploadIsRefusedWithoutAVerifiedInstallationTokenEither(): void
    {
        unset($_SESSION['setup_token_verified']);

        [$status, $body] = $this->call('restorePortableChunk', '/setup/restore-portable-chunk');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
    }

    /**
     * **Once the site is configured, this way in closes.**
     *
     * From that moment a restore belongs to Configuration > Maintenance,
     * behind a login and a role. The wizard's token is long gone, and
     * `denyUnlessTokenVerified()` deliberately stops refusing anything —
     * so if this second check were missing, the endpoint would go from
     * token-gated to completely open on the day the site started working.
     */
    public function testTheRestoreIsRefusedOnceTheSiteIsConfigured(): void
    {
        $_SESSION['setup_token_verified'] = true;
        $this->initialiseTheSite();

        [$status, $body] = $this->call('restorePortable', '/setup/restore-portable');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
        $this->assertStringContainsString('déjà configuré', (string) ($body['message'] ?? ''));
    }

    public function testTheChunkedUploadIsRefusedOnceTheSiteIsConfiguredToo(): void
    {
        $_SESSION['setup_token_verified'] = true;
        $this->initialiseTheSite();

        [$status, $body] = $this->call('restorePortableChunk', '/setup/restore-portable-chunk');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
    }

    /**
     * With the token but without a valid CSRF token, still nothing — and
     * this is what says the order of the two gates is deliberate.
     */
    public function testAValidTokenStillNeedsAValidCsrfToken(): void
    {
        $_SESSION['setup_token_verified'] = true;

        [$status, $body] = $this->call('restorePortable', '/setup/restore-portable');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
        $this->assertStringNotContainsString('Jeton d\'installation', (string) ($body['message'] ?? ''));
    }

    /**
     * **The wizard's restore, end to end, on a real database.**
     *
     * The roadmap's headline case: « restauration sur une base vide depuis
     * l'assistant ». Everything real except the browser — the archive is
     * assembled through the same chunk store the page uploads to, the
     * request carries the credentials the operator just typed, and the
     * endpoint does the rest.
     *
     * What it proves beyond the parts already covered elsewhere is the
     * wiring: that the credentials in the REQUEST are the ones that
     * survive, that the schema is migrated afterwards, and that the
     * restored site stops answering with the identity of the one it came
     * from.
     */
    #[Group('database')]
    public function testTheWizardRestoresAnArchiveOntoAFreshInstallation(): void
    {
        $connection = $this->realDbConnection();
        $origin = $this->buildOriginArchive($connection);

        // **The state the wizard actually hands this endpoint**, which is
        // not the same as "an empty database": the operator has just
        // clicked « Installer la base de données », so the schema exists
        // and holds no data. Dropping the tables and stopping there would
        // model a state the interface can never produce — and an earlier
        // version of this test did exactly that, which is how a guard that
        // refused every real attempt passed its own suite.
        $this->emptyDatabase($connection->getPdo());
        $this->migrate($connection);

        $_SESSION['setup_token_verified'] = true;
        $uploadId = $this->assembleUpload($origin['zipPath']);

        $body = $this->targetCredentials() + [
            '_csrf_token' => $this->issueCsrfToken(),
            'upload_id' => $uploadId,
            'passphrase' => self::PASSPHRASE,
        ];

        $response = $this->controller()->restorePortable(
            new Request('POST', '/setup/restore-portable', [], $body, [], ['HTTP_HOST' => 'nouveau.example']),
            []
        );
        $decoded = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($decoded['success'] ?? false, (string) ($decoded['message'] ?? 'no message'));

        // The origin's data arrived.
        $this->assertFileExists($this->installRoot . '/storage/uploads/tresorerie.pdf');
        $this->assertSame(
            $origin['masterKey'],
            file_get_contents($this->installRoot . '/storage/keys/master.key')
        );

        // The credentials the operator typed survived the archive (D5).
        $restored = (new SecretManager(
            $this->installRoot . '/storage/keys/master.key',
            $this->installRoot . '/storage/config/secrets.enc'
        ))->readSecrets();
        // The request's own credentials, and emphatically not the archive's
        // `ancienne_base` — which is the whole of D5.
        $this->assertSame($this->targetCredentials()['db_name'], $restored['db_name'] ?? null);
        $this->assertSame($this->targetCredentials()['db_host'], $restored['db_host'] ?? null);
        $this->assertNotSame('ancienne_base', $restored['db_name'] ?? null);
        $this->assertNotSame('ancien-hebergeur.example', $restored['db_host'] ?? null);
        // And the origin's column key did arrive, or none of that matters.
        $this->assertSame(self::ORIGIN_ENCRYPTION_KEY, $restored['encryption_key'] ?? null);

        // And the restored site is a new installation, not a second copy of
        // the old one (D6).
        $identity = $this->readSetting($connection->getPdo(), InstallationIdentityService::INSTALLATION_ID_SETTING);
        $this->assertNotSame(self::ORIGIN_ID, $identity);
        $this->assertSame(
            self::ORIGIN_ID,
            $this->readSetting($connection->getPdo(), InstallationIdentityService::RESTORED_FROM_SETTING)
        );
    }

    /**
     * **The upload endpoint doing its job**, which nothing else here
     * covers: the refusal tests above stop at the gate, and the end-to-end
     * test reaches the chunk store directly rather than through HTTP.
     *
     * What is asserted is the contract the shared uploader depends on —
     * `public/assets/js/chunked-upload.js` sends `file`, `chunk_offset`
     * and `last`, and reads `received` back to know where it is. A server
     * that accepted fragments and reported the wrong total would produce a
     * client that resumes from the wrong place, which shows up as a
     * corrupt archive rather than as an upload error.
     */
    public function testAFragmentIsStoredAndTheTotalHeldIsReportedBack(): void
    {
        $_SESSION['setup_token_verified'] = true;
        $uploadId = bin2hex(random_bytes(16));
        $csrf = $this->issueCsrfToken();

        [$status, $body] = $this->postChunk($uploadId, $csrf, 'les premiers octets', 0, false);

        $this->assertSame(200, $status);
        $this->assertTrue($body['success'] ?? false);
        $this->assertSame(strlen('les premiers octets'), $body['received'] ?? null);

        [$status, $body] = $this->postChunk($uploadId, $csrf, ' et la suite', strlen('les premiers octets'), true);

        $this->assertSame(200, $status);
        $this->assertSame(strlen('les premiers octets et la suite'), $body['received'] ?? null);
    }

    /**
     * A fragment that does not continue where the file ends is refused
     * with the real size, not with a bare error.
     *
     * That number is the whole point of answering 409 rather than 400: it
     * is what lets an interrupted upload resume from the right offset
     * instead of starting a multi-hundred-megabyte archive again.
     */
    public function testAFragmentOutOfSequenceIsRefusedWithTheSizeActuallyHeld(): void
    {
        $_SESSION['setup_token_verified'] = true;
        $uploadId = bin2hex(random_bytes(16));
        $csrf = $this->issueCsrfToken();

        $this->postChunk($uploadId, $csrf, 'les premiers octets', 0, false);

        [$status, $body] = $this->postChunk($uploadId, $csrf, 'la suite, mais trop loin', 9_999, false);

        $this->assertSame(409, $status);
        $this->assertFalse($body['success'] ?? true);
        $this->assertSame(strlen('les premiers octets'), $body['received'] ?? null);
    }

    /**
     * And when even the identifier is unusable, the answer is still the
     * shape the uploader expects.
     *
     * Asking the store how much it holds fails for the same reason the
     * append did — there is no such upload — so `received` is zero rather
     * than the request dying on a second exception nobody catches.
     */
    public function testAnUnusableUploadIdentifierIsRefusedWithNothingReceived(): void
    {
        $_SESSION['setup_token_verified'] = true;

        [$status, $body] = $this->postChunk('pas-un-identifiant', $this->issueCsrfToken(), 'des octets', 0, false);

        $this->assertSame(409, $status);
        $this->assertFalse($body['success'] ?? true);
        $this->assertSame(0, $body['received'] ?? null);
    }

    /** A request with no file at all is a bad request, not a conflict. */
    public function testARequestCarryingNoFragmentIsRefused(): void
    {
        $_SESSION['setup_token_verified'] = true;
        $csrf = $this->issueCsrfToken();

        $response = $this->controller()->restorePortableChunk(
            new Request('POST', '/setup/restore-portable-chunk', [], [
                '_csrf_token' => $csrf,
                'upload_id' => bin2hex(random_bytes(16)),
            ], [], []),
            []
        );
        $body = json_decode($response->getBody(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertIsArray($body);
        $this->assertFalse($body['success'] ?? true);
    }

    /**
     * One fragment, through the endpoint, the way the browser sends it.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function postChunk(
        string $uploadId,
        string $csrf,
        string $contents,
        int $offset,
        bool $isLast
    ): array {
        $tmp = $this->tempDir . '/fragment_' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($tmp, $contents);
        $_FILES['file'] = [
            'name' => 'sauvegarde.zip',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
            'type' => 'application/zip',
        ];

        try {
            $response = $this->controller()->restorePortableChunk(
                new Request('POST', '/setup/restore-portable-chunk', [], [
                    '_csrf_token' => $csrf,
                    'upload_id' => $uploadId,
                    'chunk_offset' => (string) $offset,
                    'last' => $isLast ? '1' : '0',
                ], [], []),
                []
            );
        } finally {
            unset($_FILES['file']);
            @unlink($tmp);
        }

        $decoded = json_decode($response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /**
     * **A refusal leaves the wizard usable**, which is the half that is
     * easy to get wrong.
     *
     * A mistyped passphrase is the ordinary failure here, and it must cost
     * one retry, not the installation. What would make it cost the
     * installation is the archive's keys being left on disk: from that
     * moment `SecretManager::isInitialized()` answers true and the next
     * attempt is refused as "already configured" — on a site with no
     * database, no account and no way forward.
     */
    #[Group('database')]
    public function testAWrongPassphraseIsRefusedAndLeavesTheWizardAbleToTryAgain(): void
    {
        $connection = $this->realDbConnection();
        $origin = $this->buildOriginArchive($connection);
        $this->emptyDatabase($connection->getPdo());
        $this->migrate($connection);

        $_SESSION['setup_token_verified'] = true;
        $body = $this->targetCredentials() + [
            '_csrf_token' => $this->issueCsrfToken(),
            'upload_id' => $this->assembleUpload($origin['zipPath']),
            'passphrase' => 'une phrase tout à fait différente',
        ];

        $response = $this->controller()->restorePortable(
            new Request('POST', '/setup/restore-portable', [], $body, [], ['HTTP_HOST' => 'nouveau.example']),
            []
        );
        $decoded = json_decode($response->getBody(), true);

        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success'] ?? true);
        $this->assertStringContainsString('phrase de passe', (string) ($decoded['message'] ?? ''));

        // Nothing of the archive stayed behind — and in particular the
        // site is not now pretending to be configured.
        $this->assertFileDoesNotExist($this->installRoot . '/storage/keys/master.key');
        $this->assertFileDoesNotExist($this->installRoot . '/storage/uploads/tresorerie.pdf');
        $this->assertFalse($this->secretManager->isInitialized());
    }

    /**
     * **A database that belongs to somebody is refused**, even though the
     * wizard would never offer one.
     *
     * The interface only enables this button after « Installer la base de
     * données » has succeeded, so through the UI the credentials are the
     * ones just tested. But this endpoint takes them from the request:
     * nothing stops a second attempt from naming a different database, and
     * a dump laid over a live site's data is a merge nobody asked for.
     *
     * The guard asks whether anyone LIVES there, not whether tables exist
     * — the wizard's own install step has just created some forty of them.
     */
    #[Group('database')]
    public function testADatabaseThatAlreadyHoldsASiteIsRefused(): void
    {
        $connection = $this->realDbConnection();
        $origin = $this->buildOriginArchive($connection);
        $this->emptyDatabase($connection->getPdo());
        $this->migrate($connection);

        // One account is enough: somebody lives here.
        $connection->getPdo()
            ->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['enc', hash('sha256', 'setup-portable-occupied-' . uniqid())]);

        $_SESSION['setup_token_verified'] = true;
        $body = $this->targetCredentials() + [
            '_csrf_token' => $this->issueCsrfToken(),
            'upload_id' => $this->assembleUpload($origin['zipPath']),
            'passphrase' => self::PASSPHRASE,
        ];

        $response = $this->controller()->restorePortable(
            new Request('POST', '/setup/restore-portable', [], $body, [], ['HTTP_HOST' => 'nouveau.example']),
            []
        );
        $decoded = json_decode($response->getBody(), true);

        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success'] ?? true);
        $this->assertStringContainsString('contient déjà les données', (string) ($decoded['message'] ?? ''));
        $this->assertFileDoesNotExist($this->installRoot . '/storage/uploads/tresorerie.pdf');
    }

    /** What « Installer la base de données » does: the schema, and no data. */
    private function migrate(Connection $connection): void
    {
        (new MigrationRunner(
            $connection,
            new SchemaIntrospector($connection->getPdo()),
            new SchemaComparator(),
            new SqlParser()
        ))->migrate([dirname(__DIR__, 4) . '/schema/core.sql']);
    }

    /** Drops every table, foreign keys included. */
    private function emptyDatabase(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ((new SchemaIntrospector($pdo))->getTables() as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return array<string, string> */
    private function targetCredentials(): array
    {
        return [
            'db_host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'db_port' => getenv('TEST_DB_PORT') ?: '3306',
            'db_name' => getenv('TEST_DB_NAME') ?: 'test_db',
            'db_user' => getenv('TEST_DB_USER') ?: 'root',
            'db_password' => getenv('TEST_DB_PASSWORD') ?: '',
        ];
    }

    /**
     * A real portable archive, from an install root that is not the target.
     *
     * @return array{zipPath: string, masterKey: string}
     */
    private function buildOriginArchive(Connection $connection): array
    {
        $originBase = $this->tempDir . '/origin';
        $masterKey = random_bytes(32);

        foreach ([
            'core/App.php' => '<?php // origin',
            'public/index.php' => '<?php // origin',
            'storage/uploads/tresorerie.pdf' => 'les comptes de l\'unite',
        ] as $relative => $content) {
            @mkdir(dirname($originBase . '/' . $relative), 0755, true);
            file_put_contents($originBase . '/' . $relative, $content);
        }
        @mkdir($originBase . '/storage/keys', 0700, true);
        file_put_contents($originBase . '/storage/keys/master.key', $masterKey);
        (new SecretManager(
            $originBase . '/storage/keys/master.key',
            $originBase . '/storage/config/secrets.enc'
        ))->writeSecrets([
            'db_host' => 'ancien-hebergeur.example',
            'db_name' => 'ancienne_base',
            'encryption_key' => self::ORIGIN_ENCRYPTION_KEY,
        ]);

        $service = new BackupService($connection, $originBase . '/storage', $originBase);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }

        $this->seedSetting($connection->getPdo(), InstallationIdentityService::INSTALLATION_ID_SETTING, self::ORIGIN_ID);
        $this->seedSetting($connection->getPdo(), InstallationIdentityService::RESTORED_FROM_SETTING, '');

        $result = $service->createPortableBackup(self::PASSPHRASE, '0.0.1', self::ORIGIN_ID);
        $this->cleanupPaths[] = $result['dbDumpPath'];
        $this->cleanupPaths[] = $result['zipPath'];

        return ['zipPath' => $result['zipPath'], 'masterKey' => $masterKey];
    }

    /** Puts the archive through the same store the page uploads to. */
    private function assembleUpload(string $zipPath): string
    {
        $uploadId = bin2hex(random_bytes(16));
        $copy = $this->tempDir . '/chunk.bin';
        copy($zipPath, $copy);

        (new ChunkedUploadStore($this->installRoot . '/storage'))
            ->appendChunk($uploadId, session_id(), 0, $copy, true, 2 * 1024 * 1024 * 1024);

        return $uploadId;
    }

    private function issueCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_POST['_csrf_token'] = $token;

        return $token;
    }

    private function seedSetting(\PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$key]);
        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, setting_type, label, description) VALUES (?, ?, ?, ?, ?)'
        )->execute([$key, $value, 'text', $key, '']);
    }

    private function readSetting(\PDO $pdo, string $key): ?string
    {
        $statement = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function realDbConnection(): Connection
    {
        $credentials = $this->targetCredentials();
        $connection = new Connection(
            $credentials['db_host'],
            (int) $credentials['db_port'],
            $credentials['db_name'],
            $credentials['db_user'],
            $credentials['db_password']
        );
        $result = $connection->testConnection();
        if ($result !== true) {
            $this->markTestSkipped('Database not available: ' . (is_string($result) ? $result : 'unknown error'));
        }

        (new MigrationRunner(
            $connection,
            new SchemaIntrospector($connection->getPdo()),
            new SchemaComparator(),
            new SqlParser()
        ))->migrate([dirname(__DIR__, 4) . '/schema/core.sql']);

        return $connection;
    }

    /** The cheapest thing that makes SecretManager::isInitialized() true. */
    private function initialiseTheSite(): void
    {
        file_put_contents($this->installRoot . '/storage/keys/master.key', random_bytes(32));
        $this->secretManager->writeSecrets(['db_host' => 'localhost']);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
