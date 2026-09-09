<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;

/**
 * Publishes every currently-triggered operational alert onto the
 * attention-points page (§8.79).
 *
 * **The notification and this are the same check, run once.** The
 * notification says « this has just tipped over » and is gone once read;
 * this says « this is still true » and disappears only when the check
 * stops tripping. That is why this provider computes nothing: it reads the
 * rows the scheduled pass wrote, and the state machine that wrote them is
 * the single place a threshold is ever compared.
 *
 * It is also the answer to the one real weakness of a notification: an
 * administrator who missed the message, or joined the unit afterwards, has
 * nowhere to learn that the disk has been full for a fortnight.
 *
 * **The audiences differ on purpose.** The notification is `superadmin` —
 * it leaves the site, and the person who can pay for more hosting is the
 * one who should get an e-mail at midnight. The attention page is `admin`,
 * so a chef d'unité sees the same facts on a page they already read
 * without being paged about them. Nothing here is a secret; these are
 * statements about the installation, not about anybody's data.
 */
final class OperationalAttentionProvider implements AttentionPointProvider
{
    /** @param array<string, string> $labels alert key => French surface name */
    public function __construct(
        private readonly OperationalAlertRepository $repository,
        private readonly array $labels = []
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Cœur';
    }

    /**
     * Every triggered alert, oldest first.
     *
     * `$scoutYearId` is ignored, and that is not an oversight: an
     * operational alert is a fact about the installation, not about a
     * scout year. The disk does not empty itself in September.
     *
     * @return AttentionPoint[]
     */
    public function collect(int $scoutYearId): array
    {
        $points = [];

        foreach ($this->repository->findTriggered() as $alert) {
            $surface = $this->labels[$alert->alertKey] ?? null;

            $points[] = new AttentionPoint(
                title: $this->title($alert, $surface),
                why: 'Le site l\'a signalé aux administrateurs et le répète ici tant que c\'est vrai. '
                    . 'La page Maintenance en dit le détail.',
                actionLabel: 'Ouvrir la maintenance',
                actionUrl: '/config/maintenance',
                severity: AttentionPoint::SEVERITY_URGENT
            );
        }

        return $points;
    }

    /**
     * The stored reading is what makes this readable — « Espace disque :
     * 92 % » rather than « Espace disque ». `last_value` is written on
     * every pass precisely so this sentence can exist without the page
     * re-running any check.
     */
    private function title(OperationalAlert $alert, ?string $surface): string
    {
        $name = $surface ?? $alert->alertKey;

        return $alert->lastValue !== null && $alert->lastValue !== ''
            ? $name . ' : ' . $alert->lastValue
            : $name;
    }
}
