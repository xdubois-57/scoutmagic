<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Core\Security\UserAccountRepository;
use Core\View\DateFilterExtension;
use Modules\Social\Api\DirectShare;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Repository\CommunicationRepository;
use Modules\Social\Repository\Publication;
use Modules\Social\Repository\PublicationRepository;

/**
 * What a SOURCE has already been through (issue #706, IT-05) — every
 * share of every communication that shares it, not just this one's.
 *
 * **Why the source and not the communication.** From IT-05 the same album
 * with a different message is a new communication, allowed on purpose. So
 * a chief opening the composer on an album for the third time has two
 * earlier communications behind them, each with its own publications, and
 * « Cet album est déjà parti sur Facebook le 12 septembre » is true of
 * the album whichever row carried it. Reading only the row in hand would
 * show an empty history to the one person who most needs it.
 *
 * It answers two questions, from the same read:
 *
 * - {@see lines()} — the block at the head of the composer: destination,
 *   date, author, hand-made shares included.
 * - {@see byDestination()} — one sentence per destination for the
 *   confirmation, carrying **« avec le même message »** when the text
 *   about to go is the text that went. That is the case which almost
 *   always means somebody forgot they had already shared it, and the
 *   chantier asks for it by name.
 *
 * Neither answer blocks anything. « Permis, avec avertissement » is the
 * rule: only the same communication twice to the same destination is
 * refused, and `social_publications`' own unique key is what refuses it.
 */
final class ShareHistory
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly PublicationRepository $publications,
        private readonly UserAccountRepository $accounts,
    ) {
    }

    /**
     * Every share this source has already had, newest first.
     *
     * @return list<array{label: string, date: \DateTimeImmutable, author: ?string}>
     */
    public function lines(?string $sourceKind, ?int $sourceId): array
    {
        $publications = $this->ofSource($sourceKind, $sourceId);
        if ($publications === []) {
            return [];
        }

        $names = $this->accounts->findNamesByIds(array_values(array_filter(
            array_map(static fn (Publication $p): ?int => $p->userAccountId, $publications)
        )));

        $lines = [];
        foreach ($publications as $publication) {
            $at = $publication->publishedAt ?? $publication->attemptedAt;
            $lines[] = [
                'label' => self::label($publication),
                'date' => $at,
                'author' => $publication->userAccountId === null
                    ? null
                    : ($names[$publication->userAccountId]['first_name'] ?? null),
            ];
        }

        usort($lines, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $lines;
    }

    /**
     * One sentence per destination key, for the confirmation.
     *
     * The key is the destination's own (`facebook`, `group:3`, `copy`),
     * so the dialog can look up whichever boxes are ticked. A destination
     * this source never went to is absent, and the dialog then says
     * « Jamais partagé ici. » itself — there is nothing to date.
     *
     * @param string $body the text about to be published
     * @return array<string, array{line: string, same_message: bool}>
     */
    public function byDestination(?string $sourceKind, ?int $sourceId, string $body): array
    {
        $latest = [];
        foreach ($this->ofSource($sourceKind, $sourceId) as $publication) {
            $at = $publication->publishedAt ?? $publication->attemptedAt;
            $known = $latest[$publication->destination] ?? null;
            // The most recent one speaks for the destination: it is the
            // date a chief would recognise, and the text they would
            // remember sending.
            if ($known === null || $at > ($known->publishedAt ?? $known->attemptedAt)) {
                $latest[$publication->destination] = $publication;
            }
        }

        $answers = [];
        foreach ($latest as $key => $publication) {
            $same = trim($publication->caption) === trim($body);
            $answers[$key] = [
                'line' => self::sentence($publication, $sourceKind, $same),
                'same_message' => $same,
            ];
        }

        return $answers;
    }

    /**
     * Every publication of every communication sharing this source.
     *
     * A communication with no source answers nothing: there is no
     * « already » to show, and its own destinations already carry their
     * state on the page.
     *
     * @return list<Publication>
     */
    private function ofSource(?string $sourceKind, ?int $sourceId): array
    {
        if ($sourceKind === null || $sourceId === null) {
            return [];
        }

        $ids = $this->communications->idsForSource($sourceKind, $sourceId);

        return $ids === [] ? [] : $this->publications->forCommunications($ids);
    }

    /** « Facebook », « Groupe Pionniers », « Lien copié ». */
    private static function label(Publication $publication): string
    {
        $platform = SocialPlatform::tryFrom($publication->destination);
        if ($platform !== null) {
            return $platform->label();
        }

        $direct = DirectShare::tryFrom($publication->destination);
        if ($direct !== null) {
            return $direct->label();
        }

        return $publication->destinationLabel ?? 'Groupe de discussion';
    }

    /**
     * The confirmation's sentence, worded as the mockup words it: a
     * platform is named inside the sentence, a group is not — its name is
     * already the heading of the block it sits in.
     */
    private static function sentence(Publication $publication, ?string $sourceKind, bool $same): string
    {
        $at = $publication->publishedAt ?? $publication->attemptedAt;
        // The site's one date format, year included. The mockup writes
        // « le 12 septembre »; the year is extra precision rather than a
        // different label, and a share from last September would be
        // ambiguous without it — while a second date vocabulary in one
        // module would be a real divergence.
        $when = DateFilterExtension::frenchDate($at);
        $message = $same ? ' avec le même message' : ' avec un autre message';
        $platform = SocialPlatform::tryFrom($publication->destination);

        if ($platform !== null) {
            // « sur Facebook », as the mockup writes it — not
            // `label()`'s « Page Facebook », which belongs to the
            // checkbox that names the account. Inside a sentence the
            // short name is what reads.
            return ucfirst(self::subject($sourceKind)) . ' est déjà parti sur '
                . ucfirst($platform->value) . ' le ' . $when . ',' . $message . '.';
        }

        return 'Déjà parti le ' . $when . ',' . $message . '.';
    }

    /** « cet album », « cette actualité », « cette communication ». */
    private static function subject(?string $sourceKind): string
    {
        return match ($sourceKind) {
            ShareSource::KIND_ALBUM => 'cet album',
            ShareSource::KIND_ARTICLE => 'cette actualité',
            default => 'cette communication',
        };
    }
}
