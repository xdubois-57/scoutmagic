<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Modules\Finance\Mail\FinanceMessageConsumer;
use Modules\InboundMail\Api\InboundMailInterface;

/**
 * « Des reçus arrivés par e-mail attendent un trésorier » (§8.79).
 */
class FinanceAttentionProvider implements AttentionPointProvider
{
    public function __construct(private InboundMailInterface $inboundMail)
    {
    }

    public function sourceLabel(): string
    {
        return 'Finances';
    }

    public function collect(int $scoutYearId): array
    {
        $waiting = $this->inboundMail->countCandidatesFor(FinanceMessageConsumer::CONSUMER_ID);
        if ($waiting < 1) {
            return [];
        }

        $plural = $waiting > 1;

        return [new AttentionPoint(
            title: $waiting . ' reçu' . ($plural ? 's' : '') . ' arrivé' . ($plural ? 's' : '')
                . ' par e-mail attend' . ($plural ? 'ent' : '') . ' la confirmation d\'un trésorier',
            why: 'Rien n\'est enregistré en comptabilité tant qu\'un trésorier n\'a pas confirmé. '
                . 'Le message est conservé, sans classement, jusqu\'au terme du délai de conservation du courrier.',
            actionLabel: 'Ouvrir les reçus',
            actionUrl: '/finance/receipts',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }
}
