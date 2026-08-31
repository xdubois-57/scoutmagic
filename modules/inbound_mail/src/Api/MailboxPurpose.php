<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * What a mailbox is for — the first question the configuration screen
 * asks, and the one that decides every other answer.
 *
 * It is stored rather than derived from the per-module rows, even though
 * a dedicated box is expressible as "one module analysing with
 * `ReadMode::ALL`, the others off". Deriving it would make the screen
 * contradict its own answer the first time somebody narrowed one scope by
 * hand: the box would silently stop calling itself dedicated, and the
 * operator would have no idea why the page rearranged itself.
 */
enum MailboxPurpose: string
{
    /**
     * The unit's public address. A parent's question sits next to a
     * supplier's invoice and a medical certificate, so each module is
     * given its own answer and « tout le courrier » is a warning.
     */
    case SHARED = 'shared';

    /**
     * An address created for one purpose, whose whole content concerns one
     * module. That module reads and files all of it; no other module looks
     * at it at all.
     */
    case DEDICATED = 'dedicated';

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::SHARED;
    }

    public function label(): string
    {
        return match ($this) {
            self::SHARED => 'Partagée',
            self::DEDICATED => 'Dédiée',
        };
    }
}
