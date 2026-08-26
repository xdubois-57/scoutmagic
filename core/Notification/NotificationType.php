<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

use Core\Security\Role;

/**
 * A declared notification type — the single source of truth a type is
 * ever described from, whether it's a core type (Core\Notification\
 * NotificationRegistry) or a module type (module.json "notifications"
 * section, aggregated by Core\Module\ModuleManager into
 * NotificationService::getAllDeclaredTypes()). Never sent ad-hoc:
 * NotificationService::dispatch() rejects any type_id it can't resolve
 * here — same fail-safe spirit as a route with no role_min
 * (ARCHITECTURE.md §7.1).
 */
class NotificationType
{
    private const LOCKED_VALUES = ['on', 'off'];
    private const ENABLED_VALUES = ['on', 'default_on'];

    /**
     * @param array{in_app: string, push: string, email: string} $channels each value one of "on"/"off"/"default_on"/"default_off"
     * @param ?string $defaultOnRoleMin role at or above which a "default_on" channel actually starts on — see defaultsOnForRole()
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description,
        public readonly string $group,
        public readonly string $roleMin,
        public readonly array $channels,
        public readonly ?string $defaultOnRoleMin = null
    ) {
    }

    /**
     * Whether this type's "default_on" channels actually start on for
     * $role. Null (the usual case) means "for everybody the type is
     * offered to at all" — the declared defaults apply as-is to every
     * role from $roleMin up.
     *
     * A type sets $defaultOnRoleMin when it is genuinely useful to two
     * audiences with opposite expectations: an automatic-update notice
     * is something a superadmin wants unprompted, and something an
     * ordinary admin should be able to switch on without having had it
     * switched on for them. Below $defaultOnRoleMin the type still
     * appears on /notifications/preferences with every switch it declares
     * — only its "default_on" channels start off instead of on, so
     * "available, off by default" needs no second declaration mechanism.
     *
     * A LOCKED channel ("on"/"off") is never affected: locking is a
     * statement that the member has no say at all, which a per-role
     * default must not quietly reopen.
     */
    public function defaultsOnForRole(Role $role): bool
    {
        if ($this->defaultOnRoleMin === null) {
            return true;
        }

        return $role->hasAccess(Role::fromString($this->defaultOnRoleMin));
    }

    /**
     * Whether $channel is forced ("on"/"off") — the member can never
     * override it, and the preferences page renders it greyed rather than
     * a live toggle.
     */
    public function isChannelLocked(string $channel): bool
    {
        return in_array($this->channels[$channel] ?? '', self::LOCKED_VALUES, true);
    }

    /**
     * The channel's forced value when locked, else its default when the
     * member hasn't customized it.
     *
     * $defaultsOn is defaultsOnForRole() for the account being resolved,
     * passed in rather than looked up here so this class stays a plain
     * value object with no idea who is asking. False downgrades a
     * "default_on" channel to off — never a locked one, and never one
     * already declared "default_off".
     */
    public function defaultEnabled(string $channel, bool $defaultsOn = true): bool
    {
        $declared = $this->channels[$channel] ?? 'default_off';

        if ($declared === 'default_on' && !$defaultsOn) {
            return false;
        }

        return in_array($declared, self::ENABLED_VALUES, true);
    }
}
