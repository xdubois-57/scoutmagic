<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Mail\DkimManager;
use Core\Mail\DnsVerifier;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Twig\Environment;

class SetupController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SecretManager $secretManager,
        private DkimManager $dkimManager,
        private string $schemaPath
    ) {
    }

    /**
     * GET /setup — render the setup form.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $isInitialized = $this->secretManager->isInitialized();
        $currentValues = [];

        if ($isInitialized) {
            $secrets = $this->secretManager->readSecrets();
            $currentValues = [
                'db_host' => $secrets['db_host'] ?? 'localhost',
                'db_port' => $secrets['db_port'] ?? 3306,
                'db_name' => $secrets['db_name'] ?? '',
                'db_user' => $secrets['db_user'] ?? '',
                'db_password' => '',
                'site_name' => $secrets['site_name'] ?? '',
                'short_name' => $secrets['short_name'] ?? '',
                'base_url' => $secrets['base_url'] ?? '',
                'mail_mode' => $secrets['mail_mode'] ?? 'smtp',
                'smtp_host' => $secrets['smtp_host'] ?? '',
                'smtp_port' => $secrets['smtp_port'] ?? 587,
                'smtp_user' => $secrets['smtp_user'] ?? '',
                'smtp_password' => '',
                'mail_from_address' => $secrets['mail_from_address'] ?? '',
                'mail_from_name' => $secrets['mail_from_name'] ?? '',
                'dkim_selector' => $secrets['dkim_selector'] ?? '',
                'dmarc_report_email' => $secrets['dmarc_report_email'] ?? '',
            ];
        }

        $csrfToken = CsrfGuard::generateToken();

        return $this->render('setup/index.html.twig', [
            'is_initialized' => $isInitialized,
            'values' => $currentValues,
            'errors' => [],
            'csrf_token' => $csrfToken,
            'has_dkim_key' => $this->dkimManager->hasKey(),
            'dkim_public_key' => $this->dkimManager->hasKey() ? $this->dkimManager->getPublicKey() : null,
        ]);
    }

    /**
     * POST /setup/test-db — AJAX: test database connection.
     *
     * @param array<string, string> $params
     */
    public function testDatabase(Request $request, array $params): Response
    {
        $host = (string) $request->getBody('db_host', 'localhost');
        $port = (int) $request->getBody('db_port', 3306);
        $dbName = (string) $request->getBody('db_name', '');
        $user = (string) $request->getBody('db_user', '');
        $password = (string) $request->getBody('db_password', '');

        $connection = new Connection($host, $port, $dbName, $user, $password);
        $result = $connection->testConnection();

        if ($result === true) {
            return $this->json(['success' => true, 'message' => 'Connexion réussie']);
        }

        return $this->json(['success' => false, 'message' => $result]);
    }

    /**
     * POST /setup/save — process the form.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        // Validate CSRF token
        $csrfToken = (string) $request->getBody('_csrf_token', '');
        if (!CsrfGuard::validateToken($csrfToken)) {
            return (new Response('', 403))->setBody('Forbidden: invalid CSRF token.');
        }

        // Collect and validate form data
        $data = $this->collectFormData($request);
        $errors = $this->validateFormData($data, !$this->secretManager->isInitialized());

        if (!empty($errors)) {
            $csrfToken = CsrfGuard::generateToken();
            return $this->render('setup/index.html.twig', [
                'is_initialized' => $this->secretManager->isInitialized(),
                'values' => $data,
                'errors' => $errors,
                'csrf_token' => $csrfToken,
                'has_dkim_key' => $this->dkimManager->hasKey(),
                'dkim_public_key' => $this->dkimManager->hasKey() ? $this->dkimManager->getPublicKey() : null,
            ]);
        }

        $isFirstTime = !$this->secretManager->isInitialized();

        if ($isFirstTime) {
            return $this->handleFirstTimeSetup($data);
        }

        return $this->handleConfigUpdate($data, $request);
    }

    /**
     * GET /setup/dns — AJAX: check DNS records.
     *
     * @param array<string, string> $params
     */
    public function checkDns(Request $request, array $params): Response
    {
        $domain = (string) $request->getQuery('domain', '');
        $selector = (string) $request->getQuery('selector', '');
        $mode = (string) $request->getQuery('mode', 'smtp');
        $smtpHost = (string) $request->getQuery('smtp_host', '');

        if ($domain === '' || $selector === '') {
            return $this->json(['error' => 'Domain and selector are required.'], 400);
        }

        $publicKey = $this->dkimManager->hasKey() ? $this->dkimManager->getPublicKey() : '';

        $smtpDomain = $smtpHost !== '' ? $this->extractDomain($smtpHost) : null;

        $verifier = new DnsVerifier();
        $results = [
            'spf' => $verifier->checkSpf($domain, $mode, $smtpDomain),
            'dkim' => $verifier->checkDkim($domain, $selector, $publicKey),
            'dmarc' => $verifier->checkDmarc($domain, (string) $request->getQuery('dmarc_email', '')),
        ];

        return $this->json($results);
    }

    /**
     * @param array<string, string> $data
     */
    private function handleFirstTimeSetup(array $data): Response
    {
        try {
            // Generate master key
            $this->secretManager->generateMasterKey();

            // Generate encryption keys
            $encryptionKey = random_bytes(32);
            $blindIndexKey = random_bytes(32);

            // Build secrets array
            $secrets = [
                'db_host' => $data['db_host'],
                'db_port' => (int) $data['db_port'],
                'db_name' => $data['db_name'],
                'db_user' => $data['db_user'],
                'db_password' => $data['db_password'],
                'mail_mode' => $data['mail_mode'],
                'smtp_host' => $data['smtp_host'],
                'smtp_port' => (int) $data['smtp_port'],
                'smtp_user' => $data['smtp_user'],
                'smtp_password' => $data['smtp_password'],
                'encryption_key' => base64_encode($encryptionKey),
                'blind_index_key' => base64_encode($blindIndexKey),
                // TODO: migrate non-secret settings to settings table in iteration 11
                'site_name' => $data['site_name'],
                'short_name' => $data['short_name'],
                'base_url' => $data['base_url'],
                'mail_from_address' => $data['mail_from_address'],
                'mail_from_name' => $data['mail_from_name'],
                'dkim_selector' => $data['dkim_selector'],
                'dmarc_report_email' => $data['dmarc_report_email'],
            ];

            $this->secretManager->writeSecrets($secrets);

            // Test DB connection with actual secrets
            $connection = new Connection(
                $secrets['db_host'],
                $secrets['db_port'],
                $secrets['db_name'],
                $secrets['db_user'],
                $secrets['db_password']
            );

            $testResult = $connection->testConnection();
            if ($testResult !== true) {
                $this->cleanupFailedSetup();
                FlashMessage::set('error', 'La connexion à la base de données a échoué : ' . $testResult);
                return $this->redirect('/setup');
            }

            // Generate DKIM key
            $this->dkimManager->generateKey();

            // Run migration
            $introspector = new SchemaIntrospector($connection->getPdo());
            $runner = new MigrationRunner(
                $connection,
                $introspector,
                new SchemaComparator(),
                new SqlParser()
            );
            $runner->migrate([$this->schemaPath]);

            // Create initial admin account
            $this->createAdminAccount($connection, $encryptionKey, $blindIndexKey, $data['admin_email']);

            FlashMessage::set('success', 'Installation terminée avec succès. Bienvenue !');
            return $this->redirect('/');
        } catch (\Throwable $e) {
            $this->cleanupFailedSetup();
            FlashMessage::set('error', 'Erreur lors de l\'installation : ' . $e->getMessage());
            return $this->redirect('/setup');
        }
    }

    /**
     * @param array<string, string> $data
     */
    private function handleConfigUpdate(array $data, Request $request): Response
    {
        try {
            $currentSecrets = $this->secretManager->readSecrets();

            // Merge new values (keep passwords if not provided)
            $currentSecrets['db_host'] = $data['db_host'];
            $currentSecrets['db_port'] = (int) $data['db_port'];
            $currentSecrets['db_name'] = $data['db_name'];
            $currentSecrets['db_user'] = $data['db_user'];
            if ($data['db_password'] !== '') {
                $currentSecrets['db_password'] = $data['db_password'];
            }
            $currentSecrets['mail_mode'] = $data['mail_mode'];
            $currentSecrets['smtp_host'] = $data['smtp_host'];
            $currentSecrets['smtp_port'] = (int) $data['smtp_port'];
            $currentSecrets['smtp_user'] = $data['smtp_user'];
            if ($data['smtp_password'] !== '') {
                $currentSecrets['smtp_password'] = $data['smtp_password'];
            }
            // TODO: migrate non-secret settings to settings table in iteration 11
            $currentSecrets['site_name'] = $data['site_name'];
            $currentSecrets['short_name'] = $data['short_name'];
            $currentSecrets['base_url'] = $data['base_url'];
            $currentSecrets['mail_from_address'] = $data['mail_from_address'];
            $currentSecrets['mail_from_name'] = $data['mail_from_name'];
            $currentSecrets['dkim_selector'] = $data['dkim_selector'];
            $currentSecrets['dmarc_report_email'] = $data['dmarc_report_email'];

            $this->secretManager->writeSecrets($currentSecrets);

            // Regenerate DKIM key if requested
            if ($request->getBody('regenerate_dkim') === '1') {
                $this->dkimManager->deleteKey();
                $this->dkimManager->generateKey();
            }

            // Run migration
            $connection = new Connection(
                $currentSecrets['db_host'],
                (int) $currentSecrets['db_port'],
                $currentSecrets['db_name'],
                $currentSecrets['db_user'],
                $currentSecrets['db_password']
            );

            $testResult = $connection->testConnection();
            if ($testResult === true) {
                $introspector = new SchemaIntrospector($connection->getPdo());
                $runner = new MigrationRunner(
                    $connection,
                    $introspector,
                    new SchemaComparator(),
                    new SqlParser()
                );
                $runner->migrate([$this->schemaPath]);
            }

            FlashMessage::set('success', 'Configuration enregistrée avec succès.');
            return $this->redirect('/setup');
        } catch (\Throwable $e) {
            FlashMessage::set('error', 'Erreur lors de la sauvegarde : ' . $e->getMessage());
            return $this->redirect('/setup');
        }
    }

    /**
     * @return array<string, string>
     */
    private function collectFormData(Request $request): array
    {
        return [
            'db_host' => trim((string) $request->getBody('db_host', 'localhost')),
            'db_port' => trim((string) $request->getBody('db_port', '3306')),
            'db_name' => trim((string) $request->getBody('db_name', '')),
            'db_user' => trim((string) $request->getBody('db_user', '')),
            'db_password' => (string) $request->getBody('db_password', ''),
            'site_name' => trim((string) $request->getBody('site_name', '')),
            'short_name' => trim((string) $request->getBody('short_name', '')),
            'base_url' => trim((string) $request->getBody('base_url', '')),
            'mail_mode' => (string) $request->getBody('mail_mode', 'smtp'),
            'smtp_host' => trim((string) $request->getBody('smtp_host', '')),
            'smtp_port' => trim((string) $request->getBody('smtp_port', '587')),
            'smtp_user' => trim((string) $request->getBody('smtp_user', '')),
            'smtp_password' => (string) $request->getBody('smtp_password', ''),
            'mail_from_address' => trim((string) $request->getBody('mail_from_address', '')),
            'mail_from_name' => trim((string) $request->getBody('mail_from_name', '')),
            'dkim_selector' => trim((string) $request->getBody('dkim_selector', '')),
            'dmarc_report_email' => trim((string) $request->getBody('dmarc_report_email', '')),
            'admin_email' => trim((string) $request->getBody('admin_email', '')),
        ];
    }

    /**
     * @param array<string, string> $data
     * @return array<string, string>
     */
    private function validateFormData(array $data, bool $isFirstTime): array
    {
        $errors = [];

        // Database fields
        if ($data['db_host'] === '') {
            $errors['db_host'] = 'L\'hôte de la base de données est requis.';
        }
        if ($data['db_port'] === '' || (int) $data['db_port'] < 1 || (int) $data['db_port'] > 65535) {
            $errors['db_port'] = 'Le port doit être compris entre 1 et 65535.';
        }
        if ($data['db_name'] === '') {
            $errors['db_name'] = 'Le nom de la base de données est requis.';
        }
        if ($data['db_user'] === '') {
            $errors['db_user'] = 'L\'utilisateur de la base de données est requis.';
        }
        if ($isFirstTime && $data['db_password'] === '') {
            $errors['db_password'] = 'Le mot de passe de la base de données est requis.';
        }

        // General settings
        if ($data['site_name'] === '') {
            $errors['site_name'] = 'Le nom de l\'unité est requis.';
        }
        if ($data['short_name'] === '') {
            $errors['short_name'] = 'Le nom court est requis.';
        } elseif (!preg_match('/^[A-Za-z0-9]{1,5}$/', $data['short_name'])) {
            $errors['short_name'] = 'Le nom court doit contenir 1 à 5 caractères alphanumériques.';
        }
        if ($data['base_url'] === '') {
            $errors['base_url'] = 'L\'URL de base est requise.';
        } elseif (!filter_var($data['base_url'], FILTER_VALIDATE_URL)) {
            $errors['base_url'] = 'L\'URL de base n\'est pas valide.';
        }

        // Email settings
        if (!in_array($data['mail_mode'], ['smtp', 'local'], true)) {
            $errors['mail_mode'] = 'Le mode d\'envoi doit être SMTP ou Local.';
        }
        if ($data['mail_mode'] === 'smtp') {
            if ($data['smtp_host'] === '') {
                $errors['smtp_host'] = 'L\'hôte SMTP est requis en mode SMTP.';
            }
            if ($data['smtp_port'] === '' || (int) $data['smtp_port'] < 1 || (int) $data['smtp_port'] > 65535) {
                $errors['smtp_port'] = 'Le port SMTP doit être compris entre 1 et 65535.';
            }
            if ($data['smtp_user'] === '') {
                $errors['smtp_user'] = 'L\'utilisateur SMTP est requis en mode SMTP.';
            }
            if ($isFirstTime && $data['smtp_password'] === '') {
                $errors['smtp_password'] = 'Le mot de passe SMTP est requis en mode SMTP.';
            }
        }
        if ($data['mail_from_address'] === '') {
            $errors['mail_from_address'] = 'L\'adresse d\'expédition est requise.';
        } elseif (!filter_var($data['mail_from_address'], FILTER_VALIDATE_EMAIL)) {
            $errors['mail_from_address'] = 'L\'adresse d\'expédition n\'est pas valide.';
        }
        if ($data['mail_from_name'] === '') {
            $errors['mail_from_name'] = 'Le nom d\'expédition est requis.';
        }
        if ($data['dkim_selector'] === '') {
            $errors['dkim_selector'] = 'Le sélecteur DKIM est requis.';
        } elseif (!preg_match('/^[a-z0-9]+$/', $data['dkim_selector'])) {
            $errors['dkim_selector'] = 'Le sélecteur DKIM ne doit contenir que des lettres minuscules et des chiffres.';
        }
        if ($data['dmarc_report_email'] === '') {
            $errors['dmarc_report_email'] = 'L\'email pour les rapports DMARC est requis.';
        } elseif (!filter_var($data['dmarc_report_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['dmarc_report_email'] = 'L\'email pour les rapports DMARC n\'est pas valide.';
        }

        // Admin email (first-time only)
        if ($isFirstTime) {
            if ($data['admin_email'] === '') {
                $errors['admin_email'] = 'L\'email administrateur est requis.';
            } elseif (!filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
                $errors['admin_email'] = 'L\'email administrateur n\'est pas valide.';
            }
        }

        return $errors;
    }

    private function createAdminAccount(Connection $connection, string $encryptionKey, string $blindIndexKey, string $email): void
    {
        $encryptionService = new EncryptionService($encryptionKey, $blindIndexKey);

        $emailEncrypted = $encryptionService->encrypt($email);
        $emailBlindIndex = $encryptionService->blindIndex($email);

        $pdo = $connection->getPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin, created_at) VALUES (?, ?, TRUE, NOW())'
        );
        $stmt->execute([$emailEncrypted, $emailBlindIndex]);
    }

    private function cleanupFailedSetup(): void
    {
        // Remove generated files on failure
        $masterKeyPath = (new \ReflectionClass($this->secretManager))->getProperty('masterKeyPath');
        $secretsPath = (new \ReflectionClass($this->secretManager))->getProperty('secretsPath');

        $masterKey = $masterKeyPath->getValue($this->secretManager);
        $secrets = $secretsPath->getValue($this->secretManager);

        if (file_exists($masterKey)) {
            @unlink($masterKey);
        }
        if (file_exists($secrets)) {
            @unlink($secrets);
        }
        $this->dkimManager->deleteKey();
    }

    private function extractDomain(string $host): string
    {
        // Extract the root domain from an SMTP host (e.g., smtp.gmail.com -> gmail.com)
        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            return implode('.', array_slice($parts, -2));
        }
        return $host;
    }
}
