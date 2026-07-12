<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Config\SettingService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\DnsVerifier;
use Core\Mail\MailServiceFactory;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Twig\Environment;

class SetupController extends AbstractController
{
    private ?SettingService $settingService = null;
    private ?JournalService $journalService = null;

    public function __construct(
        protected Environment $twig,
        private SecretManager $secretManager,
        private DkimManager $dkimManager,
        private string $schemaPath
    ) {
    }

    public function setSettingService(SettingService $settingService): void
    {
        $this->settingService = $settingService;
    }

    public function setJournalService(JournalService $journalService): void
    {
        $this->journalService = $journalService;
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

            // Non-secret settings may have been migrated to the settings table
            $setting = fn(string $key): string =>
                $this->settingService !== null
                    ? ($this->settingService->get($key) ?? ($secrets[$key] ?? ''))
                    : ($secrets[$key] ?? '');

            $currentValues = [
                'db_host' => $secrets['db_host'] ?? 'localhost',
                'db_port' => $secrets['db_port'] ?? 3306,
                'db_name' => $secrets['db_name'] ?? '',
                'db_user' => $secrets['db_user'] ?? '',
                'db_password' => '',
                'site_name' => $setting('site_name'),
                'short_name' => $setting('short_name'),
                'base_url' => $setting('base_url'),
                'mail_mode' => $secrets['mail_mode'] ?? 'smtp',
                'smtp_host' => $secrets['smtp_host'] ?? '',
                'smtp_port' => $secrets['smtp_port'] ?? 587,
                'smtp_user' => $secrets['smtp_user'] ?? '',
                'smtp_password' => '',
                'mail_from_address' => $setting('mail_from_address'),
                'mail_from_name' => $setting('mail_from_name'),
                'dkim_selector' => $setting('dkim_selector'),
                'dmarc_report_email' => $setting('dmarc_report_email'),
                'admin_email' => $secrets['admin_email'] ?? '',
                'admin_password' => '',
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
        if (!CsrfGuard::validateRequest()) {
            return $this->json(['success' => false, 'message' => 'Jeton CSRF invalide.'], 403);
        }

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
     * POST /setup/test-email — AJAX: send a test email.
     *
     * @param array<string, string> $params
     */
    public function testEmail(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return $this->json(['success' => false, 'message' => 'Jeton CSRF invalide.'], 403);
        }

        if (!$this->secretManager->isInitialized()) {
            return $this->json(['success' => false, 'message' => 'Le site n\'est pas encore initialisé.'], 400);
        }

        $secrets = $this->secretManager->readSecrets();
        $recipient = trim((string) $request->getBody('recipient', ''));

        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'message' => 'Adresse email invalide.']);
        }

        try {
            $mailService = MailServiceFactory::create($secrets, $this->dkimManager);
            $mailService->send(
                to: $recipient,
                subject: 'Email de test',
                bodyHtml: '<p>Ceci est un email de test envoyé depuis la page de configuration.</p><p>Si vous lisez ceci, votre configuration SMTP fonctionne correctement.</p>',
                bodyText: "Ceci est un email de test envoyé depuis la page de configuration.\n\nSi vous lisez ceci, votre configuration SMTP fonctionne correctement."
            );

            return $this->json(['success' => true, 'message' => 'Email envoyé avec succès.']);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
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
                // Non-secret settings stored temporarily; migrated to settings table on first boot
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

            // Store admin email in secrets for auto-repair
            $secrets['admin_email'] = strtolower(trim($data['admin_email']));
            $this->secretManager->writeSecrets($secrets);

            // Create initial admin account (use base64 keys to match boot sequence)
            $this->createAdminAccount($connection, $secrets['encryption_key'], $secrets['blind_index_key'], $data['admin_email'], $data['admin_password']);

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
            $this->secretManager->writeSecrets($currentSecrets);

            // Write non-secret settings to settings table
            if ($this->settingService !== null) {
                $nonSecretKeys = ['site_name', 'short_name', 'base_url', 'mail_from_address', 'mail_from_name', 'dkim_selector', 'dmarc_report_email'];
                foreach ($nonSecretKeys as $nsKey) {
                    if (isset($data[$nsKey])) {
                        try {
                            $this->settingService->set($nsKey, $data[$nsKey]);
                        } catch (\Throwable $e) {
                            // Setting may not be registered yet during initial setup
                        }
                    }
                }
                $this->settingService->clearCache();
            }

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

            // Create or update admin account if provided
            if ($data['admin_email'] !== '' && $data['admin_password'] !== '') {
                $this->upsertAdminAccount($connection, $currentSecrets, $data['admin_email'], $data['admin_password']);
            }

            $this->journalService?->log(
                'core', 'setup_completed', 'security', 'Site configuration saved',
                ['ip' => $_SERVER['REMOTE_ADDR'] ?? ''],
                AuthSession::getUserAccountId()
            );

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
            'admin_password' => (string) $request->getBody('admin_password', ''),
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

        // Admin email and password
        if ($isFirstTime) {
            // Required on first-time setup
            if ($data['admin_email'] === '') {
                $errors['admin_email'] = 'L\'email administrateur est requis.';
            } elseif (!filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
                $errors['admin_email'] = 'L\'email administrateur n\'est pas valide.';
            }
            if ($data['admin_password'] === '') {
                $errors['admin_password'] = 'Le mot de passe administrateur est requis.';
            } elseif (strlen($data['admin_password']) < 8) {
                $errors['admin_password'] = 'Le mot de passe doit contenir au moins 8 caractères.';
            }
        } else {
            // Optional on config update — validate only if provided
            if ($data['admin_email'] !== '' && !filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
                $errors['admin_email'] = 'L\'email administrateur n\'est pas valide.';
            }
            if ($data['admin_email'] !== '' && $data['admin_password'] === '') {
                $errors['admin_password'] = 'Le mot de passe est requis pour créer ou mettre à jour le compte.';
            } elseif ($data['admin_password'] !== '' && strlen($data['admin_password']) < 8) {
                $errors['admin_password'] = 'Le mot de passe doit contenir au moins 8 caractères.';
            }
            if ($data['admin_password'] !== '' && $data['admin_email'] === '') {
                $errors['admin_email'] = 'L\'email administrateur est requis.';
            }
        }

        return $errors;
    }

    private function createAdminAccount(Connection $connection, string $encryptionKey, string $blindIndexKey, string $email, string $password): void
    {
        $encryptionService = new EncryptionService($encryptionKey, $blindIndexKey);
        $normalizedEmail = strtolower(trim($email));

        $emailEncrypted = $encryptionService->encrypt($normalizedEmail);
        $emailBlindIndex = $encryptionService->blindIndex($normalizedEmail);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $pdo = $connection->getPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, password_hash, is_super_admin, created_at) VALUES (?, ?, ?, TRUE, NOW())'
        );
        $stmt->execute([$emailEncrypted, $emailBlindIndex, $passwordHash]);
    }

    /**
     * Create or update the admin account during config update.
     *
     * @param array<string, mixed> $secrets
     */
    private function upsertAdminAccount(Connection $connection, array $secrets, string $email, string $password): void
    {
        $encryptionService = new EncryptionService(
            (string) $secrets['encryption_key'],
            (string) $secrets['blind_index_key']
        );
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $encryptionService->blindIndex($normalizedEmail);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $pdo = $connection->getPdo();

        // Check if account already exists
        $stmt = $pdo->prepare('SELECT id FROM user_accounts WHERE email_blind_index = ?');
        $stmt->execute([$blindIndex]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing !== false) {
            // Update password and ensure super admin
            $stmt = $pdo->prepare('UPDATE user_accounts SET password_hash = ?, is_super_admin = TRUE WHERE id = ?');
            $stmt->execute([$passwordHash, $existing['id']]);
        } else {
            // Create new admin account
            $emailEncrypted = $encryptionService->encrypt($normalizedEmail);
            $stmt = $pdo->prepare(
                'INSERT INTO user_accounts (email_encrypted, email_blind_index, password_hash, is_super_admin, created_at) VALUES (?, ?, ?, TRUE, NOW())'
            );
            $stmt->execute([$emailEncrypted, $blindIndex, $passwordHash]);
        }

        // Store admin email in secrets
        $secrets['admin_email'] = $normalizedEmail;
        $this->secretManager->writeSecrets($secrets);
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
