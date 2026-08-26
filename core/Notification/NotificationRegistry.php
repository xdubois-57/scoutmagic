<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

/**
 * Core-declared notification types — the exact parallel of
 * Core\Cookie\CookieRegistry for cookies. Module types are declared in
 * their own module.json "notifications" section instead
 * (Core\Module\ModuleManifest::validateNotification()) and aggregated
 * alongside these by Core\Module\ModuleManager.
 */
class NotificationRegistry
{
    /**
     * @return NotificationType[]
     */
    public static function getCoreTypes(): array
    {
        return [
            new NotificationType(
                id: 'core.backup_completed',
                label: 'Sauvegarde terminée',
                description: 'Quand une sauvegarde que tu as demandée est prête au téléchargement',
                group: 'Maintenance',
                roleMin: 'admin',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off']
            ),
            new NotificationType(
                id: 'core.backup_failed',
                label: 'Échec de sauvegarde',
                description: 'Quand une sauvegarde que tu as demandée a échoué',
                group: 'Maintenance',
                roleMin: 'admin',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off']
            ),
            // The two automatic-update notices. Only ever dispatched by
            // Core\Maintenance\Task\InstallUpdateHandler for an install
            // NOBODY requested — a webhook-triggered release or dev-branch
            // build (update_history.requested_by null). A manual
            // "Installer maintenant" still notifies its own requester
            // directly and only them, so an admin who watched the install
            // happen is never told about it twice.
            //
            // role_min 'admin' with default_on_role_min 'superadmin':
            // whoever runs the site wants to know unprompted that its code
            // changed under it, while an admin gets the same switches on
            // /notifications/preferences with nothing switched on for them
            // (NotificationType::defaultsOnForRole()).
            new NotificationType(
                id: 'core.update_installed',
                label: 'Mise à jour installée',
                description: 'Quand une mise à jour du site s\'installe automatiquement (nouvelle version ou build de développement)',
                group: 'Maintenance',
                roleMin: 'admin',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
                defaultOnRoleMin: 'superadmin'
            ),
            new NotificationType(
                id: 'core.update_failed',
                label: 'Échec de mise à jour',
                description: 'Quand une mise à jour automatique du site échoue, avec ou sans restauration de la version précédente',
                group: 'Maintenance',
                roleMin: 'admin',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
                defaultOnRoleMin: 'superadmin'
            ),
            new NotificationType(
                id: 'core.support_package_ready',
                label: 'Paquet de support prêt',
                description: 'Quand l\'archive de diagnostic que tu as demandée est prête au téléchargement',
                group: 'Maintenance',
                roleMin: 'superadmin',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off']
            ),
            // Not disableable on any channel a member could hide — an
            // account holder must always see a security alert concerning
            // their own account, in-app and by push. Email stays optional
            // (member decides) since it's a slower, additional channel,
            // not the primary alerting path.
            new NotificationType(
                id: 'core.security_alert',
                label: 'Alerte de sécurité',
                description: "Évènement de sécurité concernant ton compte (ex : nouvelle connexion, changement de mot de passe)",
                group: 'Sécurité',
                roleMin: 'identified',
                channels: ['in_app' => 'on', 'push' => 'on', 'email' => 'default_off']
            ),
            new NotificationType(
                id: 'core.desk_import_done',
                label: 'Import Desk terminé',
                description: "Quand un import de fichier Desk que tu as lancé est terminé",
                group: 'Membres',
                roleMin: 'chief',
                channels: ['in_app' => 'default_on', 'push' => 'default_off', 'email' => 'default_off']
            ),
            // Manually triggered by a superadmin from the logo upload
            // block (Installation & serveur, /setup) after a unit logo
            // change — never automatically on every upload (see Core\
            // Http\Controller\SettingsController::notifyIosLogoUpdate()).
            // Broadcast to every account, not just iOS ones — the site has
            // no way to know which platform a given account is on.
            new NotificationType(
                id: 'core.unit_logo_updated_ios',
                label: 'Nouveau logo — réinstallation iOS',
                description: "Quand le logo de l'unité a changé et que l'administrateur invite les utilisateurs iOS à réinstaller l'application pour le voir",
                group: 'Informations',
                roleMin: 'identified',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off']
            ),
        ];
    }
}
