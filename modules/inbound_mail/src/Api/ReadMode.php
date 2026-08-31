<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * How much of a mailbox a consumer's own users may read.
 *
 * **Separate from whether the consumer analyses the box at all**, and that
 * separation is the whole point of the second configuration screen. The
 * first one had a checkbox and three radio buttons whose meanings
 * overlapped, and an operator could not tell which combination gave a
 * module's users a window onto the unit's mail. Two questions, asked in
 * order — does this module sort this box, and if so who gets a list —
 * cannot be misread the same way.
 *
 * Filing and reading are genuinely different powers: a module can be
 * excellent at attaching a message to a booking without its users having
 * any business seeing the rest of the box.
 */
enum ReadMode: string
{
    /**
     * Sorting only. The module attaches what it recognises and opens no
     * list of its own; a message stays visible from the record it is
     * attached to, which is somewhere the reader already has the right to
     * be. This is the default, and the safe answer.
     */
    case NONE = 'none';

    /**
     * The module's users see what it attached to something they manage —
     * **and what it merely proposed**, because the point of a proposition
     * is that a human confirms or dismisses it, which they cannot do
     * without seeing it.
     */
    case RELEVANT = 'relevant';

    /**
     * Every message of the box. The normal answer on a dedicated box, and
     * a heavy one on a shared box: it hands the module's audience the
     * parents' questions, the medical documents and the applications along
     * with its own mail. The screen says exactly that, with the number of
     * real people it means.
     */
    case ALL = 'all';

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::NONE;
    }

    /**
     * The label of the segmented control, per the v2 mockup — which is
     * what these strings answer to, not the roadmap's earlier vocabulary
     * section describing the screen this one replaced.
     */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Personne',
            self::RELEVANT => 'Messages concernés',
            self::ALL => 'Tout le courrier',
        };
    }

    /**
     * What the index pill says instead. Deliberately different words: a
     * pill summarises a state, it does not offer a choice, and « Personne »
     * on a list of active modules would read as "this module is off".
     */
    public function pillLabel(): string
    {
        return match ($this) {
            self::NONE => 'classement seul',
            self::RELEVANT => 'messages concernés',
            self::ALL => 'tout le courrier',
        };
    }

    /** Whether this mode opens a list at all. */
    public function opensAList(): bool
    {
        return $this !== self::NONE;
    }
}
