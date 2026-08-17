<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Maintenance\BackupException;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Maintenance\GitHubReleaseClient;
use Core\Maintenance\GitHubReleaseClientInterface;
use Core\Maintenance\UpdateException;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Maintenance\VersionFile;
use Core\Module\ModuleManager;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Twig\Environment;

/**
 * Configuration > Maintenance: "Mise à jour", "Mises à jour automatiques",
 * "Sauvegardes", "Réinitialisation" (in that page order) sections.
 */
class MaintenanceController extends AbstractController
{
    /** @var string[] */
    private const FULL_BACKUP_SCOPES = ['full_config', 'full_no_gallery', 'full_with_gallery'];

    private const KEEP_BACKUPS = 5;

    // Each "Réinitialisation" action requires the admin to type this exact
    // word server-side (module spec: "Ne pas permettre aucune action de
    // réinitialisation/restauration sans confirmation par mot-clé vérifiée
    // côté serveur (pas uniquement en JS)") — a JS check is a UX nicety
    // only, never the actual gate.
    private const KEYWORD_RESET_SETTINGS = 'REINITIALISER';
    private const KEYWORD_FULL_RESET = 'EFFACER';
    private const KEYWORD_RESTORE = 'RESTAURER';

    private const RESTORE_UPLOAD_MAX_BYTES = 500 * 1024 * 1024;

    /** @var string[] */
    private const AUTO_BACKUP_FREQUENCIES = ['none', 'daily', 'weekly', 'biweekly', 'monthly'];

    // 'dev' folds what used to be a separate "Mode développement" danger
    // zone into this same radio group — one mutually-exclusive choice
    // instead of two independently-toggleable mechanisms (module spec:
    // the extra confirm-keyword friction that zone had wasn't adding real
    // protection, since configuring the GitHub webhook at all already
    // requires repo admin rights).
    /** @var string[] */
    private const AUTO_UPDATE_LEVELS = ['patch', 'minor', 'major', 'dev'];

    /** @var string[] */
    private const WEEK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(
        protected Environment $twig,
        private BackupService $backupService,
        private BackupRepository $backupRepository,
        private FileRepository $fileRepository,
        private UpdateHistoryRepository $updateHistoryRepository,
        private SchedulerService $schedulerService,
        private ModuleManager $moduleManager,
        private EncryptionService $encryption,
        private JournalService $journalService,
        private SettingService $settingService,
        private string $storagePath,
        private SecretManager $secretManager,
        private ?GitHubReleaseClientInterface $releaseClient = null
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $latestVersion = (string) ($this->settingService->get('update_latest_version') ?: '');
        $installedVersion = VersionFile::read(dirname($this->storagePath));
        $level = (string) ($this->settingService->get('auto_update_level') ?: 'minor');
        $updateAvailable = $latestVersion !== '' && VersionFile::isNewerThan($latestVersion, $installedVersion, $level === 'dev');

        $autoUpdateEnabled = (bool) ((int) ($this->settingService->get('auto_update_enabled') ?: '0'));
        $webhookConfigured = $this->webhookSecret() !== '';
        [$installedVersionDisplay, $installedVersionCommit] = self::splitInstalledVersion($installedVersion);
        $installedNotes = $this->installedVersionNotes($installedVersion, $installedVersionCommit);

        return $this->render('config/maintenance.html.twig', [
            'installed_version' => $installedVersionDisplay,
            'installed_version_commit' => $installedVersionCommit,
            'installed_version_notes' => $installedNotes['notes'],
            'installed_version_notes_url' => $installedNotes['url'],
            'update_checked_at' => (string) ($this->settingService->get('update_checked_at') ?: ''),
            'update_available' => $updateAvailable,
            'update_latest_version' => $latestVersion,
            'update_release_notes' => (string) ($this->settingService->get('update_release_notes') ?: ''),
            'update_release_html_url' => (string) ($this->settingService->get('update_release_html_url') ?: ''),
            'update_dependencies_changed' => (bool) ((int) ($this->settingService->get('update_dependencies_changed') ?: '0')),
            'update_history' => $this->updateHistoryRepository->findRecent(5),
            'backups' => $this->backupRepository->findRecent(self::KEEP_BACKUPS),
            'gallery_enabled' => in_array('gallery', $this->moduleManager->getEnabledModuleIds(), true),
            'zip_encryption_supported' => $this->backupService->supportsZipEncryption(),
            'backup_auto_frequency' => (string) ($this->settingService->get('backup_auto_frequency') ?: 'monthly'),
            'backup_auto_last_run' => (string) ($this->settingService->get('backup_auto_last_run') ?: ''),
            'auto_update_enabled' => $autoUpdateEnabled,
            'auto_update_level' => $level,
            'auto_update_day' => (string) ($this->settingService->get('auto_update_day') ?: 'monday'),
            'auto_update_time' => (string) ($this->settingService->get('auto_update_time') ?: '03:00'),
            'webhook_configured' => $webhookConfigured,
            'webhook_warning' => $autoUpdateEnabled && $level === 'dev' && !$webhookConfigured,
            'webhook_url' => rtrim((string) ($this->settingService->get('base_url') ?: ''), '/') . '/api/webhook/github',
            'dev_update_branch' => (string) ($this->settingService->get('dev_update_branch') ?: 'main'),
        ]);
    }

