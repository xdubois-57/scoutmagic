<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Mail;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Notification\NotificationService;
use Core\Security\UserAccountRepository;

/**
 * Tells the unit's treasurers that a receipt arrived by e-mail and waits
 * for their confirmation on the receipts page
 * (`finance.mail_proposition`).
 *
 * The treasurers are whoever holds the treasurer badge this scout year
 * and has an account here — the same people « Courrier à trier » is
 * shown to. Nothing personal travels: the account's name, never the
 * sender, the subject or the amount.
 */
class FinanceMailNotifier
{
    public const TYPE_PROPOSITION = 'finance.mail_proposition';

    public function __construct(
        private NotificationService $notifications,
        private \PDO $pdo,
        private BadgeRepository $badges,
        private MemberBadgeRepository $memberBadges,
        private UserAccountRepository $userAccounts,
        private int $scoutYearId
    ) {
    }

    /**
     * @param string[] $accountLabels how a treasurer names each account proposed
     */
    public function proposed(array $accountLabels): void
    {
        $recipients = $this->treasurers();
        if ($recipients === [] || $accountLabels === []) {
            return;
        }

        $this->notifications->dispatch(
            self::TYPE_PROPOSITION,
            $recipients,
            [
                'title' => 'Un reçu reçu par e-mail attend votre confirmation',
                'body' => count($accountLabels) === 1
                    ? 'Une pièce jointe pourrait être un reçu du compte « ' . $accountLabels[0]
                        . ' ». Confirmez ou écartez la proposition dans « Courrier à trier ».'
                    : 'Une pièce jointe pourrait être un reçu de l\'un de ces comptes : « '
                        . implode(' », « ', $accountLabels) . ' ». Tranchez dans « Courrier à trier ».',
                'url' => '/finance/receipts',
            ]
        );
    }

    /**
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    private function treasurers(): array
    {
        $badge = $this->badges->findByName(BadgeService::BADGE_TREASURER);
        if ($badge === null || !$badge->isActive) {
            return [];
        }

        $memberYearIds = $this->memberBadges->findMemberYearIdsForBadgeAndYear($badge->id, $this->scoutYearId);
        if ($memberYearIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberYearIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT member_id, email_blind_index FROM member_years
              WHERE id IN ({$placeholders}) AND is_active = 1"
        );
        $stmt->execute(array_map('intval', $memberYearIds));

        $recipients = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $index = (string) ($row['email_blind_index'] ?? '');
            if ($index === '') {
                continue;
            }
            $account = $this->userAccounts->findByBlindIndex($index);
            if ($account === null) {
                continue;
            }
            $recipients[$account->id] ??= ['userAccountId' => $account->id, 'memberId' => (int) $row['member_id']];
        }

        return array_values($recipients);
    }
}
