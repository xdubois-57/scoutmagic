<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;

/**
 * Stays: validation, persistence, and the change history that goes with
 * them (Core\Audit entity type 'camp_camp').
 *
 * This is where the module's one structural rule lives — a stay has real
 * dates OR a bare year, never both and never neither. It is a service
 * rule rather than a CHECK constraint because Core\Database\
 * SchemaComparator does not diff CHECK constraints, so one declared in
 * schema.sql would exist on a fresh install and be absent on every
 * upgraded one: a rule that holds on half the installations is worse than
 * a rule enforced in one readable place.
 */
class CampService
{
    public const ENTITY_TYPE = 'camp_camp';

    /**
     * A stay's free-text note lives in the core editable-content store
     * under this key rather than in a column — one rich-text mechanism on
     * this site, not two. The constant lives here rather than on the
     * controller because Service\MergeService needs it too, and a service
     * reaching into a controller for a storage key would be backwards.
     */
    public const NOTE_KEY_PREFIX = 'camp_note_';

    public static function noteKey(int $campId): string
    {
        return self::NOTE_KEY_PREFIX . $campId;
    }

    public function __construct(
        private CampRepository $camps,
        private AuditService $audit,
        private ?PlaceRepository $places = null
    ) {
    }

    /**
     * A stay changed, so what the AI wrote about its place is no longer
     * true. Marking is all that happens here — regeneration is a daily
     * task, because a model call on a page load makes the page as slow as
     * the slowest third party.
     *
     * Optional dependency so the service stays constructible without it;
     * a null repository simply means nothing to mark.
     */
    private function markPlaceSummaryStale(int $placeId): void
    {
        $this->places?->markSummaryStale($placeId);
    }

