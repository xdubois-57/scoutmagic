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
     * One sentence per destination key, for the confirmation — **without
     * its « avec le même message » clause**, which only the browser can
     * decide.
     *
     * The key is the destination's own (`facebook`, `group:3`, `copy`),
     * so the dialog can look up whichever boxes are ticked. A destination
     * this source never went to is absent, and the dialog then says
     * « Jamais partagé ici. » itself — there is nothing to date.
     *
     * **Why the clause is not computed here.** It compares the text
     * about to go with the text that went, and the text about to go sits
     * in a textarea the chief can still change after this page was
     * rendered. A verdict baked in here was a verdict about the text as
     * it stood at render time, so a chief who reused an album and then
     * rewrote the caption was told « avec le même message » about a
     * message they had just changed. A second case needed no editing at
     * all: a stored body of `''`, which the controller's `??` kept as
     * `''` while the template's `default` filter treated it as empty and
     * showed the prefill — the two sides comparing different strings.
     * `Claude review` found both on PR #868.
     *
     * So this answers the sentence and the caption that went, and
     * `public/assets/js/social-publish-confirm.js` reads the live
     * textarea and finishes the sentence. Nothing here compares texts.
     *
     * @return array<string, array{sentence: string, caption: string}>
     */
    public function byDestination(?string $sourceKind, ?int $sourceId): array
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
            $answers[$key] = [
                'sentence' => self::sentence($publication, $sourceKind),
                // What that destination actually received, for the
                // browser to compare the live text against.
                'caption' => $publication->caption,
            ];
        }

        return $answers;
    }

    /**
     * For each destination, the failed publications of **this chief's own**
     * messages about the source, with the text that failed (issue #881).
     *
     * The prefilled composer reads no history, so it shows a destination
     * as available even when the same message, by the same chief, already
     * failed there — and « Publier » then lands on that very communication
     * (the twin key) and used to refuse. The confirmation now says the
     * failed publication « sera relancée », and the click relaunches it.
     *
     * **Only the browser can say whether it is the same message**, for the
     * reason {@see byDestination()} gives: the text sits in a textarea the
     * chief can still change. So every failed text is shipped with its
     * sentence, and the dialog compares the live text with each. Own
     * messages only, because the twin key includes the author: another
     * chief's failure is another communication, which this click would
     * not reach.
     *
     * A publication left `pending` past the delay counts as failed, as it
     * does in « Ce qui est parti » and for « Réessayer ».
     *
     * @return array<string, list<array{sentence: string, caption: string}>>
     */
    public function failedByDestination(?string $sourceKind, ?int $sourceId, ?int $accountId): array
    {
        if ($sourceKind === null || $sourceId === null || $accountId === null) {
            return [];
        }

        $ids = $this->communications->idsForSource($sourceKind, $sourceId);
        $creators = $this->communications->creatorsOf($ids);
        $own = array_values(array_filter($ids, static fn (int $id): bool => ($creators[$id] ?? null) === $accountId));
        if ($own === []) {
            return [];
        }

        $staleBefore = (new \DateTimeImmutable())->modify('-' . PublishingService::STALE_MINUTES . ' minutes');
        $failed = [];
        foreach ($this->publications->forCommunications($own) as $publication) {
            if (!$publication->isRetryable($staleBefore)) {
                continue;
            }
            $failed[$publication->destination][] = [
                'sentence' => 'La publication de ce même message a échoué le '
                    . DateFilterExtension::frenchDate($publication->attemptedAt) . ' — elle sera relancée.',
                'caption' => $publication->caption,
            ];
        }

        return $failed;
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
        if ($ids === []) {
            return [];
        }

        // **Published rows only.** `claim()` writes the row `pending`
        // before Meta is called, and leaves it `failed` when Meta refuses,
        // so the table holds attempts as well as shares. « Cet album est
        // déjà parti sur Facebook le 12 septembre » built from a failed
        // attempt is false, and it is said to the one person who is at
        // that moment deciding whether to send it — the block exists to
        // stop an oversight, not to invent one. What became of a failed
        // attempt is « Ce qui est parti »'s subject, with its reason and
        // its « Réessayer », and that screen reads the rows itself.
        // `Claude review` found this on PR #868.
        return array_values(array_filter(
            $this->publications->forCommunications($ids),
            static fn (Publication $publication): bool => $publication->isPublished()
        ));
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
    private static function sentence(Publication $publication, ?string $sourceKind): string
    {
        $at = $publication->publishedAt ?? $publication->attemptedAt;
        // The site's one date format, year included. The mockup writes
        // « le 12 septembre »; the year is extra precision rather than a
        // different label, and a share from last September would be
        // ambiguous without it — while a second date vocabulary in one
        // module would be a real divergence.
        $when = DateFilterExtension::frenchDate($at);
        $platform = SocialPlatform::tryFrom($publication->destination);

        if ($platform !== null) {
            // « sur Facebook », as the mockup writes it — not
            // `label()`'s « Page Facebook », which belongs to the
            // checkbox that names the account. Inside a sentence the
            // short name is what reads.
            return ucfirst(self::subject($sourceKind)) . ' est déjà ' . self::gone($sourceKind) . ' sur '
                . ucfirst($platform->value) . ' le ' . $when;
        }

        return 'Déjà ' . self::gone($sourceKind) . ' le ' . $when;
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

    /**
     * The participle, agreeing with {@see subject()}: « cet album est
     * déjà **parti** », « cette actualité est déjà **partie** ». An album
     * is the only masculine subject of the three.
     *
     * It was a fixed « parti », which read as a spelling mistake on the
     * one screen that quotes this sentence — and `AGENTS.md` is explicit
     * that a French interface error is a bug and never a detail. A test
     * written to reach an uncovered line is what found it, which is the
     * second time on this chantier that a coverage hole was hiding
     * something real.
     */
    private static function gone(?string $sourceKind): string
    {
        return $sourceKind === ShareSource::KIND_ALBUM ? 'parti' : 'partie';
    }
}
