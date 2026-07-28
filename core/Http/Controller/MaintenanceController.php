<?php

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
use Core\Maintenance\UpdateHistoryRepository;
use Core\Maintenance\VersionFile;
use Core\Module\ModuleManager;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Twig\Environment;

/**
 * Configuration > Maintenance: "Mise à jour", "Sauvegardes",
 * "Réinitialisation" (in that page order) sections.
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
        private string $storagePath
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $latestVersion = (string) ($this->settingService->get('update_latest_version') ?: '');
        $installedVersion = VersionFile::read(dirname($this->storagePath));
        $updateAvailable = $latestVersion !== '' && version_compare($latestVersion, $installedVersion, '>');

        return $this->render('config/maintenance.html.twig', [
            'installed_version' => $installedVersion,
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
        ]);
    }

    /**
     * POST /config/maintenance/update/install (AJAX, JSON) — schedules the
     * background installation (Task\InstallUpdateHandler) and returns
     * immediately; the page polls updateStatus() for progress. Re-validates
     * server-side that a newer version is actually available from the
     * cached check result — never trusts the client's own idea of the
     * target version.
     *
     * @param array<string, string> $params
     */
    public function installUpdate(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $latestVersion = (string) ($this->settingService->get('update_latest_version') ?: '');
        $downloadUrl = (string) ($this->settingService->get('update_download_url') ?: '');
        $installedVersion = VersionFile::read(dirname($this->storagePath));

        if ($latestVersion === '' || $downloadUrl === '' || !version_compare($latestVersion, $installedVersion, '>')) {
            return $this->json(['success' => false, 'error' => 'Aucune mise à jour disponible.'], 400);
        }

        $dependenciesChanged = (bool) ((int) ($this->settingService->get('update_dependencies_changed') ?: '0'));
        $userId = AuthSession::getUserAccountId();

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
