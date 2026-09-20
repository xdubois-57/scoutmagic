<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed;

/**
 * Where a seed copy ended up (roadmap IT-07).
 *
 * **Four states and not two**, and the two extra ones carry most of the
 * meaning. `Pending` is a copy sent and not yet found — which is the
 * ordinary state for the first minutes of any run, and reading it as bad
 * news would light a warning on every mailing while it is still going out.
 * `Missing` is a copy that never came, and only elapsed time separates the
 * two: a verdict that collapsed them would be wrong in whichever direction
 * it chose.
 *
 * The distinction is the same one the Rebonds page makes between a
 * transient and a permanent failure, for the same reason: a diagnostic
 * that cannot say « not yet » ends up saying something false instead.
 */
enum SeedVerdict: string
{
    case Pending = 'pending';
    case Inbox = 'inbox';
    case Spam = 'spam';
    case Missing = 'missing';

    /** What the screen says, in ordinary French. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Inbox => 'Boîte de réception',
            self::Spam => 'Indésirables',
            self::Missing => 'Jamais arrivé',
        };
    }

    /**
     * The badge tone, so one reading is one colour everywhere.
     *
     * `Missing` is graver than `Spam` on purpose: a message filed as spam
     * was at least accepted and can be found by somebody who looks, while
     * one that never arrived was refused silently — and silence is what
     * this whole chantier exists to end.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Inbox => 'success',
            self::Spam => 'warning',
            self::Missing => 'danger',
        };
    }

    /**
     * Which verdict a folder name means.
     *
     * **Matched on the folder, because that is what a provider tells us**,
     * and providers name the same shelf differently — « Junk » at Google,
     * « Indésirables » at OVH, « Spam » nearly everywhere else, « Bulk
     * Mail » on older servers. The list is a best effort and says so: an
     * unrecognised folder reads as `Inbox` only when it IS the inbox, and
     * otherwise stays `Pending` rather than being guessed either way.
     */
    public static function fromFolder(?string $folder): self
    {
        if ($folder === null || trim($folder) === '') {
            return self::Pending;
        }

        $normalised = mb_strtolower(trim($folder));
        // The leaf, since a provider may nest: « INBOX/Junk » is the junk
        // shelf and « INBOX » is not.
        $leaf = mb_strtolower(trim((string) (array_slice(explode('/', $normalised), -1)[0] ?? '')));

        foreach (['junk', 'spam', 'indésirables', 'indesirables', 'bulk mail', 'courrier indésirable'] as $needle) {
            if ($leaf === $needle || str_contains($leaf, $needle)) {
                return self::Spam;
            }
        }

        return $normalised === 'inbox' || $leaf === 'inbox' ? self::Inbox : self::Pending;
    }
}
