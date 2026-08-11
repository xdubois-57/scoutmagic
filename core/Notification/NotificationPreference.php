<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

/**
 * A member's channel overrides for one notification type. Each channel is
 * independently nullable — null means "not customized, still follows the
 * type's declared default" (see schema/core.sql's notification_preferences
 * comment). Only ever constructed for a row that actually exists; the
 * "no row" case (every channel at its type default) is represented by the
 * absence of an instance, not by an all-null one — see
 * NotificationPreferenceRepository::find().
 */
class NotificationPreference
{
    public function __construct(
        public readonly int $userAccountId,
        public readonly string $typeId,
        public readonly ?bool $inApp,
        public readonly ?bool $push,
        public readonly ?bool $email
    ) {
    }
}
