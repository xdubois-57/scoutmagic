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

    /**
     * Arrived, in a folder this list cannot name.
     *
     * **It exists because `Pending` was standing in for it, and that was
     * a lie with teeth.** A provider may file a copy in « Quarantaine »,
     * « Bulk », or a folder somebody created — none of which is the
     * inbox and none of which is on the junk list. The verdict stayed
     * `pending`, the message was pruned, and two days later the sweep
     * flipped that row to `Missing`: the gravest badge on the screen,
     * « Jamais arrivé », beside the very folder name the copy had
     * demonstrably landed in. And that fabricated `missing` fed the
     * routing's own counters.
     *
     * So a copy that arrived is never `Pending` again. What we cannot
     * classify we say we cannot classify, and show the folder next to it.
     */
    case Elsewhere = 'elsewhere';

    /** What the screen says, in ordinary French. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Inbox => 'Boîte de réception',
            self::Spam => 'Indésirables',
            self::Missing => 'Jamais arrivé',
            self::Elsewhere => 'Arrivé, dossier inconnu',
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
            // Neutral, not a warning: we do not know that anything is
            // wrong, and a colour claiming otherwise on a folder the unit
            // created itself would train its reader to ignore the ones
            // that mean something.
            self::Elsewhere => 'neutral',
        };
    }

    /**
     * Which verdict a folder name means.
     *
     * **Matched on the folder, because that is what a provider tells us**,
     * and providers name the same shelf differently — « Junk » at Google,
     * « Indésirables » at OVH, « Spam » nearly everywhere else, « Bulk
     * Mail » on older servers. The list is a best effort and says so: an
     * unrecognised folder reads as `Inbox` only when it IS the inbox.
     *
     * **A named folder is never `Pending`.** `Pending` means « sent, not
     * found yet », and it is the state the sweep turns into « jamais
     * arrivé » after two days — so returning it for a copy we have just
     * found would have the site declare an arrival a permanent failure.
     * A folder we cannot classify is `Elsewhere`, which is the honest
     * answer and the one the screen can show a folder name beside. Only
     * « the relay did not say » is still `Pending`.
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

        return $normalised === 'inbox' || $leaf === 'inbox' ? self::Inbox : self::Elsewhere;
    }
}