    /**
     * POST /config/maintenance/update/install (AJAX, JSON) — schedules the
     * background installation (Task\InstallUpdateHandler) and returns
     * immediately; the page polls updateStatus() for progress. Re-validates
     * server-side that a newer version is actually available — never
     * trusts the client's own idea of the target version — from the cached
     * check result (stable channel) or a fresh GitHub lookup (development
     * channel, since there's no equivalent settings cache for a commit).
     *
     * @param array<string, string> $params
     */
    public function installUpdate(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $installedVersion = VersionFile::read(dirname($this->storagePath));
        $level = (string) ($this->settingService->get('auto_update_level') ?: 'minor');
        $userId = AuthSession::getUserAccountId();

        if ($level === 'dev') {
            return $this->installDevBranchUpdate($installedVersion, $userId);
        }

        $latestVersion = (string) ($this->settingService->get('update_latest_version') ?: '');
        $downloadUrl = (string) ($this->settingService->get('update_download_url') ?: '');

        if ($latestVersion === '' || $downloadUrl === '' || !VersionFile::isNewerThan($latestVersion, $installedVersion)) {
            return $this->json(['success' => false, 'error' => 'Aucune mise à jour disponible.'], 400);
        }

        $dependenciesChanged = (bool) ((int) ($this->settingService->get('update_dependencies_changed') ?: '0'));

        $historyId = $this->updateHistoryRepository->create($installedVersion, $latestVersion, $dependenciesChanged, $userId);

        $this->schedulerService->scheduleAfter(
            'core',
            'install_update',
            0,
            ['history_id' => $historyId, 'download_url' => $downloadUrl],
            null,
            $userId
        );

        $this->journalService->log(
            'core', 'update_requested', 'info', 'Installation de mise à jour demandée',
            ['history_id' => $historyId, 'version_from' => $installedVersion, 'version_to' => $latestVersion], $userId
        );

        return $this->json(['success' => true, 'history_id' => $historyId]);
    }

