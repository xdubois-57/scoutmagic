<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;

/**
 * « La capture des e-mails est armée mais n'agit plus » (issue #1469).
 *
 * A core contributor and not the test toolbox's, because the situation it
 * reports is very often the toolbox not loading at all — a module that is
 * not loaded contributes no attention point. One urgent point per blocked
 * channel ({@see SandboxLossState}), read from one settings row each: as
 * cheap as AttentionPointProvider asks.
 *
 * Nothing while released: the superadmin who reopened real sending has
 * just read the same sentence on the decision page, and the journal keeps
 * the decision.
 */
final class SandboxLossAttentionProvider implements AttentionPointProvider
{
    public function __construct(private readonly SandboxLossState $state)
    {
    }

    public function sourceLabel(): string
    {
        return 'Cœur';
    }

    /** @return AttentionPoint[] */
    public function collect(int $scoutYearId): array
    {
        $points = [];

        $mailReason = $this->state->isBlocked(SandboxLossState::CHANNEL_MAIL)
            ? $this->state->reason(SandboxLossState::CHANNEL_MAIL)
            : null;
        if ($mailReason !== null) {
            $points[] = new AttentionPoint(
                title: 'La capture des e-mails est armée mais n\'agit plus : l\'envoi est suspendu',
                why: 'Raison : ' . SandboxLossState::reasonLabel($mailReason) . '. Plutôt que de renvoyer les '
                    . 'messages vers les vraies adresses des membres et des parents, le site n\'envoie plus aucun '
                    . 'e-mail tant qu\'un super-administrateur n\'a pas décidé.',
                actionLabel: 'Décider',
                actionUrl: SandboxLossState::PAGE_URL,
                severity: AttentionPoint::SEVERITY_URGENT
            );
        }

        $telephonyReason = $this->state->isBlocked(SandboxLossState::CHANNEL_TELEPHONY)
            ? $this->state->reason(SandboxLossState::CHANNEL_TELEPHONY)
            : null;
        if ($telephonyReason !== null) {
            $points[] = new AttentionPoint(
                title: 'La téléphonie simulée est armée mais n\'agit plus : le téléphone d\'urgence est suspendu',
                why: 'Raison : ' . SandboxLossState::reasonLabel($telephonyReason) . '. Plutôt que de reprendre '
                    . 'la main sur la vraie ligne SOS, le site ne la pilote plus tant qu\'un super-administrateur '
                    . 'n\'a pas décidé.',
                actionLabel: 'Décider',
                actionUrl: SandboxLossState::PAGE_URL,
                severity: AttentionPoint::SEVERITY_URGENT
            );
        }

        return $points;
    }
}
