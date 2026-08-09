<?php

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
            // block (Configuration avancée, /setup) after a unit logo
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
