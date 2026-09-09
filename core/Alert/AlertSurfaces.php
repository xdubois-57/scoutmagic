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
 * words. Constructing all six checks just to ask each one its label would
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
        ];
    }
}
