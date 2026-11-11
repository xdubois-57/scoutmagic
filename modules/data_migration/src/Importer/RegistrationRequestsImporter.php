<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

use Core\Archive\ArchiveLimits;
use Core\Config\ScoutYearService;
use Core\Import\FeeCategoryRepository;
use Core\Import\MemberRepository;
use Core\Service\DateInput;
use Modules\DataMigration\Service\MissingScoutYears;
use Modules\DataMigration\Service\SectionByDeskCode;
use Modules\Registration\Api\ImportedRequest;
use Modules\Registration\Api\ImportedSecondaryEmail;
use Modules\Registration\Api\RequestImportWriter;

/**
 * « Inscriptions de l'année suivante » — format
 * `scoutmagic-next-year-registrations` 1.x (chantier #1142, lot H,
 * docs/formats/data-migration/next-year-registrations.md).
 *
 * New registration requests still being processed on the old site, one
 * per line of `requests.jsonl`, become ordinary requests of the
 * `registration` module through its Api\RequestImportWriter — no
 * acknowledgement, no unit alert, no decision e-mail, no confirmation of
 * a secondary address. Only new requests: the answers to a reenrollment
 * campaign are not imported (D7).
 *
 * What is resolved here, before the module ever sees the request:
 *
 * - the **scout year**, by its label, which must exist on this site: it is
 *   never inferred from a date, and never created — this is not a year of
 *   history (no D5 here), it is the year a family asked for;
 * - the **sections** wished and planned, by Desk code (D10): without a
 *   match the link is dropped, the request kept;
 * - the **fee category**, by Desk code, likewise;
 * - the **members** — siblings and the member an `encoded` request became
 *   — by Desk id (`MemberRepository::findByDeskId()`, merge aliases
 *   included). A sibling not found is dropped; an `encoded` request whose
 *   member is not found cannot be represented and is ignored.
 *
 * Everything the request itself must hold is the module's to say
 * (Api\RequestImportWriter::problems(): the public form's rules), and an
 * incomplete request is ignored with the first field it misses — never
 * filed half-made. A request that looks like one already filed for the
 * same child and year is imported all the same, and the request list
 * flags it « doublon possible » as it would any other.
 *
 * The report names the old site's reference of each request and the
 * reason, never a person (D8).
 */
final class RegistrationRequestsImporter implements Importer
{
    public const KEY = 'inscriptions';
    public const FORMAT = 'scoutmagic-next-year-registrations';
    public const MEMBER = 'requests.jsonl';
    public const KIND = 'request';
    public const TARGET_TYPE = 'registration_request';

    /** Ceilings: the manifest and one JSON Lines file of at most 64 MiB. */
    public const MAX_ENTRIES = 2;
    public const MAX_BYTES = 64 * 1024 * 1024;

    public const TAG_DECIDED = 'decided';
    public const TAG_DUPLICATE = 'possible_duplicate';
    public const TAG_SECTION_DROPPED = 'section_dropped';
    public const TAG_FEE_CATEGORY_DROPPED = 'fee_category_dropped';
    public const TAG_SIBLING_DROPPED = 'sibling_dropped';
    public const TAG_SECONDARY_EMAIL_DROPPED = 'secondary_email_dropped';
    public const TAG_MEMBER_LINK_DROPPED = 'member_link_dropped';
    public const TAG_YEAR_PREFIX = 'year:';
    public const TAG_STATUS_PREFIX = 'status:';

    /**
     * The archive's field for each of the request's own fields, as the
     * module names them.
     *
     * @var array<string, string>
     */
    public const FIELD_MAP = [
        'child_first_name' => 'child_first_name',
        'child_last_name' => 'child_last_name',
        'child_birth_date' => 'birth_date',
        'child_gender' => 'gender',
        'parent_name' => 'parent_name',
        'street' => 'street',
        'street_number' => 'number',
        'postal_code' => 'postal_code',
        'city' => 'city',
        'email' => 'email',
        'phone' => 'phone1',
        'other_phone' => 'phone2',
        'remarks' => 'remarks',
        'previous_unit_answer' => 'previous_unit_answer',
        'previous_unit_name' => 'previous_unit_name',
    ];

    /** @var array<string, array{0: string, 1: string}> the statuses in the preview, singular and plural */
    private const STATUS_COUNTS = [
        ImportedRequest::STATUS_PENDING => ['en attente', 'en attente'],
        ImportedRequest::STATUS_ACCEPTED => ['acceptée', 'acceptées'],
        ImportedRequest::STATUS_REFUSED => ['refusée', 'refusées'],
        ImportedRequest::STATUS_WITHDRAWN => ['retirée', 'retirées'],
        ImportedRequest::STATUS_ENCODED => ['encodée dans Desk', 'encodées dans Desk'],
    ];

