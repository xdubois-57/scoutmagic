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
use Modules\MassMail\Repository\RecipientRepository;

/**
 * The concrete implementation behind Api\MassMailQueryInterface — a thin
 * wrapper so the public API surface never exposes Repository\
 * RecipientRepository (or PDO) directly to other modules/core.
 *
 * **It is also where a publipostage gets its variables back** (issue
 * #287). A mail merge is stored once, as a template, and substituted per
 * recipient in local variables just before the send (Task\
 * SendBatchHandler) — nothing writes a personalised copy anywhere. So
 * every surface that reads the e-mail back afterwards was showing
 * `Bonjour {{Prenom}}` to somebody whose mailbox holds `Bonjour Kaa`:
 * the member page's « Communications récentes », the detail page behind
 * it, and — until it was handed the rendered subject at the source — the
 * notification.
 *
 * **Rendered at READ time, from the recipient's own audience row, rather
 * than stored per recipient at send time.** Storing it would mean a
 * second copy of the same personal data, and above all it would outlive
 * the retention that is supposed to remove it: `Task\
 * PurgeMergeAudiencesHandler` erases a merge audience 18 months after the
 * last send precisely so those values stop existing, and a frozen copy
 * per recipient would quietly survive that. Reading through the audience
 * row means the personalisation disappears everywhere at once, on the day
 * it is meant to.
 *
 * The price is that a purged row cannot be rendered at all, and the
 * honest answer there is to SAY so rather than show the raw template
 * (which reads as a bug) or a body with every value blanked out (which
 * reads as a different bug). `merge_purged` carries that to the view.
 */
class MassMailQueryService implements MassMailQueryInterface
{
    public function __construct(
        private RecipientRepository $recipientRepository,
        private AudienceRepository $audienceRepository,
        private MergeRenderer $mergeRenderer
    ) {
    }

    public function getRecentEmailsForMember(int $memberId, int $limit): array
    {
        $rows = $this->recipientRepository->findRecentSentForMember($memberId, $limit);

        // One query for the whole page rather than one per line — this is
        // the member page's list, and it is drawn on every visit.
        $audienceRowIds = [];
        foreach ($rows as $row) {
            if ($row['audience_row_id'] !== null) {
                $audienceRowIds[] = $row['audience_row_id'];
            }
        }
        $audienceRows = $this->audienceRepository->findRowsByIds($audienceRowIds);

        return array_map(function (array $row) use ($audienceRows): array {
            $data = $this->mergeDataFor($row, $audienceRows);
            $isMerge = $row['list_type'] === Email::LIST_TYPE_MAIL_MERGE;

            return [
                'id' => $row['id'],
                'subject' => $data === null
                    ? $row['subject']
                    : $this->mergeRenderer->renderText($row['subject'], $data),
                'sent_at' => $row['sent_at'],
                'section_name' => $row['section_name'],
                // Same flag, same reason, as the detail below: a purged
                // publipostage falls back to the stored SUBJECT, which is
                // the template. A line reading « Camp de {{Prenom}} » with
                // nothing to explain it is the very symptom this change
                // exists to remove, and it would be odd for the list to
                // keep it while the page behind it explains itself.
                'merge_purged' => $isMerge && $data === null,
            ];
        }, $rows);
    }

    public function findEmailDetailForMember(int $memberId, int $recipientId): ?array
    {
        $detail = $this->recipientRepository->findSentDetailForMember($recipientId, $memberId);
        if ($detail === null) {
            return null;
        }

        $isMerge = $detail['list_type'] === Email::LIST_TYPE_MAIL_MERGE;
        $row = $detail['audience_row_id'] !== null
            ? $this->audienceRepository->findRowsByIds([$detail['audience_row_id']])[$detail['audience_row_id']] ?? null
            : null;

        if (!$isMerge || $row === null) {
            return [
                'subject' => $detail['subject'],
                'body_html' => $detail['body_html'],
                'sent_at' => $detail['sent_at'],
                'section_name' => $detail['section_name'],
                // True only for a publipostage whose row is gone: an
                // ordinary list has nothing to substitute and nothing to
                // have lost.
                'merge_purged' => $isMerge,
            ];
        }

        return [
            'subject' => $this->mergeRenderer->renderText($detail['subject'], $row->data),
            'body_html' => $this->mergeRenderer->renderHtml($detail['body_html'], $row->data),
            'sent_at' => $detail['sent_at'],
            'section_name' => $detail['section_name'],
            'merge_purged' => false,
        ];
    }

    /**
     * This line's own substitution values, or null when there is nothing
     * to substitute — an ordinary list, or a publipostage whose audience
     * has been purged.
     *
     * @param array{list_type: string, audience_row_id: int|null, ...} $row
     * @param array<int, \Modules\MassMail\Repository\AudienceRow> $audienceRows
     * @return array<string, string>|null
     */
    private function mergeDataFor(array $row, array $audienceRows): ?array
    {
        if ($row['list_type'] !== Email::LIST_TYPE_MAIL_MERGE || $row['audience_row_id'] === null) {
            return null;
        }

        return ($audienceRows[$row['audience_row_id']] ?? null)?->data;
    }
}