    /**
     * Development-channel path for installUpdate() — re-fetches the
     * branch's current head rather than trusting anything cached client-
     * side, then schedules exactly the same immediate/no-slot install
     * GitHubWebhookService::handlePushEvent() would for the same commit.
     */
    private function installDevBranchUpdate(string $installedVersion, ?int $userId): Response
    {
        $branch = (string) ($this->settingService->get('dev_update_branch') ?: 'main');
        $owner = (string) ($this->settingService->get('update_github_owner') ?: '');
        $repo = (string) ($this->settingService->get('update_github_repo') ?: '');

        try {
            $commit = $this->releaseClient()->getLatestCommit($branch);
        } catch (UpdateException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        if ($commit === null) {
            return $this->json(['success' => false, 'error' => "Branche « {$branch} » introuvable sur le dépôt GitHub."], 404);
        }

        $versionTo = $commit->shortVersion();
        if ($versionTo === $installedVersion) {
            return $this->json(['success' => false, 'error' => 'Aucune mise à jour disponible.'], 400);
        }

        $downloadUrl = "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$commit->sha}";
        $historyId = $this->updateHistoryRepository->create($installedVersion, $versionTo, false, $userId);

        $this->schedulerService->scheduleAfter(
            'core',
            'install_update',
            0,
            ['history_id' => $historyId, 'download_url' => $downloadUrl, 'source_type' => 'branch'],
            null,
            $userId
        );

        $this->journalService->log(
            'core', 'update_requested', 'info', 'Installation de mise à jour demandée (mode développement)',
            ['history_id' => $historyId, 'version_from' => $installedVersion, 'version_to' => $versionTo], $userId
        );

        return $this->json(['success' => true, 'history_id' => $historyId]);
    }

    /**
     * POST /config/maintenance/update/check-now (AJAX, JSON) — on-demand
     * check for the "Vérifier maintenant" button, since detection is
     * otherwise purely webhook-driven with no polling in between. Checks
     * the stable channel (GET .../releases/latest) unless development mode
     * (auto_update_level === 'dev') is configured, in which case it checks
     * the latest commit on the configured branch instead — never both,
     * mirroring GitHubWebhookService's release/push mutual exclusivity.
     * Does not itself schedule an install — the client shows a confirm
     * dialog and, if accepted, calls installUpdate() (stable channel) or
     * relies on the result already caching update_download_url the same
     * way a webhook-triggered check would.
     *
     * @param array<string, string> $params
     */
    public function checkForUpdatesNow(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $installedVersion = VersionFile::read(dirname($this->storagePath));
        $level = (string) ($this->settingService->get('auto_update_level') ?: 'minor');
        $client = $this->releaseClient();

        try {
            if ($level === 'dev') {
                $branch = (string) ($this->settingService->get('dev_update_branch') ?: 'main');
                $commit = $client->getLatestCommit($branch);

                if ($commit === null) {
                    return $this->json(['success' => false, 'error' => "Branche « {$branch} » introuvable sur le dépôt GitHub."], 404);
                }

                return $this->json([
                    'success' => true,
                    'channel' => 'dev',
                    'update_available' => $installedVersion !== $commit->shortVersion(),
                    'version' => $commit->shortVersion(),
                    // Full message, not just the first line — this team's
                    // commits carry their real rationale in the body (see
                    // any recent `git log`), which is exactly what an admin
                    // deciding whether to install needs to read.
                    'notes' => trim($commit->message),
                    'url' => $commit->htmlUrl,
                ]);
            }

            $release = $client->getLatestRelease();
            if ($release === null) {
                return $this->json(['success' => true, 'channel' => 'release', 'update_available' => false]);
            }

            // Same settings cache the webhook path refreshes, so a manual
            // check's result is immediately consistent with the rest of
            // the page (and installUpdate() below can act on it).
            $this->settingService->setInternal('update_checked_at', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
            $this->settingService->setInternal('update_latest_version', $release->version());
            $this->settingService->setInternal('update_release_notes', $release->body);
            $this->settingService->setInternal('update_release_html_url', $release->htmlUrl);
            $this->settingService->setInternal('update_download_url', (string) $release->downloadUrl);

            return $this->json([
                'success' => true,
                'channel' => 'release',
                'update_available' => VersionFile::isNewerThan($release->version(), $installedVersion),
                'version' => $release->version(),
                'notes' => $release->body,
                'url' => $release->htmlUrl,
            ]);
        } catch (UpdateException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 502);
        }
    }

    private function releaseClient(): GitHubReleaseClientInterface
    {
        if ($this->releaseClient !== null) {
            return $this->releaseClient;
        }

        $owner = (string) ($this->settingService->get('update_github_owner') ?: '');
        $repo = (string) ($this->settingService->get('update_github_repo') ?: '');

        return new GitHubReleaseClient($owner, $repo);
    }

    /**
     * GET /api/maintenance/update-status/{id} (AJAX, JSON) — polled by the
     * Maintenance page while an update installs.
     *
     * @param array<string, string> $params
     */
    public function updateStatus(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $history = $this->updateHistoryRepository->findById($id);
        if ($history === null) {
            return $this->json(['error' => 'Mise à jour introuvable.'], 404);
        }

        return $this->json([
            'status' => $history->status,
            'error_message' => $history->errorMessage,
        ]);
    }

    /**
     * POST /config/maintenance/backup/database — synchronous (module spec:
     * a plain DB dump is fast enough not to need the background/polling
     * pattern the full zip uses).
     *
     * @param array<string, string> $params
     */
    public function createDatabaseBackup(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/config/maintenance');
        }

        $userId = AuthSession::getUserAccountId();
        $backupId = $this->backupRepository->create('database', $userId);
        $this->backupRepository->markInProgress($backupId);

        try {
            $path = $this->backupService->createDatabaseDump();
            $fileId = $this->fileRepository->create(
                $this->relativePath($path),
                'database.sql',
                'application/sql',
                (int) filesize($path),
                'admin',
                null,
                $userId
            );
            $this->backupRepository->markCompleted($backupId, $fileId, null);
            $this->purgeBeyondLimit();

            $this->journalService->log(
                'core', 'backup_completed', 'info', 'Sauvegarde de la base de données générée',
                ['backup_id' => $backupId], $userId
            );
            FlashMessage::set('success', 'Sauvegarde de la base de données générée.');
        } catch (BackupException $e) {
            $this->backupRepository->markFailed($backupId, substr($e->getMessage(), 0, 500));
            $this->journalService->log(
                'core', 'backup_failed', 'info', 'Échec de la génération d\'une sauvegarde de base de données',
                ['backup_id' => $backupId, 'error' => $e->getMessage()], $userId
            );
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/config/maintenance');
    }

    /**
     * POST /config/maintenance/backup/full (AJAX, JSON) — schedules the
     * background generation (module spec: too slow for a synchronous
     * request, especially with the gallery included) and returns
     * immediately; the page polls backupStatus() for progress.
     *
     * @param array<string, string> $params
     */
    public function createFullBackup(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $scope = (string) ($data['scope'] ?? '');
        $password = (string) ($data['password'] ?? '');

        if (!in_array($scope, self::FULL_BACKUP_SCOPES, true)) {
            return $this->json(['success' => false, 'error' => 'Portée de sauvegarde invalide.'], 400);
        }
        if ($scope === 'full_with_gallery' && !in_array('gallery', $this->moduleManager->getEnabledModuleIds(), true)) {
            return $this->json(['success' => false, 'error' => 'Le module galerie n\'est pas actif.'], 400);
        }
        if ($password === '') {
            return $this->json(['success' => false, 'error' => 'Un mot de passe est requis.'], 400);
        }
        if (!$this->backupService->supportsZipEncryption()) {
            return $this->json(['success' => false, 'error' => 'Le serveur ne supporte pas le chiffrement des archives — contactez votre hébergeur.'], 422);
        }

        $userId = AuthSession::getUserAccountId();
        $backupId = $this->backupRepository->create($scope, $userId);

        // The password never touches the database in plaintext — encrypted
        // with the same master-key-backed service as everything else
        // sensitive, decrypted only inside CreateBackupHandler right before
        // it's needed.
        $encryptedPassword = base64_encode($this->encryption->encrypt($password));

        $this->schedulerService->scheduleAfter(
            'core',
            'create_backup',
            0,
            ['backup_id' => $backupId, 'scope' => $scope, 'encrypted_password' => $encryptedPassword],
            null,
            $userId
        );

        $this->journalService->log(
            'core', 'backup_requested', 'info', 'Sauvegarde complète demandée',
            ['backup_id' => $backupId, 'scope' => $scope], $userId
        );

        return $this->json(['success' => true, 'backup_id' => $backupId]);
    }

    /**
     * POST /config/maintenance/backup/auto-frequency (AJAX, JSON) —
     * auto-saved on change (same pattern as the banner module's per-item
     * visibility select). Deliberately its own admin-level endpoint rather
     * than the generic superadmin-only POST /config/settings/update, since
     * the Maintenance page itself is role_min admin.
     *
     * @param array<string, string> $params
     */
    public function updateAutoBackupFrequency(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $frequency = (string) ($data['frequency'] ?? '');
        if (!in_array($frequency, self::AUTO_BACKUP_FREQUENCIES, true)) {
            return $this->json(['success' => false, 'error' => 'Fréquence invalide.'], 400);
        }

        $userId = AuthSession::getUserAccountId();
        $this->settingService->set('backup_auto_frequency', $frequency);

        $this->journalService->log(
            'core', 'setting_changed', 'info', 'Fréquence de sauvegarde automatique modifiée',
            ['key' => 'backup_auto_frequency', 'new_value' => $frequency], $userId
        );

        return $this->json(['success' => true]);
    }

    /**
     * GET /api/maintenance/backup-status/{id} (AJAX, JSON) — polled by the
     * Maintenance page while a full backup is generating.
     *
     * @param array<string, string> $params
     */
    public function backupStatus(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $backup = $this->backupRepository->findById($id);
        if ($backup === null) {
            return $this->json(['error' => 'Sauvegarde introuvable.'], 404);
        }

        return $this->json([
            'status' => $backup->status,
            'error_message' => $backup->errorMessage,
            'download_url' => $backup->fileId !== null ? '/files/' . $backup->fileId : null,
        ]);
    }

    /**
     * POST /config/maintenance/reset/settings (AJAX, JSON) — schedules
     * Task\ResetSettingsHandler. Requires typing KEYWORD_RESET_SETTINGS.
     *
     * @param array<string, string> $params
     */
    public function resetSettings(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if ((string) ($data['confirm_keyword'] ?? '') !== self::KEYWORD_RESET_SETTINGS) {
            return $this->json(['success' => false, 'error' => 'Mot de confirmation incorrect.'], 400);
        }

        $userId = AuthSession::getUserAccountId();
        $actionId = $this->schedulerService->scheduleAfter('core', 'reset_settings', 0, [], null, $userId);

        $this->journalService->log('core', 'settings_reset_requested', 'security', 'Réinitialisation des paramètres par défaut demandée', [], $userId);

        return $this->json(['success' => true, 'action_id' => $actionId]);
    }

    /**
     * POST /config/maintenance/reset/full (AJAX, JSON) — schedules
     * Task\FullResetHandler. Requires typing KEYWORD_FULL_RESET AND
     * checking the explicit "I understand" checkbox — the most destructive
     * action on the site, per module spec double-confirmation requirement.
     *
     * @param array<string, string> $params
     */
    public function fullReset(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if ((string) ($data['confirm_keyword'] ?? '') !== self::KEYWORD_FULL_RESET) {
            return $this->json(['success' => false, 'error' => 'Mot de confirmation incorrect.'], 400);
        }
        if (($data['confirm_checkbox'] ?? false) !== true) {
            return $this->json(['success' => false, 'error' => 'Vous devez cocher la case de confirmation.'], 400);
        }

        $userId = AuthSession::getUserAccountId();
        $actionId = $this->schedulerService->scheduleAfter('core', 'full_reset', 0, [], null, $userId);

        $this->journalService->log('core', 'full_reset_requested', 'security', 'Réinitialisation complète demandée', [], $userId);

        return $this->json(['success' => true, 'action_id' => $actionId]);
    }

    /**
     * POST /config/maintenance/reset/restore — classic multipart form POST
     * (not AJAX/JSON, unlike the site's other Maintenance actions), since
     * source=upload needs a real file upload; the page redirects back with
     * ?restore_id={id} so its JS can start polling resetStatus()
     * immediately. Requires typing KEYWORD_RESTORE.
     *
     * @param array<string, string> $params
     */
    public function restoreBackup(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/config/maintenance');
        }
        if ((string) $request->getBody('confirm_keyword', '') !== self::KEYWORD_RESTORE) {
            FlashMessage::set('error', 'Mot de confirmation incorrect.');
            return $this->redirect('/config/maintenance');
        }

        $userId = AuthSession::getUserAccountId();
        $source = (string) $request->getBody('source', 'server');
        $password = (string) $request->getBody('password', '');
        $encryptedPassword = $password !== '' ? base64_encode($this->encryption->encrypt($password)) : null;

        $payload = ['source' => $source, 'encrypted_password' => $encryptedPassword];

        if ($source === 'upload') {
            $file = $request->getFile('backup_file');
            if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
                FlashMessage::set('error', 'Veuillez sélectionner un fichier de sauvegarde valide.');
                return $this->redirect('/config/maintenance');
            }
            $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                FlashMessage::set('error', 'Le fichier doit être une archive ZIP.');
                return $this->redirect('/config/maintenance');
            }
            if ($file['size'] > self::RESTORE_UPLOAD_MAX_BYTES) {
                FlashMessage::set('error', 'Le fichier dépasse la taille maximale autorisée.');
                return $this->redirect('/config/maintenance');
            }

