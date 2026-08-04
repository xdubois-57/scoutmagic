<?php

declare(strict_types=1);

namespace Core\Notification;

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
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description,
        public readonly string $group,
        public readonly string $roleMin,
        public readonly array $channels
    ) {
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
     */
    public function defaultEnabled(string $channel): bool
    {
        return in_array($this->channels[$channel] ?? 'default_off', self::ENABLED_VALUES, true);
    }
}