    /**
     * @param array<string, mixed> $fields
     * @param callable(int[]): string $describeSections renders section ids
     *        as the words the history and the page both show
     */
    public function create(
        int $placeId,
        array $fields,
        ?int $actorUserAccountId,
        callable $describeSections,
        AuditSource $source = AuditSource::Human
    ): int {
        $values = $this->validate($fields);

        $id = $this->camps->create(
            $placeId,
            $values['stay_type'],
            $values['start_date'],
            $values['end_date'],
            $values['year_only'],
            $values['status'],
            $values['price_cents'],
            $values['participant_count'],
            $values['booked_by_member_id'],
            $values['booked_by_name'],
            $values['section_ids'],
        );

        $this->audit->record(
            self::ENTITY_TYPE,
            $id,
            'camp',
            null,
            CampLabels::dateRange($values['start_date'], $values['end_date'], $values['year_only']),
            $source,
            'Séjour créé',
            null,
            $actorUserAccountId
        );

        $this->markPlaceSummaryStale($placeId);

        // Sections chosen at creation are recorded too: a chief reading
        // the history a year later should not have to infer that the
        // sections were set from the fact that no line ever set them.
        if ($values['section_ids'] !== []) {
            $this->audit->record(
                self::ENTITY_TYPE, $id, 'sections', null, $describeSections($values['section_ids']),
                $source, null, null, $actorUserAccountId
            );
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $fields
     * @param callable(int[]): string $describeSections
     */
    public function update(
        Camp $camp,
        array $fields,
        ?int $actorUserAccountId,
        callable $describeSections,
        AuditSource $source = AuditSource::Human
    ): void {
        $values = $this->validate($fields);

        $this->camps->update(
            $camp->id,
            $values['stay_type'],
            $values['start_date'],
            $values['end_date'],
            $values['year_only'],
            $values['status'],
            $values['price_cents'],
            $values['participant_count'],
            $values['booked_by_member_id'],
            $values['booked_by_name'],
            $values['section_ids'],
        );

        $this->markPlaceSummaryStale($camp->placeId);

        $this->recordChange($camp->id, 'stay_type',
            CampLabels::stayType($camp->stayType), CampLabels::stayType($values['stay_type']),
            $source, $actorUserAccountId);
        $this->recordChange($camp->id, 'dates',
            CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly),
            CampLabels::dateRange($values['start_date'], $values['end_date'], $values['year_only']),
            $source, $actorUserAccountId);
        $this->recordChange($camp->id, 'status',
            CampLabels::status($camp->status), CampLabels::status($values['status']),
            $source, $actorUserAccountId);
        $this->recordChange($camp->id, 'price',
            CampLabels::money($camp->priceCents), CampLabels::money($values['price_cents']),
            $source, $actorUserAccountId);
        $this->recordChange($camp->id, 'participants',
            $camp->participantCount !== null ? (string) $camp->participantCount : null,
            $values['participant_count'] !== null ? (string) $values['participant_count'] : null,
            $source, $actorUserAccountId);
        $this->recordChange($camp->id, 'booked_by',
            $camp->bookedByName, $values['booked_by_name'],
            $source, $actorUserAccountId);
        $this->recordChange($camp->id, 'sections',
            $describeSections($camp->sectionIds), $describeSections($values['section_ids']),
            $source, $actorUserAccountId);
    }

    /**
     * Splits a list of stays into upcoming and past, both already ordered
     * the way their screens want them: upcoming soonest first (the next
     * departure is the one being prepared), past newest first.
     *
     * @param Camp[] $camps
     * @return array{upcoming: Camp[], past: Camp[]}
     */
    public function split(array $camps, \DateTimeImmutable $today): array
    {
        $upcoming = [];
        $past = [];
        foreach ($camps as $camp) {
            if ($camp->isUpcoming($today)) {
                $upcoming[] = $camp;
            } else {
                $past[] = $camp;
            }
        }

        usort($upcoming, static fn(Camp $a, Camp $b): int => $a->sortKey() <=> $b->sortKey());
        usort($past, static fn(Camp $a, Camp $b): int => $b->sortKey() <=> $a->sortKey());

        return ['upcoming' => $upcoming, 'past' => $past];
    }

    /**
     * Normalises and checks one form's worth of input.
     *
     * @param array<string, mixed> $fields
     * @return array{stay_type: string, start_date: ?string, end_date: ?string, year_only: ?int,
     *               status: string, price_cents: ?int, participant_count: ?int,
     *               booked_by_member_id: ?int, booked_by_name: ?string, section_ids: int[]}
     */
    public function validate(array $fields): array
    {
        $stayType = (string) ($fields['stay_type'] ?? Camp::STAY_GRAND_CAMP);
        if (!isset(CampLabels::STAY_TYPES[$stayType])) {
            throw new CampsException('Ce type de séjour n\'existe pas.');
        }

        $status = (string) ($fields['status'] ?? Camp::STATUS_TO_CONFIRM);
        if (!isset(CampLabels::STATUSES[$status])) {
            throw new CampsException('Ce statut n\'existe pas.');
        }

        $startDate = $this->cleanDate($fields['start_date'] ?? null);
        $endDate = $this->cleanDate($fields['end_date'] ?? null);
        $yearOnly = $this->cleanYear($fields['year_only'] ?? null);

        $hasRange = $startDate !== null || $endDate !== null;
        if ($hasRange && $yearOnly !== null) {
            throw new CampsException(
                'Indiquez soit les dates du séjour, soit seulement son année — pas les deux.'
            );
        }
        if (!$hasRange && $yearOnly === null) {
            throw new CampsException(
                'Indiquez les dates du séjour, ou au moins son année si vous ne les connaissez plus.'
            );
        }
        if ($hasRange && ($startDate === null || $endDate === null)) {
            throw new CampsException('Indiquez la date de début ET la date de fin du séjour.');
        }
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            throw new CampsException('Le séjour ne peut pas se terminer avant d\'avoir commencé.');
        }

        return [
            'stay_type' => $stayType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'year_only' => $yearOnly,
            'status' => $status,
            'price_cents' => $this->cleanPrice($fields['price'] ?? null),
            'participant_count' => $this->cleanCount($fields['participant_count'] ?? null),
            'booked_by_member_id' => $this->checkedMemberId($this->cleanId($fields['booked_by_member_id'] ?? null)),
            'booked_by_name' => $this->cleanText($fields['booked_by_name'] ?? null),
            'section_ids' => $this->checkedSectionIds(array_values(array_filter(array_map(
                'intval',
                is_array($fields['section_ids'] ?? null) ? $fields['section_ids'] : []
            )))),
        ];
    }

