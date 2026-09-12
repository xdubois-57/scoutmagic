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
            // Restoring a backup and resetting the settings were this
            // registry's asymmetry: CREATING a backup had declared types,
            // the two operations that UNDO one had none, so they reached
            // their requester through NotificationService::notify() — no
            // type, no channel resolution, no row on
            // /notifications/preferences to switch off. Same shape as the
            // backup pair above, one role tighter, because that is what
            // the routes are: /config/maintenance/reset/* is superadmin
            // while /config/maintenance/backup/* is admin.
            new NotificationType(
                id: 'core.restore_completed',
                label: 'Restauration terminée',
                description: 'Quand une restauration de sauvegarde que tu as demandée est terminée',
                group: 'Maintenance',
                roleMin: 'superadmin',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off']
            ),
            new NotificationType(
                id: 'core.restore_failed',
                label: 'Échec de restauration',
                description: 'Quand une restauration de sauvegarde que tu as demandée a échoué',
                group: 'Maintenance',
                roleMin: 'superadmin',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off']
            ),
            new NotificationType(
                id: 'core.settings_reset_completed',
                label: 'Réinitialisation terminée',
                description: 'Quand la réinitialisation des paramètres que tu as demandée est terminée',
                group: 'Maintenance',
                roleMin: 'superadmin',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off']
            ),
            new NotificationType(
                id: 'core.settings_reset_failed',
                label: 'Échec de réinitialisation',
                description: 'Quand la réinitialisation des paramètres que tu as demandée a échoué',
                group: 'Maintenance',
                roleMin: 'superadmin',
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
                description: 'Quand une mise à jour du site s\'installe automatiquement (nouvelle version ou build de '
                    . 'développement)',
                group: 'Maintenance',
                roleMin: 'admin',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
                defaultOnRoleMin: 'superadmin'
            ),
            new NotificationType(
                id: 'core.update_failed',
                label: 'Échec de mise à jour',
                description: 'Quand une mise à jour automatique du site échoue, avec ou sans restauration de la '
                    . 'version précédente',
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
                description: "Évènement de sécurité concernant ton compte (ex : nouvelle connexion, changement de mot "
                    . "de passe)",
                group: 'Sécurité',
                roleMin: 'identified',
                channels: ['in_app' => 'on', 'push' => 'on', 'email' => 'default_off']
            ),
            // The operational alerts (Core\Alert, ARCHITECTURE.md §8.99).
            //
            // **E-mail is default_on here, and that is the point.** Every
            // other Maintenance type leaves it off, because those answer
            // somebody who just clicked something and will see the bell.
            // These are the opposite: if the disk is full or the cron has
            // stopped, nobody is visiting the site to notice a badge. The
            // alert has to leave the site to be worth having.
            //
            // NotificationService::dispatch() re-checks each recipient's
            // CURRENT role against role_min, so `superadmin` holds without
            // any hand-filtering at the call site.
            //
            // **And it leaves during the dispatch rather than through the
            // queue** ($deliversImmediately). One of these alerts reports
            // that the scheduler has stopped, and the queue is drained by
            // that scheduler: `core/send_notification_emails` waited
            // behind the very failure it described, so the mail arrived —
            // when it arrived — as old news about a cron somebody had
            // already repaired by hand (issue #296). The whole type is
            // declared this way rather than the one check, because every
            // alert in it is about the site's own machinery, fires only
            // on the armed → triggered transition, and goes to
            // superadmins alone: a handful of messages, at most, on the
            // rarest event the site has.
            new NotificationType(
                id: 'core.operational_alert',
                label: 'Alerte opérationnelle',
                description: "Quand quelque chose ne va plus sur le site lui-même : disque presque plein, "
                    . "sauvegardes trop anciennes, tâche planifiée arrêtée",
                group: 'Maintenance',
                roleMin: 'superadmin',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_on'],
                deliversImmediately: true
            ),
            // The same alert, for the one check that is ABOUT e-mail.
            //
            // Its e-mail channel is `off` rather than `default_off`: a
            // locked value, which no preference can turn on. Sending « vos
            // e-mails ne partent plus » by e-mail is not merely useless —
            // the attempt fails, and that failure is itself journaled as
            // `mail_send_failed`, so the alert would inflate the very
            // count MailDeliveryCheck reads. A separate type is the honest
            // way to say "this one cannot use that channel", and it shows
            // on the preferences page as a row with no e-mail box, which
            // is exactly true.
            //
            // It does NOT deliver immediately, and the asymmetry with its
            // sibling above is deliberate rather than an oversight. The
            // check behind it (Core\Alert\Check\MailDeliveryCheck) runs in
            // the scheduled task, so a site that can raise this alert at
            // all has a scheduler running to drain the queue — the
            // deadlock issue #296 describes cannot occur here. Its one
            // outbound channel is push, and a push that waits for the next
            // scheduler pass costs a minute.
            new NotificationType(
                id: 'core.operational_alert_mail',
                label: 'Alerte opérationnelle — envoi d\'e-mails',
                description: "Quand les envois d'e-mail échouent. Cette alerte-là ne peut pas partir par "
                    . "e-mail : elle arrive dans la cloche et dans les points d'attention",
                group: 'Maintenance',
                roleMin: 'superadmin',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'off']
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
                description: "Quand le logo de l'unité a changé et que l'administrateur invite les utilisateurs iOS à "
                    . "réinstaller l'application pour le voir",
                group: 'Informations',
                roleMin: 'identified',
                channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off']
            ),
        ];
    }
}
