<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\Rental\Mail\RentalMessageConsumer;

/**
 * « Du courrier attend une décision sur une réservation » (§8.79).
 *
 * A proposition is answered on the booking by its managers, and a
 * manager who is away leaves it standing: the message is then filed
 * nowhere, and the retention clock runs on it. The chef d'unité's
 * attention page is where that becomes visible at the unit's level.
 */
class RentalAttentionProvider implements AttentionPointProvider
{
    public function __construct(private InboundMailInterface $inboundMail)
    {
    }

    public function sourceLabel(): string
    {
        return 'Locations';
    }

    public function collect(int $scoutYearId): array
    {
        $waiting = $this->inboundMail->countCandidatesFor(RentalMessageConsumer::CONSUMER_ID);
        if ($waiting < 1) {
            return [];
        }

        $plural = $waiting > 1;

        return [new AttentionPoint(
            title: $waiting . ' message' . ($plural ? 's' : '') . ' reçu' . ($plural ? 's' : '')
                . ' attend' . ($plural ? 'ent' : '') . ' une décision sur une réservation',
            why: 'Chaque proposition est visible sur la réservation concernée par ses gestionnaires. '
                . 'Tant qu\'elle n\'est pas tranchée, le message n\'est classé nulle part et sera '
                . 'supprimé au terme du délai de conservation du courrier.',
            actionLabel: 'Ouvrir le courrier',
            actionUrl: '/courrier?association=proposed',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }
}
