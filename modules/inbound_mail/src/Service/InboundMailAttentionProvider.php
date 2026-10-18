<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Config\AppClock;
use Core\Config\SettingService;
use Modules\InboundMail\Mailbox\SyncState;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Task\PurgeUnlinkedMessagesHandler;
use Modules\InboundMail\Task\SyncMailboxesHandler;

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
 * quoted in exports and screenshots, and §8.6 does not make exceptions for
 * a summary.
 *
 * Bounded, as the interface requires: two aggregate counts, no decryption,
 * no listing.
 *
 * **And the boxes themselves** (issue #718 § C points 8 and 9): one that
 * no longer synchronises, and a module switched on with no box to read.
 * Both come from what the polling task stored on each row — `sync_state`,
 * `last_synced_at`, read without decrypting anything — never from connecting: the page must stay cheap, and
 * must not fail because a mail host does. Until now a broken box said so
 * only on the superadmin's configuration page, which nobody opens while
 * mail seems to be arriving.
 */
class InboundMailAttentionProvider implements AttentionPointProvider
{
    /**
     * Below this, saying nothing is better than saying something. A chef
     * d'unité who is told about one unattributed newsletter every week
     * stops reading the page, and then stops seeing the fifty that matter.
     */
    public const THRESHOLD = 1;

    /**
     * A box is reported once it has failed for this long at least. One
     * failed pass is a mail host restarting, or a timeout; telling the
     * unit about it in red would be telling it about something already
     * over by the time anybody reads the page.
     */
    private const MIN_FAILING_SECONDS = 3600;

    /** Nor before two passes in a row have failed, however long apart. */
    private const MIN_FAILED_PASSES = 2;

    public function __construct(
        private InboundMessageRepository $messages,
        private ?SettingService $settings = null,
        /** Null leaves the boxes out — the message count stands alone. */
        private ?InboundMailboxRepository $mailboxes = null,
        /** Tests only: the moment to read a failure's age against. */
        private ?\DateTimeImmutable $now = null
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
        return array_merge($this->boxes(), $this->unattributedMail());
    }

    /**
     * @return AttentionPoint[]
     */
    private function unattributedMail(): array
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

    /**
     * @return AttentionPoint[]
     */
    private function boxes(): array
    {
        if ($this->mailboxes === null) {
            return [];
        }

        $enabled = $this->mailboxes->enabledSyncStates();
        if ($enabled === []) {
            return [new AttentionPoint(
                title: "Aucune boîte de courrier entrant n'est relevée",
                why: "Le module Courrier entrant est activé, mais aucune boîte active n'y est configurée : le "
                    . "site ne reçoit aucun message, et les modules qui classent le courrier n'ont rien à lire.",
                actionLabel: 'Configurer une boîte',
                actionUrl: '/config/courrier-entrant',
                severity: AttentionPoint::SEVERITY_ATTENTION
            )];
        }

        $now = $this->now ?? AppClock::now();
        $points = [];
        foreach ($enabled as $box) {
            if (!$this->hasStoppedSynchronising($box['state'], $box['lastSyncedAt'], $now)) {
                continue;
            }
            $points[] = new AttentionPoint(
                title: 'La boîte « ' . $box['name'] . ' » ne se synchronise plus',
                why: ($box['lastSyncedAt'] !== null
                        ? 'Sa dernière relève réussie date du ' . $box['lastSyncedAt']->format('d/m/Y à H:i') . '. '
                        : "Elle n'a encore jamais été relevée avec succès. ")
                    . "Le courrier qui y arrive n'atteint plus le site : ni la page Courrier ni les modules "
                    . "qui le classent ne le voient. La page de configuration donne l'erreur.",
                actionLabel: 'Vérifier la boîte',
                actionUrl: '/config/courrier-entrant',
                severity: AttentionPoint::SEVERITY_URGENT
            );
        }

        return $points;
    }

    /**
     * In error, and for longer than a passing failure: the last success
     * lies further back than both thresholds.
     *
     * A box in error that never once succeeded is reported at once. Its
     * `last_error_at` cannot date the trouble — every failed pass rewrites
     * it — and a box that has never worked is a configuration to fix, not
     * an outage to wait out.
     */
    private function hasStoppedSynchronising(
        SyncState $state,
        ?\DateTimeImmutable $lastSyncedAt,
        \DateTimeImmutable $now
    ): bool {
        if ($state !== SyncState::ERROR) {
            return false;
        }
        if ($lastSyncedAt === null) {
            return true;
        }

        $interval = $this->settings !== null
            ? SyncMailboxesHandler::intervalSeconds($this->settings)
            : SyncMailboxesHandler::DEFAULT_INTERVAL_MINUTES * 60;
        $grace = max(self::MIN_FAILING_SECONDS, self::MIN_FAILED_PASSES * $interval);

        return $now->getTimestamp() - $lastSyncedAt->getTimestamp() >= $grace;
    }

    private function retentionDays(): int
    {
        return $this->settings === null
            ? PurgeUnlinkedMessagesHandler::DEFAULT_RETENTION_DAYS
            : (new PurgeUnlinkedMessagesHandler())->retentionDays($this->settings);
    }
}
