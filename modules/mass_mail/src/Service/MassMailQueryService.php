<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Modules\MassMail\Api\MassMailQueryInterface;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\TargetRepository;

/**
 * The concrete implementation behind Api\MassMailQueryInterface — a thin
 * wrapper so the public API surface never exposes Repository\
 * TargetRepository (or PDO) directly to other modules/core.
 *
 * **One entry per target** (issue #999, §2.5): the member's history counts
 * communications meant for them, never the messages that carried them —
 * three addresses are one line — and a communication nothing of which
 * reached them stays listed, « Non envoyée ».
 *
 * **It is also where a publipostage gets its variables back** (issue
 * #287). A mail merge is stored once, as a template, and substituted per
 * line just before the send (Task\SendBatchHandler) — nothing writes a
 * personalised copy anywhere. **Rendered at READ time, from the target's
 * own file row, rather than stored per target at send time**: storing it
 * would be a second copy of the same personal data, and it would outlive
 * the retention that is supposed to remove it — Task\
 * PurgeMergeAudiencesHandler erases a merge audience 18 months after the
 * last send precisely so those values stop existing. Reading through the
 * row means the personalisation disappears everywhere at once.
 *
 * The price is that a purged row cannot be rendered at all, and the honest
 * answer there is to SAY so rather than show the raw template (which reads
 * as a bug) or a body with every value blanked out (which reads as a
 * different bug). `merge_purged` carries that to the view.
 */
class MassMailQueryService implements MassMailQueryInterface
{
    public function __construct(
        private TargetRepository $targetRepository,
        private AudienceRepository $audienceRepository,
        private MergeRenderer $mergeRenderer
    ) {
    }

    public function getRecentEmailsForMember(int $memberId, int $limit): array
    {
        $entries = $this->targetRepository->findRecentEntriesForMember($memberId, $limit);

        // One query for the whole page rather than one per line — this is
        // the member page's list, and it is drawn on every visit.
        $audienceRowIds = [];
        foreach ($entries as $entry) {
            if ($entry['audience_row_id'] !== null) {
                $audienceRowIds[] = $entry['audience_row_id'];
            }
        }
        $audienceRows = $this->audienceRepository->findRowsByIds($audienceRowIds);

        return array_map(function (array $entry) use ($audienceRows): array {
            $data = $this->mergeDataFor($entry, $audienceRows);
            $isMerge = $entry['list_type'] === Email::LIST_TYPE_MAIL_MERGE;

            return [
                'id' => $entry['id'],
                'subject' => $data === null
                    ? $entry['subject']
                    : $this->mergeRenderer->renderText($entry['subject'], $data),
                'sent_at' => $entry['sent_at'],
                'section_name' => $entry['section_name'],
                // Same flag, same reason, as the detail below: a purged
                // publipostage falls back to the stored SUBJECT, which is
                // the template. A line reading « Camp de {{Prenom}} » with
                // nothing to explain it is the very symptom this exists to
                // remove.
                'merge_purged' => $isMerge && $data === null,
                'not_sent' => $entry['not_sent'],
            ];
        }, $entries);
    }

    public function findEmailDetailForMember(int $memberId, int $targetId): ?array
    {
        $entry = $this->targetRepository->findEntryForMember($targetId, $memberId);
        if ($entry === null) {
            return null;
        }

        $isMerge = $entry['list_type'] === Email::LIST_TYPE_MAIL_MERGE;
        $row = $entry['audience_row_id'] !== null
            ? $this->audienceRepository->findRowsByIds([$entry['audience_row_id']])[$entry['audience_row_id']] ?? null
            : null;

        // « Concerne aussi » is a classic list's: a publipostage line has
        // one target, and two lines naming two children are two
        // communications, not one concerning both.
        $others = [];
        if (!$isMerge) {
            $others = $this->targetRepository->findOtherEntriesOfEmail($entry['email_id'], $targetId);
        }

        if (!$isMerge || $row === null) {
            return [
                'subject' => $entry['subject'],
                'body_html' => $entry['body_html'],
                'sent_at' => $entry['sent_at'],
                'section_name' => $entry['section_name'],
                // True only for a publipostage whose row is gone: an
                // ordinary list has nothing to substitute and nothing to
                // have lost.
                'merge_purged' => $isMerge,
                'not_sent' => $entry['not_sent'],
                'other_targets' => $others,
            ];
        }

        return [
            'subject' => $this->mergeRenderer->renderText($entry['subject'], $row->data),
            'body_html' => $this->mergeRenderer->renderHtml($entry['body_html'], $row->data),
            'sent_at' => $entry['sent_at'],
            'section_name' => $entry['section_name'],
            'merge_purged' => false,
            'not_sent' => $entry['not_sent'],
            'other_targets' => [],
        ];
    }

    /**
     * This entry's own substitution values, or null when there is nothing
     * to substitute — an ordinary list, or a publipostage whose audience
     * has been purged.
     *
     * @param array{list_type: string, audience_row_id: int|null, ...} $entry
     * @param array<int, \Modules\MassMail\Repository\AudienceRow> $audienceRows
     * @return array<string, string>|null
     */
    private function mergeDataFor(array $entry, array $audienceRows): ?array
    {
        if ($entry['list_type'] !== Email::LIST_TYPE_MAIL_MERGE || $entry['audience_row_id'] === null) {
            return null;
        }

        return ($audienceRows[$entry['audience_row_id']] ?? null)?->data;
    }
}
