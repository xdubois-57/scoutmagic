<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

class NotificationPreferenceRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function find(int $userAccountId, string $typeId): ?NotificationPreference
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notification_preferences WHERE user_account_id = ? AND type_id = ?'
        );
        $stmt->execute([$userAccountId, $typeId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Every customized type for this account — the preferences page loads
     * this once and overlays it on the full declared-type list, rather
     * than one query per type.
     *
     * @return array<string, NotificationPreference> keyed by type_id
     */
    public function findAllForUser(int $userAccountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notification_preferences WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $pref = $this->hydrate($row);
            $result[$pref->typeId] = $pref;
        }

        return $result;
    }

    /**
     * Upserts a single channel's override for (user, type), leaving the
     * other two channels of the same row untouched — a member toggling
     * only "push" must not implicitly pin "in_app"/"email" to their
     * current default. $value null clears the override back to "follow
     * the type default".
     */
    public function setChannel(int $userAccountId, string $typeId, string $channel, ?bool $value): void
    {
        if (!in_array($channel, ['in_app', 'push', 'email'], true)) {
            throw new \InvalidArgumentException("Unknown channel '{$channel}'");
        }

        $dbValue = $value === null ? null : ($value ? 1 : 0);

        $stmt = $this->pdo->prepare('SELECT id FROM notification_preferences WHERE user_account_id = ? AND type_id = '
            . '?');
        $stmt->execute([$userAccountId, $typeId]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            $stmt = $this->pdo->prepare("UPDATE notification_preferences SET {$channel} = ?, updated_at = "
                . "CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$dbValue, $existingId]);

            return;
        }

        $stmt = $this->pdo->prepare("INSERT INTO notification_preferences (user_account_id, type_id, {$channel}) "
            . "VALUES (?, ?, ?)");
        $stmt->execute([$userAccountId, $typeId, $dbValue]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NotificationPreference
    {
        return new NotificationPreference(
            userAccountId: (int) $row['user_account_id'],
            typeId: (string) $row['type_id'],
            inApp: $row['in_app'] !== null ? (bool) $row['in_app'] : null,
            push: $row['push'] !== null ? (bool) $row['push'] : null,
            email: $row['email'] !== null ? (bool) $row['email'] : null
        );
    }
}
