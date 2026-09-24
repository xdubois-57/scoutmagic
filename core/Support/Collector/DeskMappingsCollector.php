<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Import\DeskMappingGap;
use Core\Import\DeskMappingGapService;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `desk-mappings.json` — the Desk values this installation does not
 * recognise, as a maintainer would want them while reading a ticket
 * (issue #356).
 *
 * This is the half of the answer that does not depend on the daily report
 * (D10). A unit that turned `statistics_enabled` off, or whose reports
 * never reach anywhere, still produces this file the moment somebody asks
 * for a support package — and « depuis l'import, trois animateurs ne
 * voient rien » is one of the questions it answers on the spot, against a
 * journal whose retention may already have swallowed the import.
 *
 * Each entry names the hard-coded table to complete, because that is the
 * fix and a reader should not have to rediscover which one it is.
 *
 * Federal vocabulary and counts only — no member, no section name
 * (SECURITY.md §11). Nothing here carries a secret, so nothing needs
 * redacting.
 */
class DeskMappingsCollector implements SupportCollectorInterface
{
    public function __construct(private DeskMappingGapService $gapService)
    {
    }

    public function name(): string
    {
        return 'desk_mappings';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $gaps = array_map(
            static fn(DeskMappingGap $gap): array => [
                'kind' => $gap->kind->value,
                'value' => $gap->rawValue,
                'affected' => $gap->affectedCount,
                'code_table' => $gap->kind->codeTable(),
            ],
            $this->gapService->gaps()
        );

        $context->addFileFromContent(
            'desk-mappings.json',
            (string) json_encode(
                // `unresolved: []` rather than an absent file: « this
                // installation recognises everything » and « nobody
                // collected this » are different answers, and a support
                // package that omits the second is read as the first.
                ['unresolved' => $gaps],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            )
        );
    }
}
