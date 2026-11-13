<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Service\AlbumVisibility;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * The one album-visibility rule (issue #1112, audit SEC-013): below
 * `chief`, the album's scout year must be the current or the previous one
 * AND the album must be unit-wide or of a linked section. The list used to
 * ask both halves and every direct route only the second.
 */
class AlbumVisibilityTest extends TestCase
{
    private const CURRENT = 50;
    private const PREVIOUS = 49;
    private const FOUR_YEARS_AGO = 46;
    private const OWN_SECTION = 7;
    private const OTHER_SECTION = 8;

    private static function album(?int $sectionId, int $scoutYearId): Album
    {
        return new Album(
            id: 1,
            type: Album::TYPE_LOCAL,
            title: 'Camp',
            subtitle: null,
            albumDate: '2026-01-01',
            sectionId: $sectionId,
            scoutYearId: $scoutYearId,
            coverMediaId: null,
            externalUrl: null,
            ogTitle: null,
            ogDescription: null,
            ogImageUrl: null,
            ogImageFileId: null,
            locationId: null,
            migrationStatus: Album::MIGRATION_NONE,
            migrationTargetId: null,
            migrationError: null,
            createdBy: 1,
            createdAt: '2026-01-01 00:00:00'
        );
    }

    private static function parent(): AlbumVisibility
    {
        return AlbumVisibility::forMember([self::CURRENT, self::PREVIOUS], [self::OWN_SECTION]);
    }

    public function testAMemberSeesAUnitAlbumOfTheCurrentAndThePreviousYear(): void
    {
        $this->assertTrue(self::parent()->allows(self::album(null, self::CURRENT)));
        $this->assertTrue(self::parent()->allows(self::album(null, self::PREVIOUS)));
    }

    public function testAMemberSeesTheirOwnSectionsAlbumOfARelevantYear(): void
    {
        $this->assertTrue(self::parent()->allows(self::album(self::OWN_SECTION, self::PREVIOUS)));
    }

    public function testAMemberDoesNotSeeAnotherSectionsAlbumEvenThisYear(): void
    {
        $this->assertFalse(self::parent()->allows(self::album(self::OTHER_SECTION, self::CURRENT)));
    }

    /**
     * The defect itself: the direct routes asked only the section, so a
     * unit-wide album was open to every parent whatever its year.
     */
    public function testAMemberDoesNotSeeAUnitAlbumFromFourYearsAgo(): void
    {
        $this->assertFalse(self::parent()->allows(self::album(null, self::FOUR_YEARS_AGO)));
    }

    public function testAMemberDoesNotSeeTheirOwnSectionsAlbumFromFourYearsAgo(): void
    {
        $this->assertFalse(self::parent()->allows(self::album(self::OWN_SECTION, self::FOUR_YEARS_AGO)));
    }

    public function testAMemberWithNoRelevantYearSeesNothing(): void
    {
        $noYear = AlbumVisibility::forMember([], [self::OWN_SECTION]);

        $this->assertFalse($noYear->allows(self::album(null, self::CURRENT)));
    }

    public function testAChiefSeesEveryYearAndEverySection(): void
    {
        $chief = AlbumVisibility::everything();

        $this->assertTrue($chief->seesEverything());
        $this->assertTrue($chief->allows(self::album(null, self::FOUR_YEARS_AGO)));
        $this->assertTrue($chief->allows(self::album(self::OTHER_SECTION, self::FOUR_YEARS_AGO)));
    }

    public function testAMemberVisibilityExposesTheYearsAndSectionsTheListFiltersOn(): void
    {
        $visibility = AlbumVisibility::forMember([self::CURRENT, self::PREVIOUS, self::CURRENT], [self::OWN_SECTION]);

        $this->assertFalse($visibility->seesEverything());
        $this->assertSame([self::CURRENT, self::PREVIOUS], $visibility->scoutYearIds());
        $this->assertSame([self::OWN_SECTION], $visibility->sectionIds());
    }

    /**
     * The list (Repository\AlbumRepository::findVisible(), in SQL) and the
     * direct routes (AlbumVisibility::allows(), in PHP) must give the SAME
     * answer — the issue was exactly the two drifting apart. Five years,
     * three sections plus unit-wide, one delegated album the list must
     * never show, and several callers: whatever the list shows is allowed,
     * and whatever is allowed is in the list.
     */
    #[Group('database')]
    public function testTheListAndTheDirectRoutesAgreeOverEveryYearAndSection(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($pdo);
        $pdo->exec("INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES ('enc', 'idx')");
        $authorId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $pdo->lastInsertId();

        $sectionIds = [];
        $insertSection = $pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        foreach (['A', 'B', 'C'] as $code) {
            $insertSection->execute([$branchId, 'S_' . $code, 'Section ' . $code]);
            $sectionIds[] = (int) $pdo->lastInsertId();
        }

        $yearIds = [];
        $insertYear = $pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, ?)'
        );
        foreach ([2021, 2022, 2023, 2024, 2025] as $start) {
            $insertYear->execute([
                $start . '-' . ($start + 1), $start . '-09-01', ($start + 1) . '-08-31', $start === 2025 ? 1 : 0,
            ]);
            $yearIds[] = (int) $pdo->lastInsertId();
        }

        $repository = new AlbumRepository($pdo);
        $albumIds = [];
        foreach ($yearIds as $yearId) {
            foreach ([null, ...$sectionIds] as $sectionId) {
                $albumIds[] = $repository->create(
                    Album::TYPE_LOCAL, 'Album', null, '2024-01-01', $sectionId, $yearId, null, null, $authorId
                );
            }
        }
        $repository->create(
            Album::TYPE_LOCAL, 'Délégué', null, '2024-01-01', null, $yearIds[4], null, null, $authorId, 'owner', 1
        );

        $albums = [];
        foreach ($albumIds as $id) {
            $album = $repository->findById($id);
            $this->assertNotNull($album);
            $albums[] = $album;
        }

        $relevant = [$yearIds[4], $yearIds[3]];
        $callers = [
            'parent of no section' => AlbumVisibility::forMember($relevant, []),
            'parent of section A' => AlbumVisibility::forMember($relevant, [$sectionIds[0]]),
            'parent of sections A and C' => AlbumVisibility::forMember($relevant, [$sectionIds[0], $sectionIds[2]]),
            'only the current year' => AlbumVisibility::forMember([$yearIds[4]], [$sectionIds[1]]),
            'no year at all' => AlbumVisibility::forMember([], [$sectionIds[1]]),
        ];

        foreach ($callers as $label => $visibility) {
            $listed = array_map(
                static fn(Album $a): int => $a->id,
                $repository->findVisible($visibility->sectionIds(), $visibility->scoutYearIds())
            );
            $allowed = array_map(
                static fn(Album $a): int => $a->id,
                array_values(array_filter($albums, static fn(Album $a): bool => $visibility->allows($a)))
            );
            sort($listed);
            sort($allowed);

            $this->assertSame($allowed, $listed, $label . ': the list and the direct routes disagree');
        }

        // The matrix is not vacuous: a parent of section A sees exactly
        // two unit albums and two of section A's, out of twenty.
        $this->assertCount(4, $repository->findVisible([$sectionIds[0]], $relevant));

        $chief = AlbumVisibility::everything();
        foreach ($albums as $album) {
            $this->assertTrue($chief->allows($album), 'a chief opens every album of every year');
        }
    }
}
