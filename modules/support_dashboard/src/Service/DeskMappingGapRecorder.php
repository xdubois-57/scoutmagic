<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Core\Service\TextNormalizerService;
use Modules\SupportDashboard\Repository\DeskMappingGapRepository;

/**
 * The half of issue #356 that happens when a report ARRIVES: remember a
 * Desk value this receiver had never seen, and announce it once.
 *
 * Everything the page shows is derived on read; this writes the three
 * facts that cannot be (D6), and it is also the only place a notification
 * can be decided from — a page nobody opens is a page that announces
 * nothing, and « le mainteneur n'apprend un problème que si quelqu'un le
 * lui rapporte » is the whole problem this chantier exists for.
 *
 * **One notification per value, ever** (D8). The row's `notified_at` is
 * what enforces it: without it the same value would be announced every
 * morning for as long as one installation kept reporting it, which is how
 * a notification becomes something people turn off.
 */
class DeskMappingGapRecorder
{
    public const NOTIFICATION_DESK_MAPPING_UNKNOWN = 'support_dashboard.desk_mapping_unknown';

    public function __construct(
        private DeskMappingGapRepository $gaps,
        private JournalService $journal,
        private ?NotificationService $notifications = null
    ) {
    }

    /**
     * @param array<string, mixed> $payload the report exactly as accepted
     */
    public function record(array $payload): void
    {
        $newIds = [];

        foreach (DeskMappingGapReport::unresolvedIn($payload) as [$kind, $value]) {
            // Filtered with the SAME question the page asks: a value this
            // receiver's own code already knows is one a released version
            // has fixed, and announcing it would mean one notification per
            // installation that has not upgraded yet.
            if (DeskMappingGapReport::recognisedByThisVersion($kind, $value)) {
                continue;
            }

            if ($this->gaps->rememberIfNew($kind, TextNormalizerService::fold($value), $value)) {
                $newIds[] = $kind . ' « ' . $value . ' »';
            }
        }

        if ($newIds === []) {
            return;
        }

        $this->journal->log(
            'support_dashboard',
            'desk_mapping_unknown',
            'info',
            count($newIds) . ' correspondance(s) Desk inconnue(s) signalée(s) pour la première fois',
            ['values' => $newIds]
        );

        $this->announce(count($newIds));
    }

    /**
     * Generic, and pointing at the page rather than at the dashboard (D8):
     * the message says how many values are new and nothing about which, so
     * that a push notification on somebody's phone never carries a
     * federation's vocabulary around.
     */
    private function announce(int $newCount): void
    {
        if ($this->notifications === null) {
            return;
        }

        try {
            $recipients = $this->notifications->recipientsForType(self::NOTIFICATION_DESK_MAPPING_UNKNOWN);
            if ($recipients === []) {
                // Nobody to tell — so nothing has been announced, and the
                // rows must stay un-notified rather than be marked as
                // though they had been.
                return;
            }

            $this->notifications->dispatch(
                self::NOTIFICATION_DESK_MAPPING_UNKNOWN,
                $recipients,
                [
                    'title' => 'Correspondances Desk inconnues',
                    'body' => $newCount > 1
                        ? $newCount . ' nouvelles valeurs que ce code ne reconnaît pas'
                        : 'Une nouvelle valeur que ce code ne reconnaît pas',
                    'url' => '/support-dashboard/correspondances',
                ]
            );

            $this->gaps->markNotified($this->gaps->idsAwaitingNotification());
        } catch (\Throwable $e) {
            // Same posture as the ticket notification: a receiver whose
            // push keys are misconfigured must not start refusing reports
            // over it. The rows keep `notified_at` null, so the next
            // report announces them.
            $this->journal->log(
                'support_dashboard',
                'desk_mapping_notification_failed',
                'warning',
                "Correspondance Desk inconnue enregistrée, mais la notification n'a pas pu être envoyée",
                ['error' => $e->getMessage()]
            );
        }
    }
}
