<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert;

/**
 * Every operational alert key, and the French surface it is about.
 *
 * This exists because {@see OperationalAttentionProvider} reads *rows*,
 * not checks: it renders whatever the last pass left in
 * `operational_alerts`, and a row carries a key and a reading but no
 * words. Constructing all twelve checks just to ask each one its label would
 * mean building a `DiskBudget` and a `CronHealth` on every render of a
 * page that only wants to print « Espace disque ».
 *
 * The keys are `const` on the checks themselves, so this map cannot drift
 * from them without failing to compile — and
 * `Tests\Core\Alert\AlertSurfacesTest` pins that every shipped check
 * appears here, which is the half a constant reference cannot check.
 */
final class AlertSurfaces
{
    /** @return array<string, string> alert key => French surface name */
    public static function labels(): array
    {
        return [
            Check\DiskUsageCheck::KEY => 'Espace disque',
            Check\BackupAgeCheck::KEY => 'Sauvegardes',
            Check\MailDeliveryCheck::KEY => 'Envoi d\'e-mails',
            Check\DevelopmentModeCheck::KEY => 'Mises à jour automatiques',
            Check\CronSilenceCheck::KEY => 'Tâche planifiée',
            Check\HttpsCheck::KEY => 'Connexion sécurisée',
            Check\BackupIntegrityCheck::KEY => 'Intégrité des sauvegardes',
            Check\PortableBackupLingerCheck::KEY => 'Sauvegarde portable',
            Check\RemoteBackupAgeCheck::KEY => 'Sauvegarde hors site',
            Check\RemoteQuotaCheck::KEY => 'Espace hors site',
            Check\AuthenticationLaneCheck::KEY => 'Voie d\'authentification',
            Check\DeferredMailBacklogCheck::KEY => 'Messages différés',
        ];
    }

    /**
     * The alerts whose attention point must NOT send the reader to the
     * maintenance page, and where it sends them instead.
     *
     * **Only the exceptions are listed.** For eleven of the twelve checks
     * the maintenance page is the right destination — it is where the
     * disk figure, the backup age and the cron stamp all live, so a
     * reader arrives at the thing the alert is about. The twelfth is the
     * one issue #352 is about: « Le site est servi en HTTP » has two
     * causes calling for opposite gestures, and the maintenance page only
     * restates the reading the reader has just read. It needs the help
     * topic that can tell the two apart.
     *
     * This map exists for the same reason {@see labels()} does, and it is
     * not a duplicate of what the check declares: the check's own
     * `AlertReading` reaches the **notification**, which
     * {@see OperationalAlertService::notify()} sends once, on the
     * armed→triggered transition. {@see OperationalAttentionProvider}
     * renders stored rows and constructs no check, so nothing a check
     * returns can reach it. An installation already sitting on a
     * triggered alert — precisely #352's population, which cannot re-arm
     * before applying the fix the topic explains — would otherwise never
     * see the new destination on either surface.
     *
     * The values are `const` on the checks themselves, so this cannot
     * drift from what the notification says without failing to compile.
     *
     * @return array<string, array{path: string, label: string, why: string}>
     */
    public static function destinations(): array
    {
        return [
            Check\HttpsCheck::KEY => [
                'path' => Check\HttpsCheck::HELP_PATH,
                'label' => Check\HttpsCheck::HELP_LABEL,
                'why' => Check\HttpsCheck::ATTENTION_WHY,
            ],
        ];
    }
}
