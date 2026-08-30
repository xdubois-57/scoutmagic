<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Value;

/**
 * What reading a deposited PDF produced: how many pages it holds, how many
 * of them one certificate takes, and the certificates themselves.
 *
 * The structure a service hands back rather than a row it wrote — the
 * reader touches no database at all, which is what makes it testable
 * against a fixture (roadmap IT-01).
 */
final class PdfAnalysis
{
    /**
     * @param list<ReadAttestation> $attestations in page order
     */
    public function __construct(
        public readonly int $pageCount,
        public readonly int $pagesPerDocument,
        public readonly array $attestations
    ) {
    }

    /** How many certificates still need a human decision. */
    public function pendingCount(): int
    {
        $pending = 0;
        foreach ($this->attestations as $attestation) {
            if ($attestation->state() !== MatchState::Matched) {
                $pending++;
            }
        }

        return $pending;
    }

    /** @return list<int> every members.id a single line resolves to, once each */
    public function matchedMemberIds(): array
    {
        $ids = [];
        foreach ($this->attestations as $attestation) {
            $memberId = $attestation->matchedMemberId();
            if ($memberId !== null) {
                $ids[$memberId] = true;
            }
        }

        return array_keys($ids);
    }
}
