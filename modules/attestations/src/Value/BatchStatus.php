<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Value;

/**
 * Where a batch stands. Two states and no more, because there are only two
 * things that can be true of one: the split has happened and a human has
 * not confirmed it yet, or the documents are on the members' pages.
 *
 * There is deliberately no « failed » state. A split the arithmetic refuses
 * produces nothing at all — no batch row, no file, no line — so there is
 * nothing to give a status to. That refusal is a screen, not a record.
 */
enum BatchStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'À vérifier',
            self::Published => 'Publié',
        };
    }

    /**
     * The semantic key `partials/status_badge.html.twig` colours states by
     * — never a Bootstrap tone chosen here. One severity, one colour, site
     * wide: a batch awaiting its check is informational, a published one
     * is done.
     */
    public function badgeKey(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Published => 'done',
        };
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
