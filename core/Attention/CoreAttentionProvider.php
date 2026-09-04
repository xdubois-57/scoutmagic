<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Attention;

/**
 * The core's own contributions to the attention-points page.
 *
 * Four states of the unit, none of them stored, each of them true until
 * somebody makes it false:
 *
 * - a badge nobody holds — a Trésorier, an Infirmier, a « Référent
 *   {section} » who left, leaving a job with no owner and no screen
 *   saying so;
 * - a Desk function still unqualified, whose holders therefore see
 *   nothing more than an ordinary member;
 * - departure flags still raised on members Desk still lists — who the
 *   federation therefore still bills;
 * - active members with no function at all, who belong nowhere.
 */
class CoreAttentionProvider implements AttentionPointProvider
{
    public function __construct(private CoreAttentionRepository $repository)
    {
    }

    public function sourceLabel(): string
    {
        return 'Cœur';
    }

    public function collect(int $scoutYearId): array
    {
        return array_merge(
            $this->unheldBadges($scoutYearId),
            $this->unconfirmedFunctions(),
            $this->staleLeavingFlags($scoutYearId),
            $this->membersWithoutFunction($scoutYearId)
        );
    }

    /** @return AttentionPoint[] */
    private function unheldBadges(int $scoutYearId): array
    {
        $points = [];
        foreach ($this->repository->findUnheldActiveBadges($scoutYearId) as $badge) {
            $points[] = new AttentionPoint(
                title: 'Le badge ' . $badge['name'] . " n'est porté par personne",
                why: "Ce badge désigne une responsabilité dans l'unité. Tant qu'il n'est attribué à "
                    . 'personne, ce qui en dépend reste sans titulaire, et rien d\'autre sur le site ne le signale.',
                actionLabel: 'Attribuer le badge',
                actionUrl: '/chefs/staffs',
                severity: AttentionPoint::SEVERITY_ATTENTION
            );
        }

        return $points;
    }

    /** @return AttentionPoint[] */
    private function unconfirmedFunctions(): array
    {
        $functions = $this->repository->findUnconfirmedFunctions();
        if ($functions === []) {
            return [];
        }

        $labels = array_map(static fn(array $f): string => '« ' . $f['label'] . ' »', $functions);
        $count = count($functions);

        return [new AttentionPoint(
            title: $count
                . ' fonction'
                . ($count > 1 ? 's attendent' : ' attend')
                . " d'être qualifiée"
                . ($count > 1 ? 's' : ''),
            why: implode(', ', $labels) . ($count > 1 ? ' sont arrivées' : ' est arrivée')
                . ' au rôle minimum. Les personnes concernées ne voient rien de plus qu\'un membre ordinaire.',
            actionLabel: 'Qualifier dans Config Desk',
            actionUrl: '/config/functions',
            severity: AttentionPoint::SEVERITY_URGENT
        )];
    }

    /** @return AttentionPoint[] */
    private function staleLeavingFlags(int $scoutYearId): array
    {
        $count = $this->repository->countLeavingButStillActive($scoutYearId);
        if ($count === 0) {
            return [];
        }

        return [new AttentionPoint(
            title: $count
                . ' membre'
                . ($count > 1 ? 's annoncés partants sont' : ' annoncé partant est')
                . ' toujours actif'
                . ($count > 1 ? 's' : '')
                . ' dans Desk',
            why: 'Le drapeau a été posé sur le site, mais Desk les liste encore. Ils seront facturés '
                . "par la fédération tant que Desk n'est pas mis à jour.",
            actionLabel: 'Voir les membres',
            actionUrl: '/chefs/membres',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }

    /** @return AttentionPoint[] */
    private function membersWithoutFunction(int $scoutYearId): array
    {
        $count = $this->repository->countActiveWithoutFunction($scoutYearId);
        if ($count === 0) {
            return [];
        }

        return [new AttentionPoint(
            title: $count . ' membre' . ($count > 1 ? 's actifs n\'ont' : ' actif n\'a') . ' ni fonction ni section',
            why: "Aucun sélecteur de section ne les liste et leur page personnelle est vide : pour le "
                . "site, ils existent sans appartenir à quoi que ce soit. C'est presque toujours un "
                . "encodage Desk resté inachevé.",
            actionLabel: 'Voir les membres',
            actionUrl: '/chefs/membres',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }
}
