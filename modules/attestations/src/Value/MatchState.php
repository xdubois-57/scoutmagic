<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Value;

/**
 * The three answers name matching can give, and the third one is the
 * reason this enum exists at all.
 *
 * Two members can carry the same name. The previous site kept the first
 * match it found, preferring the most recent year — a silent choice, on a
 * nominative document, that sends one family's certificate to another
 * without anybody noticing. Nothing downstream would ever catch it: the
 * document's title says nothing about whose name is printed inside, so the
 * two only disagree for whoever opens it. Ambiguous is therefore a state of
 * its own, never a resolved match, and nothing is published while one
 * remains.
 */
enum MatchState: string
{
    /** Exactly one member carries this name. */
    case Matched = 'matched';

    /** No member carries it — a person unknown to the site. */
    case Unmatched = 'unmatched';

    /** Several members carry it. A human has to choose. */
    case Ambiguous = 'ambiguous';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Apparié',
            self::Unmatched => 'Non apparié',
            self::Ambiguous => 'Homonymie',
        };
    }
}