    /**
     * Both columns are foreign keys, so a value nobody offered used to
     * reach MySQL and come back as a PDOException — a 500 on a chief's
     * form, from a `<select>` somebody edited. The `<select>` is the
     * client's copy of the list, not the list, so the list is asked here.
     *
     * @param int[] $sectionIds
     * @return int[]
     */
    private function checkedSectionIds(array $sectionIds): array
    {
        if ($sectionIds === []) {
            return [];
        }

        $known = $this->camps->existingSectionIds($sectionIds);
        if (count($known) !== count(array_unique($sectionIds))) {
            throw new CampsException('Une des sections choisies n\'existe pas.');
        }

        return $sectionIds;
    }

    private function checkedMemberId(?int $memberId): ?int
    {
        if ($memberId !== null && !$this->camps->memberExists($memberId)) {
            throw new CampsException('Ce membre n\'existe pas.');
        }

        return $memberId;
    }

    private function recordChange(
        int $campId,
        string $fieldKey,
        ?string $from,
        ?string $to,
        AuditSource $source,
        ?int $actorUserAccountId
    ): void {
        // Core\Audit stores whatever it is handed and never compares, so
        // "did anything actually change" is decided here — otherwise every
        // save of an untouched form would add seven lines of history
        // saying nothing.
        if ($from === $to || ($from === '' && $to === null) || ($from === null && $to === '')) {
            return;
        }

        $this->audit->record(
            self::ENTITY_TYPE,
            $campId,
            $fieldKey,
            $from === '' ? null : $from,
            $to === '' ? null : $to,
            $source,
            null,
            null,
            $actorUserAccountId
        );
    }

    private function cleanDate(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new CampsException('Cette date n\'est pas une date valide.');
        }

        return $value;
    }

    private function cleanYear(mixed $value): ?int
    {
        $value = is_string($value) || is_int($value) ? trim((string) $value) : '';
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}$/', $value)) {
            throw new CampsException('L\'année doit s\'écrire en quatre chiffres, par exemple 2029.');
        }

        return (int) $value;
    }

    /**
     * Accepts what a chief actually types — "2450", "2 450", "2450,50",
     * "2450.50 €" — and stores cents. A price is the field most often
     * copied out of a quote, and refusing a space or a comma would be
     * refusing the way the number was written in the document it came
     * from.
     */
    private function cleanPrice(mixed $value): ?int
    {
        $value = is_string($value) || is_numeric($value) ? trim((string) $value) : '';
        if ($value === '') {
            return null;
        }

        $normalised = str_replace([' ', "\u{00A0}", '€', ','], ['', '', '', '.'], $value);
        if (!is_numeric($normalised) || (float) $normalised < 0) {
            throw new CampsException('Le prix doit être un montant, par exemple 2450 ou 2450,50.');
        }

        return (int) round(((float) $normalised) * 100);
    }

    private function cleanCount(mixed $value): ?int
    {
        $value = is_string($value) || is_int($value) ? trim((string) $value) : '';
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d+$/', $value)) {
            throw new CampsException('Le nombre de participants doit être un nombre entier.');
        }

        return (int) $value;
    }

    private function cleanId(mixed $value): ?int
    {
        $value = is_string($value) || is_int($value) ? (int) $value : 0;

        return $value > 0 ? $value : null;
    }

    private function cleanText(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
