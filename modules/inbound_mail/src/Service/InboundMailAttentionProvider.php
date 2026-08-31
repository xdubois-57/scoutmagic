<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Config\SettingService;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Task\PurgeUnlinkedMessagesHandler;

/**
 * « Du courrier attend d'être orienté ».
 *
 * The reason this exists at all: the general mailbox is a page a chef
 * d'unité has no daily reason to open, and the retention deletes what
 * nobody looked at. A registration sent to the wrong address would then be
 * kept for ninety days and thrown away unread — which is the old bug with
 * a slower clock. The attention page is what turns "we kept it" into
 * "somebody was told".
 *
 * **A count and a link, never a sender or a subject.** The attention page
 * is rendered for a role that may open the mail anyway, but it is also
 * quoted in exports and screenshots, and §7.9 does not make exceptions for
 * a summary.
 *
 * Bounded, as the interface requires: two aggregate counts, no decryption,
 * no listing.
 */
class InboundMailAttentionProvider implements AttentionPointProvider
{
    /**
     * Below this, saying nothing is better than saying something. A chef
     * d'unité who is told about one unattributed newsletter every week
     * stops reading the page, and then stops seeing the fifty that matter.
     */
    public const THRESHOLD = 1;

    public function __construct(
        private InboundMessageRepository $messages,
        private ?SettingService $settings = null
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Courrier entrant';
    }

    /**
     * @return AttentionPoint[]
     */
    public function collect(int $scoutYearId): array
    {
        // Automatic mail is excluded, exactly as it is on the screen this
        // links to: a newsletter nobody attributed is not somebody waiting
        // for an answer, and counting it would bury the ones who are.
        $waiting = $this->messages->countUnassociated(false);

        if ($waiting < self::THRESHOLD) {
            return [];
        }

        $days = $this->retentionDays();

        return [new AttentionPoint(
            title: $waiting . ' message' . ($waiting > 1 ? 's' : '') . ' reçu'
                . ($waiting > 1 ? 's' : '') . ' n\'' . ($waiting > 1 ? 'ont' : 'a')
                . ' encore été rattaché' . ($waiting > 1 ? 's' : '') . ' à rien',
            why: 'Ces messages sont conservés ' . $days . ' jours puis supprimés automatiquement. '
                . 'Une demande arrivée sur la mauvaise adresse est ici, et nulle part ailleurs.',
            actionLabel: 'Ouvrir le courrier',
            actionUrl: '/courrier',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }

    private function retentionDays(): int
    {
        return $this->settings === null
            ? PurgeUnlinkedMessagesHandler::DEFAULT_RETENTION_DAYS
            : (new PurgeUnlinkedMessagesHandler())->retentionDays($this->settings);
    }
}
