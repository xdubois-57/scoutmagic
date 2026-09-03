<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\InboundMail\Api\InboundMailInterface;

/**
 * What the camps mail leaves for somebody to decide (§8.79): messages
 * proposed towards a stay and not yet settled, and stays « à confirmer »
 * — the state a stay created from a message is born in, and the state
 * a chief's own draft stays in until they say so.
 */
class CampsAttentionProvider implements AttentionPointProvider
{
    public function __construct(
        private CampRepository $camps,
        private ?InboundMailInterface $inboundMail = null
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Camps';
    }

    public function collect(int $scoutYearId): array
    {
        $points = [];

        $waiting = $this->inboundMail?->countCandidatesFor(CampsMessageConsumer::CONSUMER_ID) ?? 0;
        if ($waiting > 0) {
            $plural = $waiting > 1;
            $points[] = new AttentionPoint(
                title: $waiting . ' message' . ($plural ? 's' : '') . ' reçu' . ($plural ? 's' : '')
                    . ' attend' . ($plural ? 'ent' : '') . ' une décision sur un séjour',
                why: 'Le site hésite entre plusieurs séjours et n\'en choisit aucun. Tant que personne '
                    . 'ne tranche, le message n\'est classé nulle part.',
                actionLabel: 'Ouvrir le courrier des camps',
                actionUrl: '/chefs/camps/courrier',
                severity: AttentionPoint::SEVERITY_ATTENTION
            );
        }

        $toConfirm = $this->camps->countByStatus(Camp::STATUS_TO_CONFIRM);
        if ($toConfirm > 0) {
            $plural = $toConfirm > 1;
            $points[] = new AttentionPoint(
                title: $toConfirm . ' séjour' . ($plural ? 's' : '') . ' « à confirmer »',
                why: 'Un séjour créé depuis un message, ou encodé en attendant une réponse, reste dans '
                    . 'cet état jusqu\'à ce qu\'un chef le confirme ou le supprime.',
                actionLabel: 'Ouvrir les camps',
                actionUrl: '/chefs/camps',
                severity: AttentionPoint::SEVERITY_ATTENTION
            );
        }

        return $points;
    }
}
