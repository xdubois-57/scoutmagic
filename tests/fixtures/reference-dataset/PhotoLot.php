<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * What is known about the photo lot in `photos/`, declared rather than
 * guessed.
 *
 * **The branch prefix of a filename is an initial assignment hint, not an
 * identity.** Once a photo belongs to a Tiers it follows that person: the
 * Pionnier promoted to Baladins animateur (scenario 8) and the animateur who
 * changes section (scenario 9) keep their file, whose name then stops
 * describing their current branch. That is correct, and it is written down
 * here and in README.md §4.3 so nobody "fixes" the inconsistency later.
 */
final class PhotoLot
{
    public const DIRECTORY = 'photos';

    /**
     * The gender each synthetic portrait reads as, so that a member record and
     * the picture rendered next to it never contradict each other on screen.
     *
     * Recorded by looking at the lot once, when it was re-encoded (IT-01), and
     * versioned here rather than re-derived: nothing in a JPEG says this, and a
     * mismatch is only ever visible to a human looking at the page.
     *
     * 16 F and 24 M — the imbalance is the lot's, and the assigner works with
     * it rather than against it.
     *
     * @var array<string, string>
     */
    public const INDIVIDUAL_GENDERS = [
        'baladin_individual_001.jpg' => 'F',
        'baladin_individual_002.jpg' => 'M',
        'baladin_individual_003.jpg' => 'M',
        'baladin_individual_004.jpg' => 'M',
        'baladin_individual_005.jpg' => 'F',
        'baladin_individual_006.jpg' => 'F',
        'eclaireur_individual_001.jpg' => 'M',
        'eclaireur_individual_002.jpg' => 'M',
        'eclaireur_individual_003.jpg' => 'M',
        'eclaireur_individual_004.jpg' => 'M',
        'eclaireur_individual_005.jpg' => 'F',
        'eclaireur_individual_006.jpg' => 'F',
        'eclaireur_individual_007.jpg' => 'F',
        'eclaireur_individual_008.jpg' => 'F',
        'eclaireur_individual_009.jpg' => 'M',
        'louveteau_individual_001.jpg' => 'M',
        'louveteau_individual_002.jpg' => 'M',
        'louveteau_individual_003.jpg' => 'F',
        'louveteau_individual_004.jpg' => 'F',
        'louveteau_individual_005.jpg' => 'F',
        'louveteau_individual_006.jpg' => 'M',
        'louveteau_individual_007.jpg' => 'M',
        'louveteau_individual_008.jpg' => 'F',
        'pionnier_individual_001.jpg' => 'M',
        'pionnier_individual_002.jpg' => 'M',
        'pionnier_individual_003.jpg' => 'M',
        'pionnier_individual_004.jpg' => 'M',
        'pionnier_individual_005.jpg' => 'F',
        'pionnier_individual_006.jpg' => 'F',
        'pionnier_individual_007.jpg' => 'F',
        'pionnier_individual_008.jpg' => 'M',
        'unite_individual_001.jpg' => 'M',
        'unite_individual_002.jpg' => 'M',
        'unite_individual_003.jpg' => 'M',
        'unite_individual_004.jpg' => 'F',
        'unite_individual_005.jpg' => 'M',
        'unite_individual_006.jpg' => 'M',
        'unite_individual_007.jpg' => 'F',
        'unite_individual_008.jpg' => 'M',
        'unite_individual_009.jpg' => 'M',
    ];

    /**
     * The section handle each individual photo's prefix suggests. The assigner
     * honours it when a cadre of the right gender is free there, and says so
     * in the manifest when it cannot.
     *
     * @var array<string, list<string>>
     */
    public const PREFIX_SECTIONS = [
        'baladin' => ['bal1', 'bal2'],
        'louveteau' => ['lou1', 'lou2'],
        'eclaireur' => ['ecl1'],
        'pionnier' => ['pio1'],
        'unite' => [self::UNIT_STAFF_HANDLE],
    ];

    /**
     * The Staff d'U is a real section like any other, but it never appears in
     * a Desk export's Section column — UnitStaffSectionService synthesises it.
     * This handle stands for it in the assignment manifest; the builder
     * resolves it through UnitStaffSectionService::DESK_CODE.
     */
    public const UNIT_STAFF_HANDLE = 'staffdu';

    /**
     * Group photos, section by section and year by year — declared by hand,
     * because each line is a decision about what the site should be seen
     * doing, not a slot to be filled.
     *
     * Four shapes are deliberately represented:
     *
     *   - `bal1` has one every year: the ordinary case, and the only one that
     *     exercises the exact-year hit three times over.
     *   - `lou1` has one in A1 and nothing after. SectionPhotoService::
     *     resolveFileId() must fall back to the most recent earlier year — the
     *     one behaviour that is otherwise assumed rather than exercised.
     *   - `bal2` is created in A2 and photographed that year only; `lou2`,
     *     `ecl1` and `pio1` each skip a year somewhere.
     *   - `rou1` and `iam1` have none at all, ever. section_photo() has no
     *     initials fallback by design, so those two sections show nothing —
     *     which is a normal state of the site and must be in the dataset.
     *
     * All 14 files are used. A photo nobody references is a silent
     * desynchronisation, and generate.php --check fails on one.
     *
     * @var array<string, array<string, string>>
     */
    public const GROUP_PHOTOS = [
        'bal1' => [
            '2024-2025' => 'baladin_group_001.jpg',
            '2025-2026' => 'baladin_group_002.jpg',
            '2026-2027' => 'baladin_group_003.jpg',
        ],
        'bal2' => [
            '2025-2026' => 'baladin_group_004.jpg',
        ],
        'lou1' => [
            '2024-2025' => 'louveteau_group_001.jpg',
        ],
        'lou2' => [
            '2024-2025' => 'louveteau_group_002.jpg',
            '2026-2027' => 'louveteau_group_003.jpg',
        ],
        'ecl1' => [
            '2024-2025' => 'eclaireur_group_001.jpg',
            '2025-2026' => 'eclaireur_group_002.jpg',
        ],
        'pio1' => [
            '2024-2025' => 'pionnier_group_001.jpg',
            '2026-2027' => 'pionnier_group_002.jpg',
        ],
        self::UNIT_STAFF_HANDLE => [
            '2024-2025' => 'unite_group_001.jpg',
            '2025-2026' => 'unite_group_002.jpg',
            '2026-2027' => 'unite_group_003.jpg',
        ],
    ];

    /**
     * How many cadres get a SECOND, different portrait in a later year.
     *
     * Most cadres are photographed once, in their first year, and the site's
     * fallback produces their photo in the years after — which is what
     * actually exercises MemberPhotoService::resolveFileId()'s earlier-year
     * branch. These few exist so the exact-year hit and the replacement of an
     * existing photo are exercised too.
     */
    public const RE_PHOTOGRAPHED_COUNT = 3;

    /** @return list<string> every file the lot contains, in a stable order */
    public static function allFiles(string $datasetRoot): array
    {
        $files = glob($datasetRoot . '/' . self::DIRECTORY . '/*.jpg');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);

        return array_map(static fn (string $path): string => basename($path), $files);
    }

    /** The prefix of a photo filename: everything before `_individual`/`_group`. */
    public static function prefixOf(string $filename): string
    {
        return (string) preg_replace('/_(individual|group)_\d+\.jpg$/', '', $filename);
    }
}