    public function __construct(
        private ?RequestImportWriter $writer,
        private ScoutYearService $scoutYears,
        private SectionByDeskCode $sections,
        private MemberRepository $members,
        private FeeCategoryRepository $feeCategories
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function requiredModule(): string
    {
        return 'registration';
    }

    public function title(): string
    {
        return 'Inscriptions de l\'année suivante';
    }

    public function description(): string
    {
        return 'Les nouvelles demandes d\'inscription en cours. Elles rejoignent la liste des demandes et se traitent comme les autres.';
    }

    public function unitLabel(): string
    {
        return 'demandes';
    }

    public function archiveFormat(): ArchiveFormat
    {
        return new ArchiveFormat(
            self::FORMAT,
            1,
            new ArchiveLimits(self::MAX_ENTRIES, self::MAX_BYTES, self::MAX_BYTES + 1024 * 1024, ['json', 'jsonl']),
            self::MAX_BYTES + 1024 * 1024
        );
    }

    public function items(ImportSource $source, int $from): iterable
    {
        foreach ($source->jsonLines(self::MEMBER, $from) as $position => $row) {
            if ($row === null) {
                yield $position => SourceItem::unreadable(self::KIND, 'ligne ' . ($position + 1), 'Ligne illisible');
                continue;
            }
            $id = SourceItem::idFrom($row['id'] ?? null);
            yield $position => new SourceItem(self::KIND, $id ?? '', $row, $id ?? 'ligne ' . ($position + 1));
        }
    }

    public function inspect(SourceItem $item, ImportContext $context): ItemVerdict
    {
        $resolved = $this->resolve($item->data);
        if (is_string($resolved)) {
            return ItemVerdict::ignored($resolved);
        }
        [$request, $tags] = $resolved;

        $problems = $this->writer()->problems($request);
        if ($problems !== []) {
            return ItemVerdict::ignored($problems[0]);
        }

        $tags[] = self::TAG_YEAR_PREFIX . (string) $item->data['scout_year'];
        $tags[] = self::TAG_STATUS_PREFIX . $request->status;
        if (in_array($request->status, [
            ImportedRequest::STATUS_ACCEPTED,
            ImportedRequest::STATUS_REFUSED,
            ImportedRequest::STATUS_ENCODED,
        ], true)) {
            $tags[] = self::TAG_DECIDED;
        }
        if ($this->writer()->looksLikeExistingRequest($request)) {
            $tags[] = self::TAG_DUPLICATE;
        }

        return ItemVerdict::import()->tagged(...$tags);
    }

    public function write(SourceItem $item, ImportContext $context): WrittenTarget
    {
        $resolved = $this->resolve($item->data);
        if (is_string($resolved)) {
            throw new \LogicException('An item that inspect() let through no longer resolves.');
        }

        return new WrittenTarget(self::TARGET_TYPE, (string) $this->writer()->write($resolved[0]));
    }

    public function previewNotes(AnalysisSummary $summary): array
    {
        $notes = [];

        $years = [];
        $statuses = [];
        foreach ($summary->tags as $tag => $count) {
            if (str_starts_with($tag, self::TAG_YEAR_PREFIX)) {
                $years[] = substr($tag, strlen(self::TAG_YEAR_PREFIX));
            } elseif (str_starts_with($tag, self::TAG_STATUS_PREFIX)) {
                $statuses[substr($tag, strlen(self::TAG_STATUS_PREFIX))] = $count;
            }
        }
        if ($years !== []) {
            sort($years);
            $counts = [];
            foreach (self::STATUS_COUNTS as $status => [$one, $many]) {
                $count = $statuses[$status] ?? 0;
                if ($count > 0) {
                    $counts[] = $count . ' ' . ($count === 1 ? $one : $many);
                }
            }
            $notes[] = PreviewNote::info(
                (count($years) > 1 ? 'Années visées par le fichier : ' : 'Année visée par le fichier : ')
                . implode(', ', $years) . '.'
                . ($counts !== [] ? ' ' . implode(', ', $counts) . '.' : '')
            );
        }

        $decided = $summary->tag(self::TAG_DECIDED);
        if ($decided > 0) {
            $notes[] = PreviewNote::info($decided === 1
                ? 'La décision déjà prise est marquée « Communiquée par l\'ancien site ». La famille la voit tout de suite, sans nouvel e-mail.'
                : 'Les ' . $decided . ' décisions déjà prises sont marquées « Communiquée par l\'ancien site ». Les familles les voient tout de suite, sans nouvel e-mail.');
        }

        $duplicates = $summary->tag(self::TAG_DUPLICATE);
        if ($duplicates > 0) {
            $notes[] = PreviewNote::warning($duplicates === 1
                ? '1 demande ressemble à une demande déjà présente. Elle est importée et signalée « doublon possible », comme une demande normale.'
                : $duplicates . ' demandes ressemblent à une demande déjà présente. Elles sont importées et signalées « doublon possible », comme une demande normale.');
        }

        foreach ([
            self::TAG_SECTION_DROPPED => [
                'vise une section inconnue de ce site',
                'visent une section inconnue de ce site',
                ' : la section souhaitée ou prévue est laissée vide.',
            ],
            self::TAG_FEE_CATEGORY_DROPPED => [
                'vise une catégorie de cotisation inconnue de ce site',
                'visent une catégorie de cotisation inconnue de ce site',
                ' : elle est laissée vide.',
            ],
            self::TAG_SIBLING_DROPPED => [
                'cite un frère ou une sœur introuvable dans Desk',
                'citent un frère ou une sœur introuvable dans Desk',
                ' : ce lien n\'est pas repris.',
            ],
            self::TAG_SECONDARY_EMAIL_DROPPED => [
                'a une adresse secondaire illisible ou dans un état non reconnu',
                'ont une adresse secondaire illisible ou dans un état non reconnu',
                ' : elle n\'est pas reprise.',
            ],
            self::TAG_MEMBER_LINK_DROPPED => [
                'cite un membre Desk sans être encodée',
                'citent un membre Desk sans être encodées',
                ' : ce lien n\'est pas repris, le rapprochement avec Desk le fera comme pour toute demande.',
            ],
        ] as $tag => [$one, $many, $consequence]) {
            $count = $summary->tag($tag);
            if ($count > 0) {
                $notes[] = PreviewNote::warning(
                    $count . ($count === 1 ? ' demande ' . $one : ' demandes ' . $many) . $consequence
                );
            }
        }

        return $notes;
    }

    public function effects(): array
    {
        return [
            'Aucun accusé de réception, aucune alerte à l\'unité, aucune décision envoyée.',
            'Les liens de suivi de l\'ancien site ne fonctionnent pas ici.',
            'Après l\'import, les demandes se traitent comme les autres.',
        ];
    }

    /**
     * The request as the module will file it, every reference resolved,
     * with the tags of the links dropped on the way — or the reason it
     * cannot even be put together.
     *
     * @param array<string, mixed> $row
     * @return array{0: ImportedRequest, 1: list<string>}|string
     */
    private function resolve(array $row): array|string
    {
        $yearLabel = $row['scout_year'] ?? null;
        if (!is_string($yearLabel) || trim($yearLabel) === '') {
            return 'Champ obligatoire manquant : année scoute';
        }
        if (!MissingScoutYears::isLabel($yearLabel)) {
            return 'Année scoute illisible';
        }
        $year = $this->scoutYears->findByLabel($yearLabel);
        if ($year === null) {
            return 'Année scoute absente de ce site';
        }

        $receivedAt = $row['received_at'] ?? null;
        if (!is_string($receivedAt) || trim($receivedAt) === '') {
            return 'Champ obligatoire manquant : date de réception';
        }
        $receivedAt = self::moment($receivedAt);
        if ($receivedAt === null) {
            return 'Date de réception illisible';
        }

        $status = $row['status'] ?? null;
        if (!is_string($status) || trim($status) === '') {
            return 'Champ obligatoire manquant : statut';
        }
        if (!in_array($status, ImportedRequest::STATUSES, true)) {
            // Never converted to the nearest known one: an uncertain
            // correspondence is excluded (spec §11.2).
            return 'Statut non reconnu';
        }

        $decidedAt = null;
        if (!self::isAbsent($row['decided_at'] ?? null)) {
            $decidedAt = is_string($row['decided_at']) ? self::moment($row['decided_at']) : null;
            if ($decidedAt === null) {
                return 'Date de décision illisible';
            }
        }

        $refusalReason = $row['refusal_reason'] ?? null;
        if ($refusalReason !== null && !is_string($refusalReason)) {
            return 'Motif de refus non reconnu';
        }
        $notes = $row['internal_notes'] ?? null;
        if ($notes !== null && !is_string($notes)) {
            return 'Valeur non reconnue : notes internes';
        }

        $tags = [];

        $desiredSectionId = $this->section($row['desired_section_desk_code'] ?? null, $tags);
        $intendedSectionId = $this->section($row['intended_section_desk_code'] ?? null, $tags);

        $feeCategoryId = null;
        $feeCode = $row['fee_category_desk_code'] ?? null;
        if (!self::isAbsent($feeCode)) {
            $feeCategoryId = is_string($feeCode) && trim($feeCode) !== ''
                ? ($this->feeCategories->findByDeskCode(trim($feeCode))['id'] ?? null)
                : null;
            if ($feeCategoryId === null) {
                $tags[] = self::TAG_FEE_CATEGORY_DROPPED;
            }
        }

        $siblings = [];
        $siblingIds = $row['sibling_desk_ids'] ?? [];
        $siblingIds = is_array($siblingIds) && array_is_list($siblingIds) ? $siblingIds : [null];
        $siblingDropped = false;
        foreach ($siblingIds as $deskId) {
            $memberId = $this->memberId($deskId);
            if ($memberId === null) {
                $siblingDropped = true;
                continue;
            }
            $siblings[] = $memberId;
        }
        if ($siblingDropped) {
            $tags[] = self::TAG_SIBLING_DROPPED;
        }

        $linkedMemberId = null;
        $memberDeskId = $row['member_desk_id'] ?? null;
        if ($status === ImportedRequest::STATUS_ENCODED) {
            $linkedMemberId = $this->memberId($memberDeskId);
        } elseif (!self::isAbsent($memberDeskId)) {
            $tags[] = self::TAG_MEMBER_LINK_DROPPED;
        }

        $secondaryEmails = [];
        $rawEmails = $row['secondary_emails'] ?? [];
        $rawEmails = is_array($rawEmails) && array_is_list($rawEmails) ? $rawEmails : [null];
        $emailDropped = false;
        foreach ($rawEmails as $entry) {
            $email = is_array($entry) ? ($entry['email'] ?? null) : null;
            $state = is_array($entry) ? ($entry['status'] ?? null) : null;
            if (!is_string($email)
                || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false
                || !in_array($state, [ImportedSecondaryEmail::STATUS_PENDING, ImportedSecondaryEmail::STATUS_VALID], true)
            ) {
                $emailDropped = true;
                continue;
            }
            $secondaryEmails[] = new ImportedSecondaryEmail(trim($email), $state);
        }
        if ($emailDropped) {
            $tags[] = self::TAG_SECONDARY_EMAIL_DROPPED;
        }

        $fields = [];
        foreach (self::FIELD_MAP as $archiveKey => $moduleKey) {
            $fields[$moduleKey] = $row[$archiveKey] ?? null;
        }

        return [
            new ImportedRequest(
                scoutYearId: (int) $year['id'],
                receivedAt: $receivedAt,
                status: $status,
                fields: $fields,
                refusalReason: $refusalReason !== null && trim($refusalReason) !== '' ? trim($refusalReason) : null,
                decidedAt: $decidedAt,
                desiredSectionId: $desiredSectionId,
                intendedSectionId: $intendedSectionId,
                feeCategoryId: $feeCategoryId,
                internalNotes: $notes,
                siblingMemberIds: array_values(array_unique($siblings)),
                linkedMemberId: $linkedMemberId,
                secondaryEmails: $secondaryEmails
            ),
            array_values(array_unique($tags)),
        ];
    }

    /**
     * A section by Desk code (D10): null for none asked, and for one this
     * site does not know — the link is dropped, and tagged as such.
     *
     * @param list<string> $tags
     */
    private function section(mixed $deskCode, array &$tags): ?int
    {
        if (self::isAbsent($deskCode)) {
            return null;
        }
        $id = $this->sections->find($deskCode);
        if ($id === null) {
            $tags[] = self::TAG_SECTION_DROPPED;
        }

        return $id;
    }

    /** A field left out, null, or an empty string: the conventions read all three alike. */
    private static function isAbsent(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function memberId(mixed $deskId): ?int
    {
        $deskId = SourceItem::idFrom($deskId);

        return $deskId !== null ? ($this->members->findByDeskId($deskId)['id'] ?? null) : null;
    }

    /**
     * A moment as the conventions write it — ISO 8601 with its offset —
     * on this site's clock, since the module stores naive times.
     */
    private static function moment(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return null;
        }

        return DateInput::fromStorage($value)?->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private function writer(): RequestImportWriter
    {
        return $this->writer ?? throw new \LogicException('The registration module is not enabled.');
    }
}
