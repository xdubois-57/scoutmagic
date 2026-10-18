<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Modules\Groups\Repository\UnsettledReportRepository;

/**
 * Reported messages nobody has answered yet (issue #718 § C point 6): one
 * point per group, counting what still sits on that group's « Signalements »
 * page — see UnsettledReportRepository for what "answered" means.
 *
 * Only a notification announces them (`groups.item_reported`, to the
 * group's moderators), and a notification read and forgotten leaves no
 * other place saying something waits.
 */
class GroupsAttentionProvider implements AttentionPointProvider
{
    public function __construct(private UnsettledReportRepository $reports)
    {
    }

    public function sourceLabel(): string
    {
        return 'Discussions';
    }

    public function collect(int $scoutYearId): array
    {
        $points = [];
        foreach ($this->reports->countsByGroup() as $group) {
            $count = $group['items'];
            $points[] = new AttentionPoint(
                title: $count === 1
                    ? 'Un contenu signalé attend une décision dans « ' . $group['group_name'] . ' »'
                    : $count . ' contenus signalés attendent une décision dans « ' . $group['group_name'] . ' »',
                why: "Des membres l'ont signalé et aucun modérateur n'a encore tranché : rétablir le contenu, ou "
                    . "le supprimer. Masqué ou non, il reste sur la page des signalements du groupe tant que "
                    . 'personne ne l\'a fait.',
                actionLabel: 'Voir les signalements',
                actionUrl: '/groups/' . $group['group_id'] . '/reports',
                severity: AttentionPoint::SEVERITY_ATTENTION
            );
        }

        return $points;
    }
}
