<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use Core\Member\Duplicate\RepointingMergeListener;

/**
 * Brings a batch's lines along when two identities of a member are merged
 * (issue #1462): a line matched to the absorbed identity is published to
 * the kept one. The attestations already published are member documents,
 * which core moves itself.
 *
 * **Collision rule:** a line proposes each member once — a line proposing
 * both identities keeps the kept identity's candidate.
 */
final class AttestationMergeListener extends RepointingMergeListener
{
    protected const UNIQUE = [
        'attestation_line_candidates' => ['member_id', ['line_id'], 'kept'],
    ];

    protected const PLAIN = [
        ['attestation_batch_lines', 'member_id'],
    ];

    protected const PREVIEW = [
        'ligne(s) d\'attestation en préparation' => ['attestation_batch_lines', 'member_id'],
    ];

    public function moduleId(): string
    {
        return 'attestations';
    }
}
