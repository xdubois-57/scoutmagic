<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Config\ScoutYearService;
use Core\Import\ImportJournalRepository;

/**
 * The scout years an email can be sent for — the previous one, the
 * current one, and the next one once there is somebody to send to.
 *
 * Two screens offer them and must offer the same ones: the page of an
 * email (its « Année(s) scoute(s) » boxes) and « Voir les destinataires »
 * on the page of a list, whose year selector shows what the list would
 * reach for each year a send can actually target. One rule in one place,
 * so the second screen never previews a year the first one refuses.
 */
final class SendableScoutYears
{
    public function __construct(
        private ScoutYearService $scoutYearService,
        private ImportJournalRepository $importJournalRepository,
        private MailingListService $mailingListService
    ) {
    }

    /**
     * @return array{
     *     previous: array{id: int, label: string, available: bool, warning: ?string},
     *     current: array{id: int, label: string, available: bool, warning: ?string},
     *     next: array{id: int, label: string, available: bool, warning: ?string}
     * }
     */
    public function options(): array
    {
        $current = $this->scoutYearService->getCurrentYear();
        $previousLabel = ScoutYearService::previousLabel($current['label']);
        $nextLabel = ScoutYearService::nextLabel($current['label']);
        $nextId = $this->scoutYearService->ensureYear($nextLabel);

        // A future scout year used to be selectable ONLY once Desk had been
        // imported for it (module addendum) — there was nothing to send to
        // otherwise. With the registration module enabled there now is: the
        // projection knows who is expected next year long before Desk does
        // (Modules\Registration\Api\ProjectedPopulationProvider), which is
        // exactly what the warning below is about. Without that module the
        // old rule stands unchanged.
        // Computing the projected audience means running the registration
        // module's whole projection (every animé of the year, decrypted);
        // an import for next year already makes it available, so the
        // projection is only asked when nothing cheaper answers.
        $nextImported = $this->importJournalRepository->findByYear($nextId) !== [];
        $nextIsProjected = !$nextImported
            && $this->mailingListService->futureAudienceWarning($nextId) !== null
            && $this->mailingListService->resolveMembers('default_active_members', null, null, $nextId) !== [];

        return [
            'previous' => [
                'id' => $this->scoutYearService->ensureYear($previousLabel),
                'label' => $previousLabel,
                'available' => true,
                'warning' => null
            ],
            'current' => ['id' => $current['id'], 'label' => $current['label'], 'available' => true, 'warning' => null],
            'next' => [
                'id' => $nextId,
                'label' => $nextLabel,
                'available' => $nextImported || $nextIsProjected,
                // Null for the current year and for any past one: a warning
                // shown on every ordinary send is a warning nobody reads.
                'warning' => $this->mailingListService->futureAudienceWarning($nextId),
            ],
        ];
    }

    /**
     * The years that can be chosen right now, oldest first.
     *
     * @return list<array{id: int, label: string}>
     */
    public function available(): array
    {
        $years = [];
        foreach ($this->options() as $option) {
            if ($option['available']) {
                $years[] = ['id' => $option['id'], 'label' => $option['label']];
            }
        }

        return $years;
    }
}
