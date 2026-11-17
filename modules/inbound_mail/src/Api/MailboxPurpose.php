<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * What a mailbox is for — one of exactly two answers, and the only setting
 * that decides who analyses it and who reads it (§8.58).
 *
 * There used to be a per-module setting under it (« analyse ce courrier »,
 * « qui peut lire »). It is gone: the two usages say everything an
 * operator meant, and a box added is at work the moment it is saved.
 */
enum MailboxPurpose: string
{
    /**
     * The unit's general mail. Every active consumer analyses it; a
     * module's users see only the messages attached to something they
     * manage, and the propositions about it stay with the Staff d'U in
     * « Courrier reçu », who confirms or dismisses them.
     */
    case SHARED = 'shared';

    /**
     * An address created for one module, whose whole content concerns it.
     * That module alone analyses it, and its users read all of it,
     * propositions included. A module has at most one such box.
     */
    case DEDICATED = 'dedicated';

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::SHARED;
    }

    public function label(): string
    {
        return match ($this) {
            self::SHARED => 'Courrier général',
            self::DEDICATED => 'Réservée à un module',
        };
    }
}
