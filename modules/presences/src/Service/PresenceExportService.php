<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

use Core\Export\TabularSpreadsheet;
use Core\Member\SectionService;
use Core\Service\DateInput;
use Modules\Calendar\Api\SectionEvent;
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Value\PresenceStatus;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * The section's whole year as an .xlsx: every animé, every evening, the
 * state and the comment. It is where somebody goes for the detail the
 * screens summarise on purpose.
 *
 * **One row per (animé, evening), not one column per evening.** Thirty
 * meetings would be sixty columns once each carries a state and a
 * comment, which no spreadsheet reader sorts or filters; long is the
 * shape a pivot table, a filter and a sort all understand. The screens
 * are what answer « d'un coup d'œil » — this file answers « montre-moi
 * tout ».
 *
 * **It goes through Core\Export\TabularSpreadsheet**, the site's brick
 * for a module's own domain, rather than through
 * Core\Member\Export\MemberExportService: that one owns the canonical
 * MEMBER columns and its own docblock refuses to grow them with a
 * module's data. What the roadmap asked of it — that a value beginning
 * with `=`, `+`, `-` or `@` can never be written as a live formula
 * (SECURITY.md §23) — is exactly what TabularSpreadsheet guarantees, by
 * writing every cell with an explicit string type. A comment is free
 * text somebody typed, so this is not a theoretical concern here.
 */
class PresenceExportService
{
    public function __construct(
        private PresenceRegisterService $registerService,
        private SectionService $sectionService,
        private PresenceRepository $repository
    ) {
    }

    /**
     * @return array{0: Spreadsheet, 1: string, 2: array{animes: int, events: int, rows: int, comments: int}}
     *         the workbook, the file name, and the counters the journal
     *         records — counters only, never a name (see the controller)
     */
    public function build(int $sectionId, int $scoutYearId, string $scoutYearLabel): array
    {
        $section = $this->sectionService->getSection($sectionId);
        $sectionLabel = PresenceSheetService::sectionLabel($section);
        $events = $this->registerService->sectionEvents($sectionId, $scoutYearId);
        $animes = $this->sectionService->getSectionAnimes($sectionId, $scoutYearId);

        $records = $this->repository->findByEvents(
            array_map(static fn(SectionEvent $event): int => $event->id, $events)
        );

        $rows = [];
        $comments = 0;
        foreach ($animes as $anime) {
            foreach ($events as $event) {
                $record = $records[$event->id][$anime->memberId] ?? null;
                $status = $record !== null ? $record->status : PresenceStatus::UNSET;
                if ($record?->comment !== null) {
                    $comments++;
                }

                $rows[] = [
                    $anime->lastName,
                    $anime->firstName,
                    $anime->totem ?? '',
                    self::displayDate($event->startDate),
                    $event->title,
                    $status->label(),
                    $record !== null ? ($record->comment ?? '') : '',
                ];
            }
        }

        $spreadsheet = TabularSpreadsheet::buildSpreadsheet(
            self::HEADERS,
            $rows,
            $sectionLabel . ' ' . $scoutYearLabel
        );

        return [
            $spreadsheet,
            self::fileName($sectionLabel, $scoutYearLabel),
            [
                'animes' => count($animes),
                'events' => count($events),
                'rows' => count($rows),
                'comments' => $comments,
            ],
        ];
    }

    /**
     * French headers, because the file is opened by a chef d'unité and
     * not by a program (AGENTS.md § Language: the interface is French,
     * and a spreadsheet somebody reads is interface).
     */
    private const HEADERS = ['Nom', 'Prénom', 'Totem', 'Date', 'Évènement', 'État', 'Commentaire'];

    private static function displayDate(string $storedDate): string
    {
        return DateInput::fromStorage($storedDate)?->format('d/m/Y') ?? $storedDate;
    }

    /**
     * A name somebody can find again in their downloads folder, with
     * nothing in it that a filesystem, an e-mail gateway or a zip refuses
     * — the section may be called « Louveteaux 1 / Meute » and a slash is
     * a path separator.
     */
    private static function fileName(string $sectionLabel, string $scoutYearLabel): string
    {
        $slug = (string) preg_replace('/[^A-Za-z0-9]+/', '-', $sectionLabel . '-' . $scoutYearLabel);
        $slug = trim($slug, '-');

        return 'presences-' . ($slug !== '' ? $slug : 'section') . '.xlsx';
    }
}
