<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;

/**
 * Says on the attention page that a quota of the support receiver refused
 * something in the last day (issue #1113).
 *
 * A quota that refuses in silence reads, from the receiver, exactly like a
 * quiet day: no ticket arrives, and nothing says one was turned away. Both
 * quotas protect against a flood, and a flood is something a superadmin
 * wants to know is under way — while a real unit caught by it is a ticket
 * somebody may have to ask for again.
 *
 * Read from the markers {@see SupportIntakeGuard} leaves when it refuses:
 * two indexed counts, bounded as the attention page requires. The actions
 * lead to `superadmin` pages, so an administrator below that reads the
 * fact without a button (Core\Http\Controller\AttentionController).
 */
class SupportIntakeAttentionProvider implements AttentionPointProvider
{
    private const WINDOW_MINUTES = 24 * 60;

    public function __construct(private SupportIntakeGuard $guard)
    {
    }

    public function sourceLabel(): string
    {
        return 'Supervision';
    }

    public function collect(int $scoutYearId): array
    {
        $points = [];

        $registrations = $this->guard->quotaRefusalsSince(
            SupportIntakeGuard::QUOTA_NEW_INSTALLATIONS,
            self::WINDOW_MINUTES
        );
        if ($registrations > 0) {
            $points[] = new AttentionPoint(
                title: 'Des tickets venant de nouvelles installations ont été refusés',
                why: self::times($registrations) . ' au cours des dernières 24 heures, un ticket envoyé par une '
                    . "installation inconnue a été refusé : le quota de nouvelles installations était atteint ("
                    . SupportIntakeGuard::NEW_INSTALLATIONS_PER_HOUR . ' par heure, '
                    . SupportIntakeGuard::NEW_INSTALLATIONS_PER_DAY . ' par jour). Un tel volume vient le plus '
                    . "souvent d'un script : les installations déjà connues ne sont pas touchées, mais une unité "
                    . "réelle refusée devra renvoyer son ticket. Le journal du module donne l'adresse d'origine.",
                actionLabel: 'Voir les installations',
                actionUrl: '/support-dashboard',
                severity: AttentionPoint::SEVERITY_URGENT
            );
        }

        $archives = $this->guard->quotaRefusalsSince(SupportIntakeGuard::QUOTA_ARCHIVE_STORAGE, self::WINDOW_MINUTES);
        if ($archives > 0) {
            $points[] = new AttentionPoint(
                title: 'Des archives de diagnostic ont été refusées faute de place',
                why: self::times($archives) . ' au cours des dernières 24 heures, une archive jointe à un ticket a '
                    . 'été refusée : le quota de stockage des archives était atteint ('
                    . self::gigabytes(SupportIntakeGuard::ARCHIVE_QUOTA_BYTES) . ' en tout, '
                    . self::megabytes(SupportIntakeGuard::ARCHIVE_QUOTA_PER_INSTALLATION_BYTES)
                    . ' par installation). Le ticket est conservé, mais sans son archive. Clôturer les tickets '
                    . 'traités libère leurs archives après le délai de conservation.',
                actionLabel: 'Voir les tickets',
                actionUrl: '/support-dashboard/tickets',
                severity: AttentionPoint::SEVERITY_ATTENTION
            );
        }

        return $points;
    }

    private static function times(int $count): string
    {
        return $count === 1 ? 'Une fois' : $count . ' fois';
    }

    private static function gigabytes(int $bytes): string
    {
        return intdiv($bytes, 1024 * 1024 * 1024) . ' Go';
    }

    private static function megabytes(int $bytes): string
    {
        return intdiv($bytes, 1024 * 1024) . ' Mo';
    }
}
