<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

use Core\Config\AppClock;
use Core\Service\DateInput;

/**
 * The one piece of stored state behind the « Activer les notifications ? »
 * invitation the installed application offers (ARCHITECTURE.md §8.111):
 * `user_accounts.push_invitation_dismissed_at`.
 *
 * **Only the refusal is written here.** Accepting the invitation creates a
 * Web Push subscription, which belongs to one device — so a second device
 * this account installs the application on still has to be asked, and the
 * browser's own `Notification.permission` is what stops the dialog coming
 * back on the device that accepted. « Plus tard » is the opposite: it is a
 * decision about the account, taken once, after which the only way in is
 * « Mon compte ».
 *
 * No journaling: this is a display preference, not an action on the unit's
 * data — the same reading as `help_topics_seen` (§8.95).
 */
class PushInvitationRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * When this account said « Plus tard », or null when it never did —
     * which is also the answer for an account row that no longer exists.
     */
    public function dismissedAt(int $accountId): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->prepare('SELECT push_invitation_dismissed_at FROM user_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $value = $stmt->fetchColumn();

        // Same reading as SeenTopicRepository::snoozedUntil(): a stored
        // DATETIME is Belgian wall-clock time (AppClock), and an empty or
        // malformed column must read as "never answered" rather than as
        // the current moment, which is what `new DateTimeImmutable('')`
        // would answer.
        return DateInput::fromStorage(is_string($value) ? $value : null, AppClock::zone());
    }

    /**
     * Records « Plus tard » — after which the invitation is never offered
     * to this account again on any device.
     *
     * Idempotent by shape rather than by a guard: a second click writes a
     * later instant over an earlier one, and the column is read as a
     * boolean question ("has this been answered"), never as a delay.
     */
    public function dismiss(int $accountId): void
    {
        $stmt = $this->pdo->prepare('UPDATE user_accounts SET push_invitation_dismissed_at = ? WHERE id = ?');
        $stmt->execute([AppClock::now()->format('Y-m-d H:i:s'), $accountId]);
    }
}
