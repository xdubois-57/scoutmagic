<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Mail;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\FieldProposal;
use Modules\Camps\Repository\FieldProposalRepository;
use Modules\Camps\Service\CampLabels;
use Modules\Camps\Service\CampService;

/**
 * What an inbound message does to the stay it was attached to.
 *
 * One rule, applied per field:
 *
 * - **Empty field → filled**, with a history entry marked `email`. There
 *   is nothing to disagree with, and a chief who has to retype what the
 *   farmer already wrote will not use the feature.
 * - **Field already filled → NEVER overwritten.** A chief typed 2 450 €
 *   because they read a contract; a regex over an e-mail body does not
 *   get to disagree silently. The reading is parked as a proposal shown
 *   inline next to the value it argues with, with Appliquer / Ignorer.
 *
 * Both outcomes produce a history entry, including "Ignorer": a reading
 * that was refused is itself worth knowing about six months later, when
 * somebody asks why the price on the page is not the one in the mail.
 */
class MailFieldCompletionService
{
    public function __construct(
        private CampRepository $camps,
        private FieldProposalRepository $proposals,
        private AuditService $audit,
        private MessageReader $reader
    ) {
    }

    /**
     * Reads a message against a stay and either fills or proposes.
     *
     * @return array{filled: string[], proposed: string[]}
     */
    public function apply(Camp $camp, string $body, string $sourceReference): array
    {
        $filled = [];
        $proposed = [];

        $range = $this->reader->readDateRange($body);
        if ($range !== null) {
            $current = CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly);
            $incoming = CampLabels::dateRange($range['start'], $range['end'], null);

            if ($incoming !== $current) {
                // A year-only stay counts as EMPTY for dates: "on y va en
                // 2029" is a placeholder waiting for exactly this message,
                // not a value somebody would defend.
                if ($camp->endDate === null) {
                    $this->fillDates($camp, $range, $sourceReference);
                    $filled[] = 'dates';
                } else {
                    $this->proposals->save(
                        $camp->id, 'dates', $current, $incoming,
                        $range['start'] . '|' . $range['end'], $sourceReference
                    );
                    $proposed[] = 'dates';
                }
            }
        }

        $price = $this->reader->readPriceCents($body);
        if ($price !== null && $price !== $camp->priceCents) {
            $incoming = (string) CampLabels::money($price);
            if ($camp->priceCents === null) {
                $this->fillPrice($camp, $price, $incoming, $sourceReference);
                $filled[] = 'price';
            } else {
                $this->proposals->save(
                    $camp->id,
                    'price',
                    CampLabels::money($camp->priceCents),
                    $incoming,
                    (string) $price,
                    $sourceReference
                );
                $proposed[] = 'price';
            }
        }

        return ['filled' => $filled, 'proposed' => $proposed];
    }

    /**
     * Accepts a parked reading. The value moves onto the stay and the
     * history says a human decided it, from a message.
     */
    public function accept(FieldProposal $proposal, ?int $actorUserAccountId): void
    {
        $camp = $this->camps->findById($proposal->campId);
        if ($camp === null) {
            $this->proposals->delete($proposal->id);

            return;
        }

        // The MACHINE value, never the readable one: "12–19 juillet 2028"
        // is what a chief sees, and re-parsing it here would mean
        // teaching the reader to read its own output.
        $written = false;
        if ($proposal->fieldKey === 'price' && ctype_digit($proposal->proposedMachineValue)) {
            $this->write($camp, ['price_cents' => (int) $proposal->proposedMachineValue]);
            $written = true;
        } elseif ($proposal->fieldKey === 'dates') {
            $parts = explode('|', $proposal->proposedMachineValue);
            if (count($parts) === 2) {
                $this->write($camp, ['start_date' => $parts[0], 'end_date' => $parts[1], 'year_only' => null]);
                $written = true;
            }
        }

        // Nothing was written — an unreadable machine value, or a field key
        // this method has no branch for. Recording "acceptée" and deleting
        // the proposal anyway was the worst of both: the history claimed a
        // chief's decision had been applied, the stay still said the old
        // thing, and the proposal that would have let anyone notice was
        // gone. Recorded as what it is instead, and still removed, because
        // a proposal that cannot be applied is not worth offering twice.
        if (!$written) {
            $this->audit->record(
                CampService::ENTITY_TYPE, $camp->id, $proposal->fieldKey,
                $proposal->currentValue, $proposal->currentValue,
                AuditSource::System, "Information du message inapplicable — proposition retirée",
                $proposal->sourceReference, $actorUserAccountId
            );
            $this->proposals->delete($proposal->id);

            return;
        }

        $this->audit->record(
            CampService::ENTITY_TYPE, $camp->id, $proposal->fieldKey,
            $proposal->currentValue, $proposal->proposedValue,
            AuditSource::Email, 'Information du message acceptée',
            $proposal->sourceReference, $actorUserAccountId
        );
        $this->proposals->delete($proposal->id);
    }

    /**
     * Refuses a parked reading — and records the refusal. Six months
     * later somebody will ask why the page does not say what the mail
     * says, and "a chief looked at it and said no" is the answer.
     */
    public function dismiss(FieldProposal $proposal, ?int $actorUserAccountId): void
    {
        $this->audit->record(
            CampService::ENTITY_TYPE, $proposal->campId, $proposal->fieldKey,
            $proposal->proposedValue, $proposal->currentValue,
            AuditSource::Human, 'Information du message ignorée',
            $proposal->sourceReference, $actorUserAccountId
        );
        $this->proposals->delete($proposal->id);
    }

    /**
     * @param array{start: string, end: string} $range
     */
    private function fillDates(Camp $camp, array $range, string $sourceReference): void
    {
        $before = CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly);
        $this->write($camp, ['start_date' => $range['start'], 'end_date' => $range['end'], 'year_only' => null]);

        $this->audit->record(
            CampService::ENTITY_TYPE, $camp->id, 'dates',
            $before !== '' ? $before : null,
            CampLabels::dateRange($range['start'], $range['end'], null),
            AuditSource::Email, 'Dates complétées depuis un message reçu', $sourceReference, null
        );
    }

    private function fillPrice(Camp $camp, int $cents, string $formatted, string $sourceReference): void
    {
        $this->write($camp, ['price_cents' => $cents]);

        $this->audit->record(
            CampService::ENTITY_TYPE, $camp->id, 'price', null, $formatted,
            AuditSource::Email, 'Prix complété depuis un message reçu', $sourceReference, null
        );
    }

    /**
     * Writes through CampRepository::update() with everything else left
     * exactly as it was — the stay's other fields are none of this
     * service's business.
     *
     * @param array<string, mixed> $changes
     */
    private function write(Camp $camp, array $changes): void
    {
        $this->camps->update(
            $camp->id,
            $camp->stayType,
            $changes['start_date'] ?? $camp->startDate,
            $changes['end_date'] ?? $camp->endDate,
            array_key_exists('year_only', $changes) ? $changes['year_only'] : $camp->yearOnly,
            $camp->status,
            $changes['price_cents'] ?? $camp->priceCents,
            $camp->participantCount,
            $camp->bookedByMemberId,
            $camp->bookedByName,
            $camp->sectionIds,
        );
    }
}
