<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Member\MemberProfile;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Notification\NotificationService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;

/**
 * "Le camp est fini — racontez-le au staff suivant." Sent the day after a
 * stay ends, exactly once, with no reminder.
 *
 * Once, because nobody wants a site that nags about a camp they have
 * decided not to review. That is enforced by camp_camps.
 * review_notified_at and not by the task's schedule:
 * NotificationService::dispatch() deduplicates nothing, and a
 * "yesterday only" window would lose the notification for good on any
 * installation whose cron did not run that day — which this module
 * assumes is common.
 *
 * Never for a year-only stay (there is no day after a year), and never
 * for a cancelled one (there was no camp).
 */
class ReviewNotificationService
{
    public const TYPE_ID = 'camps.review_due';

    public function __construct(
        private CampRepository $camps,
        private SectionService $sections,
        private UserAccountRepository $userAccounts,
        private EncryptionService $encryption,
        private \PDO $pdo,
        private ?NotificationService $notifications = null
    ) {
    }

    /**
     * Sends every notification that is due and returns how many stays
     * were notified.
     */
    public function dispatchDue(\DateTimeImmutable $today, string $baseUrl = ''): int
    {
        if ($this->notifications === null) {
            return 0;
        }

        $sent = 0;
        foreach ($this->camps->findAwaitingReviewNotification($today) as $camp) {
            $recipients = $this->recipientsFor($camp);

            // Nobody to tell — a stay whose sections have no animators this
            // year, or a unit with no chief account at all. Marked anyway,
            // or the task rebuilds that empty list on every single run, for
            // ever.
            if ($recipients === []) {
                $this->camps->markReviewNotified($camp->id, $today);
                continue;
            }

            // Dispatch FIRST, then the mark, and both in one transaction.
            // The other order was at-most-zero: a stay was marked notified
            // and a dispatch that then failed left it marked for good, with
            // nothing ever sent and no way to notice. This order is
            // at-least-once — the worst case is a second notification about
            // a camp, which somebody can ignore, rather than none at all.
            $this->pdo->beginTransaction();
            try {
                $this->notifications->dispatch(
                    self::TYPE_ID,
                    $recipients,
                    [
                        'title' => 'Racontez ce camp',
                        'body' => sprintf(
                            'Le séjour %s est terminé. Laissez un avis pour les staffs suivants.',
                            CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly)
                        ),
                        'url' => rtrim($baseUrl, '/') . '/chefs/camps/sejours/' . $camp->id,
                    ]
                );
                $this->camps->markReviewNotified($camp->id, $today);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * Who hears about a finished stay.
     *
     * Sections set → the animators of THOSE sections, for the scout year
     * the stay ended in. Not the current staff: the people who were there
     * are the people who know, and a year later they may have moved
     * section or left.
     *
     * No section set → the unit's chiefs only. Broadcasting to every
     * animator of the unit because nobody filled in a field would train
     * everyone to ignore the notification.
     *
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    public function recipientsFor(Camp $camp): array
    {
        $scoutYearId = $camp->endDate !== null ? $this->scoutYearIdContaining($camp->endDate) : null;
        if ($scoutYearId === null) {
            return [];
        }

        $sectionIds = $camp->sectionIds;
        if ($sectionIds === []) {
            $unitStaff = $this->sections->findByDeskCode(UnitStaffSectionService::DESK_CODE);
            if ($unitStaff === null) {
                return [];
            }
            $sectionIds = [(int) $unitStaff['id']];
        }

        $profiles = [];
        foreach ($sectionIds as $sectionId) {
            foreach ($this->sections->getSectionStaff($sectionId, $scoutYearId) as $profile) {
                $profiles[$profile->memberId] = $profile;
            }
        }

        return $this->toRecipients($profiles);
    }

    /**
     * Turns member profiles into notification recipients by matching
     * their e-mail's blind index against user_accounts — an animator with
     * no account on this site simply is not a recipient, which is the
     * honest outcome rather than a silent failure.
     *
     * @param array<int, MemberProfile> $profiles
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    private function toRecipients(array $profiles): array
    {
        $byIndex = [];
        foreach ($profiles as $profile) {
            if ($profile->email === null || trim($profile->email) === '') {
                continue;
            }
            $index = $this->encryption->blindIndex(mb_strtolower(trim($profile->email)), 'user_accounts.email');
            $byIndex[$index] = $profile->memberId;
        }
        if ($byIndex === []) {
            return [];
        }

        $recipients = [];
        foreach ($this->userAccounts->findIdsByBlindIndexes(array_keys($byIndex)) as $index => $accountId) {
            $recipients[] = ['userAccountId' => $accountId, 'memberId' => $byIndex[$index] ?? null];
        }

        return $recipients;
    }

    /**
     * The scout year a real date falls in. Null when no year covers it —
     * a camp from 2014 on an installation created in 2025 has no scout
     * year row, and inventing one would be worse than sending nothing.
     */
    private function scoutYearIdContaining(string $date): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM scout_years WHERE start_date <= ? AND end_date >= ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$date, $date]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }
}
