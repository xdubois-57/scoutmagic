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
use Minishlink\WebPush\VAPID;
use Twig\Environment;

class SetupController extends AbstractController
{
    private ?SettingService $settingService = null;
    private ?JournalService $journalService = null;

    public function __construct(
        protected Environment $twig,
        private SecretManager $secretManager,
        private DkimManager $dkimManager,
        private string $schemaPath,
        private string $publicDir = ''
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

        if (!$isInitialized) {
            $gate = $this->checkTokenGate();
            if ($gate !== null) {
                return $gate;
            }
        }

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
     * POST /setup/verify-token — checks a submitted token against token.php
     * (created via FTP by the bootstrap installer, or manually by the
     * operator). Session-based progressive lockout: there is no database
     * to back Core\Security\LoginThrottler's pattern yet at this point
     * (that's the whole reason this gate exists), so failures are tracked
     * per-session instead, with the same tiered escalation shape.
     *
     * @param array<string, string> $params
     */
    public function verifyToken(Request $request, array $params): Response
    {
        if ($this->secretManager->isInitialized()) {
            return $this->redirect('/setup');
        }
        if (!CsrfGuard::validateRequest()) {
            return $this->redirect('/setup');
        }

        $lockedUntil = (int) ($_SESSION['setup_token_locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            return $this->redirect('/setup');
        }

        $tokenPath = $this->findTokenFile();
        $expected = $tokenPath !== null ? $this->extractTokenValue((string) @file_get_contents($tokenPath)) : '';
        $submitted = trim((string) $request->getBody('token', ''));

        $valid = $tokenPath !== null && $expected !== '' && $submitted !== '' && hash_equals($expected, $submitted);

        if ($valid) {
            unset($_SESSION['setup_token_attempts'], $_SESSION['setup_token_locked_until'], $_SESSION['setup_token_error']);
            $_SESSION['setup_token_verified'] = true;
            $this->journalService?->log(
                'core', 'setup_token_verified', 'security', 'Jeton d\'installation vérifié avec succès',
                ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']
            );
            return $this->redirect('/setup');
        }

        $attempts = (int) ($_SESSION['setup_token_attempts'] ?? 0) + 1;
        $_SESSION['setup_token_attempts'] = $attempts;
        $_SESSION['setup_token_error'] = 'Jeton invalide.';

        if ($attempts >= 10) {
            $_SESSION['setup_token_locked_until'] = time() + 86400;
            $_SESSION['setup_token_error'] = 'Trop de tentatives — nouvel essai possible dans 24 heures.';
        } elseif ($attempts >= 8) {
            $_SESSION['setup_token_locked_until'] = time() + 1800;
        } elseif ($attempts >= 6) {
            $_SESSION['setup_token_locked_until'] = time() + 300;
        } elseif ($attempts >= 4) {
            $_SESSION['setup_token_locked_until'] = time() + 60;
        }

        $this->journalService?->log(
            'core', 'setup_token_failed', 'security', 'Tentative de jeton d\'installation invalide',
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'attempts' => $attempts]
        );

        return $this->redirect('/setup');
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

        // short_name, mail_from_address, mail_from_name and dkim_selector
        // all live in the settings table (migrated out of secrets.enc by
        // public/index.php's one-time migration) — without this merge,
        // mail_from_address in particular comes out permanently empty on
        // any install that already ran that migration, which makes
        // PHPMailer reject the send outright ("Invalid address: (From): "),
        // same root cause fixed in public/index.php and public/cron.php.
        if ($this->settingService !== null) {
            foreach (['short_name', 'mail_from_address', 'mail_from_name', 'dkim_selector'] as $mailSecretKey) {
                $secrets[$mailSecretKey] = (string) ($this->settingService->get($mailSecretKey) ?: ($secrets[$mailSecretKey] ?? ''));
            }
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

            // Generate VAPID keys (Web Push, Core\Notification) — same
            // "generated once at setup, lives in secrets.enc" treatment as
            // the encryption keys above.
            $vapidKeys = VAPID::createVapidKeys();

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
                'vapid_public_key' => $vapidKeys['publicKey'],
                'vapid_private_key' => $vapidKeys['privateKey'],
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

            $tokenDeleted = $this->deleteTokenFileWithWarning();

            FlashMessage::set(
                $tokenDeleted ? 'success' : 'warning',
                $tokenDeleted
                    ? 'Installation terminée avec succès. Bienvenue !'
                    : 'Installation terminée avec succès, mais token.php n\'a pas pu être supprimé automatiquement — retirez-le manuellement via FTP pour des raisons de sécurité.'
            );
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
                'core', 'setup_completed', 'security', 'Configuration du site enregistrée',
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
            // Password is only required when changing admin email to a new address without existing account
            if ($data['admin_password'] !== '' && strlen($data['admin_password']) < 8) {
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
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, password_hash, is_super_admin) VALUES (?, ?, ?, TRUE)'
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
                'INSERT INTO user_accounts (email_encrypted, email_blind_index, password_hash, is_super_admin) VALUES (?, ?, ?, TRUE)'
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

    /**
     * Gates GET /setup while the site isn't initialized: no token.php,
     * no wizard — ever. Returns null when access is already granted this
     * session, otherwise a Response for whichever gate screen applies.
     */
    private function checkTokenGate(): ?Response
    {
        if (($_SESSION['setup_token_verified'] ?? false) === true) {
            return null;
        }

        $tokenPath = $this->findTokenFile();

        if ($tokenPath === null) {
            $example = bin2hex(random_bytes(32));
            return $this->render('setup/token_gate.html.twig', [
                'state' => 'missing',
                'example_content' => "<?php /* TOKEN: {$example} */\n",
            ]);
        }

        $lockedUntil = (int) ($_SESSION['setup_token_locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            return $this->render('setup/token_gate.html.twig', [
                'state' => 'locked',
                'retry_after' => $lockedUntil - time(),
            ]);
        }

        $error = $_SESSION['setup_token_error'] ?? null;
        unset($_SESSION['setup_token_error']);

        return $this->render('setup/token_gate.html.twig', [
            'state' => 'form',
            'csrf_token' => CsrfGuard::generateToken(),
            'error' => $error,
        ]);
    }

    /**
     * token.php always lives in the HTTP document root, which sits at a
     * different relative position from this controller depending on which
     * layout bootstrap.php chose (Layout A: same directory as index.php;
     * Layout B: one level above public/) — see bootstrap/bootstrap.php's
     * own layout-selection comments. Checking both fixed candidates is a
     * narrow exception scoped to this one gate file, not a general
     * path-resolution abstraction (ARCHITECTURE.md §9's governing
     * constraint is about not teaching the rest of the codebase about
     * hosting layouts, which this doesn't).
     *
     * @return string[]
     */
    private function candidateTokenPaths(): array
    {
        $candidates = [];
        if ($this->publicDir !== '') {
            $candidates[] = rtrim($this->publicDir, '/') . '/token.php';
            $candidates[] = dirname(rtrim($this->publicDir, '/')) . '/token.php';
        }

        return array_values(array_unique($candidates));
    }

    private function findTokenFile(): ?string
    {
        foreach ($this->candidateTokenPaths() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function extractTokenValue(string $fileContent): string
    {
        if (preg_match('/TOKEN:\s*([0-9a-f]+)/i', $fileContent, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Deletes token.php once first-time setup genuinely completes — not at
     * token verification (which only unlocks the form), matching
     * bootstrap.php's own framing of token.php as gating the whole wizard,
     * not just its first screen.
     */
    private function deleteTokenFileWithWarning(): bool
    {
        $tokenPath = $this->findTokenFile();
        if ($tokenPath === null) {
            return true;
        }

        if (@unlink($tokenPath)) {
            return true;
        }

        $this->journalService?->log(
            'core', 'setup_token_delete_failed', 'security',
            'Impossible de supprimer token.php après l\'installation — à retirer manuellement via FTP.',
            []
        );

        return false;
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