            $tempDir = $this->storagePath . '/temp';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir . '/' . uniqid('restore_upload_') . '.zip';
            if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
                FlashMessage::set('error', 'Le téléversement du fichier a échoué.');
                return $this->redirect('/config/maintenance');
            }
            $payload['uploaded_temp_path'] = $tempPath;
        } else {
            $backupId = (int) $request->getBody('backup_id', '0');
            $backup = $this->backupRepository->findById($backupId);
            if ($backup === null || $backup->status !== 'completed') {
                FlashMessage::set('error', 'Sauvegarde introuvable ou incomplète.');
                return $this->redirect('/config/maintenance');
            }
            $payload['backup_id'] = $backupId;
        }

        $actionId = $this->schedulerService->scheduleAfter('core', 'restore_backup', 0, $payload, null, $userId);

        $this->journalService->log('core', 'backup_restore_requested', 'security', 'Restauration de sauvegarde demandée', ['source' => $source], $userId);

        return $this->redirect('/config/maintenance?restore_id=' . $actionId);
    }

    /**
     * GET /api/maintenance/reset-status/{id} (AJAX, JSON) — polled by the
     * Maintenance page while a "Réinitialisation" action runs. {id} is the
     * scheduled_actions row id (Core\Scheduler\SchedulerService::
     * scheduleAfter()'s return value) — reused directly rather than a
     * dedicated history table, since Core\Scheduler already tracks
     * pending/processing/done/failed/canceled.
     *
     * @param array<string, string> $params
     */
    public function resetStatus(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $action = $this->schedulerService->findById($id);
        if ($action === null) {
            return $this->json(['error' => 'Action introuvable.'], 404);
        }

        return $this->json([
            'status' => $action['status'],
            'error_message' => $action['last_error'],
        ]);
    }

    /**
     * POST /config/maintenance/auto-update/save (AJAX, JSON) — the "Mises
     * à jour automatiques" section's toggle/level/schedule, all saved
     * together on one "Enregistrer" click (module spec).
     *
     * @param array<string, string> $params
     */
    public function saveAutoUpdatePreferences(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $enabled = (bool) ($data['enabled'] ?? false);
        $level = (string) ($data['level'] ?? '');

        if (!in_array($level, self::AUTO_UPDATE_LEVELS, true)) {
            return $this->json(['success' => false, 'error' => 'Niveau de version invalide.'], 400);
        }

        $userId = AuthSession::getUserAccountId();

        // 'dev' tracks a branch installed immediately on every push, with
        // no weekly slot — mutually exclusive with the semver levels below
        // by construction of the radio group itself (module spec).
        if ($level === 'dev') {
            $branch = trim((string) ($data['branch'] ?? ''));
            if ($branch === '' || strlen($branch) > 100) {
                return $this->json(['success' => false, 'error' => 'Nom de branche invalide.'], 400);
            }

            $this->settingService->set('auto_update_enabled', $enabled ? '1' : '0');
            $this->settingService->set('auto_update_level', $level);
            $this->settingService->set('dev_update_branch', $branch);

            $this->journalService->log(
                'core', 'auto_update_settings_changed', 'security',
                'Préférences de mise à jour automatique modifiées (mode développement)',
                ['enabled' => $enabled, 'level' => $level, 'branch' => $branch], $userId
            );

            return $this->json(['success' => true]);
        }

        $day = (string) ($data['day'] ?? '');
        $time = (string) ($data['time'] ?? '');

        if (!in_array($day, self::WEEK_DAYS, true)) {
            return $this->json(['success' => false, 'error' => 'Jour invalide.'], 400);
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return $this->json(['success' => false, 'error' => 'Heure invalide (format HH:MM attendu).'], 400);
        }

        $this->settingService->set('auto_update_enabled', $enabled ? '1' : '0');
        $this->settingService->set('auto_update_level', $level);
        $this->settingService->set('auto_update_day', $day);
        $this->settingService->set('auto_update_time', $time);

        $this->journalService->log(
            'core', 'auto_update_settings_changed', 'info', 'Préférences de mise à jour automatique modifiées',
            ['enabled' => $enabled, 'level' => $level, 'day' => $day, 'time' => $time], $userId
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /api/maintenance/webhook-secret (AJAX, JSON) — generates (or
     * regenerates, replacing any existing one) the shared secret GitHub
     * signs webhook requests with. Returned in cleartext exactly once, in
     * this response — never persisted anywhere it could be read back
     * (secrets.enc only, never Core\Config\SettingService — see module
     * spec's "Ne pas stocker le secret webhook dans settings ou en code").
     *
     * @param array<string, string> $params
     */
    public function generateWebhookSecret(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $wasConfigured = $this->webhookSecret() !== '';
        $newSecret = bin2hex(random_bytes(32));

        $secrets = $this->secretManager->readSecrets();
        $secrets['github_webhook_secret'] = $newSecret;
        $this->secretManager->writeSecrets($secrets);

        $userId = AuthSession::getUserAccountId();
        $this->journalService->log(
            'core',
            $wasConfigured ? 'webhook_secret_regenerated' : 'webhook_secret_generated',
            'security',
            $wasConfigured ? 'Secret du webhook GitHub régénéré (l\'ancien webhook cessera de fonctionner)' : 'Secret du webhook GitHub généré',
            [],
            $userId
        );

        return $this->json(['success' => true, 'secret' => $newSecret]);
    }

    /**
     * Release notes (stable channel) or commit message (dev channel) for
     * the currently INSTALLED version — so an admin looking at the
     * Maintenance page can see what they're actually running, not just its
     * number/sha. Cached in settings, keyed by the exact installed-version
     * string ($raw): a page view after an update naturally lands on a
     * cache miss (the key no longer matches) and refetches once; every
     * view in between reuses the cache instead of hitting the GitHub API
     * on every single page load. Best-effort — a network hiccup here must
     * never break the page, it just leaves the notes blank for this view
     * and retries on the next one (nothing is cached on failure).
     *
     * @return array{notes: string, url: string}
     */
    private function installedVersionNotes(string $installedVersionRaw, ?string $installedVersionCommit): array
    {
        $cachedFor = (string) ($this->settingService->get('installed_version_notes_for') ?: '');
        if ($cachedFor === $installedVersionRaw) {
            return [
                'notes' => (string) ($this->settingService->get('installed_version_notes') ?: ''),
                'url' => (string) ($this->settingService->get('installed_version_notes_url') ?: ''),
            ];
        }

        try {
            $client = $this->releaseClient();
            if ($installedVersionCommit !== null) {
                $commit = $client->getCommit($installedVersionCommit);
                $notes = $commit !== null ? trim($commit->message) : '';
                $url = $commit !== null ? $commit->htmlUrl : '';
            } else {
                $release = $client->getReleaseByTag($installedVersionRaw);
                $notes = $release !== null ? $release->body : '';
                $url = $release !== null ? $release->htmlUrl : '';
            }
        } catch (UpdateException) {
            return ['notes' => '', 'url' => ''];
        }

        $this->settingService->setInternal('installed_version_notes', $notes);
        $this->settingService->setInternal('installed_version_notes_url', $url);
        $this->settingService->setInternal('installed_version_notes_for', $installedVersionRaw);

        return ['notes' => $notes, 'url' => $url];
    }

    /**
     * A dev/branch install's VersionFile content is literally
     * "dev-{7-char-sha}" (Task\InstallUpdateHandler writes
     * update_history.version_to as-is — see GitHubWebhookService::
     * handlePushEvent()/installDevBranchUpdate()'s shared "dev-{sha}"
     * convention) — the commit is already tracked, just not split out for
     * display. Splits it into a clean "dev" label plus the commit shown
     * separately, rather than the raw concatenated string.
     *
     * @return array{0: string, 1: ?string} [display, commit]
     */
    private static function splitInstalledVersion(string $raw): array
    {
        if (preg_match('/^dev-([0-9a-f]{7,40})$/', $raw, $matches)) {
            return ['dev', $matches[1]];
        }

        return [$raw, null];
    }

    private function webhookSecret(): string
    {
        try {
            $secrets = $this->secretManager->readSecrets();
        } catch (\Throwable) {
            return '';
        }

        return (string) ($secrets['github_webhook_secret'] ?? '');
    }

    private function relativePath(string $absolutePath): string
    {
        return ltrim(substr($absolutePath, strlen($this->storagePath)), '/');
    }

    /**
     * Deletes (file + row) every backup beyond the 5 most recent — module
     * spec's automatic purge, run after every successful synchronous
     * database-only backup. CreateBackupHandler does the equivalent for
     * the background full-backup path.
     */
    private function purgeBeyondLimit(): void
    {
        foreach ($this->backupRepository->findBeyond(self::KEEP_BACKUPS) as $old) {
            foreach ([$old->fileId, $old->dbDumpFileId] as $fileId) {
                if ($fileId === null) {
                    continue;
                }
                $file = $this->fileRepository->findById($fileId);
                if ($file !== null) {
                    @unlink($this->storagePath . '/' . $file->relativePath);
                    $this->fileRepository->delete($fileId);
                }
            }
            $this->backupRepository->delete($old->id);
        }
    }
}
