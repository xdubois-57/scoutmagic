<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\SettingService;
use Modules\Gallery\Api\GalleryException;
use Modules\Gallery\Api\ImportedExternalAlbum;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Service\ExternalAlbumImportService;
use Modules\Gallery\Service\OgImageStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * The gallery's writer for imported external albums: an ordinary external
 * album, created without the « Nouvel album » notification, owned by the
 * administrator who imports, in the year and section the caller chose.
 */
#[Group('database')]
final class ExternalAlbumImportServiceTest extends TestCase
{
    private \PDO $pdo;
    private AlbumRepository $albums;
    private bool $allowExternal = true;
    private int $authorId;
    private int $scoutYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $this->albums = new AlbumRepository($this->pdo);

        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')->execute([
            'enc',
            'idx'
        ]);
        $this->authorId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date) VALUES ('2018-2019', '2018-09-01', '2019-08-31')"
        );
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)')
            ->execute([(int) $this->pdo->lastInsertId(), 'MEUTE_A', 'Meute A']);
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    public function testItCreatesAnOrdinaryExternalAlbumWithThePagesData(): void
    {
        $images = $this->createMock(OgImageStore::class);
        $images->expects($this->once())->method('store')
            ->with($this->greaterThan(0), 'image-bytes', $this->authorId)
            ->willReturn(null);

        $id = $this->service($images)->createImportedExternalAlbum($this->album(
            ogTitle: '  ' . str_repeat('t', 300) . '  ',
            ogDescription: 'Ce que la page dit',
            ogImageBytes: 'image-bytes'
        ));

        $album = $this->albums->findById($id) ?? $this->fail('No album.');
        $this->assertSame(Album::TYPE_EXTERNAL, $album->type);
        $this->assertSame('Camp de Pâques', $album->title);
        $this->assertSame('Au bord de la Semois', $album->subtitle);
        $this->assertSame('2019-04-20', $album->albumDate);
        $this->assertSame($this->sectionId, $album->sectionId);
        $this->assertSame($this->scoutYearId, $album->scoutYearId);
        $this->assertSame(
            'https://Photos.Example.org/a#1',
            $album->externalUrl,
            'The address is kept as the old site gave it.'
        );
        $this->assertSame($this->authorId, $album->createdBy);
        $this->assertNull($album->locationId, 'An external album has no storage location.');
        $this->assertNull($album->ownerType, 'It is an ordinary album, in the gallery\'s own lists.');
        $this->assertSame(255, mb_strlen((string) $album->ogTitle), 'The page\'s title is clipped to its column.');
        $this->assertSame('Ce que la page dit', $album->ogDescription);
    }

    public function testThePagesImageIsKeptAsTheAlbumsOwnFile(): void
    {
        $this->pdo->exec(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min)
             VALUES ('gallery/1/og/x.png', 'og', 'image/png', 1, 'identified')"
        );
        $fileId = (int) $this->pdo->lastInsertId();
        $images = $this->createStub(OgImageStore::class);
        $images->method('store')->willReturn($fileId);

        $id = $this->service($images)->createImportedExternalAlbum($this->album(ogImageBytes: 'png'));

        $this->assertSame($fileId, $this->albums->findById($id)?->ogImageFileId);
    }

    /**
     * Nothing announces it: the service is built without any notification
     * service at all, and nothing reaches the notifications table.
     */
    public function testNoNotificationIsSent(): void
    {
        $this->service($this->createStub(OgImageStore::class))->createImportedExternalAlbum($this->album());

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
        $constructor = (new \ReflectionClass(ExternalAlbumImportService::class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $this->assertStringNotContainsString('Notification', (string) $parameter->getType());
        }
    }

    public function testAnAlbumWithoutPageDataHasNoOpenGraphFields(): void
    {
        $id = $this->service($this->createStub(OgImageStore::class))->createImportedExternalAlbum($this->album());

        $album = $this->albums->findById($id) ?? $this->fail('No album.');
        $this->assertNull($album->ogTitle);
        $this->assertNull($album->ogImageFileId);
        $this->assertSame('Camp de Pâques', $album->displayTitle());
    }

    public function testTheGalleryRulesStillApply(): void
    {
        $service = $this->service($this->createStub(OgImageStore::class));

        foreach ([
            'not a web address' => $this->album(url: 'ftp://example.org/a'),
            'address too long' => $this->album(url: 'https://example.org/' . str_repeat('a', 490)),
            'blank title' => $this->album(title: '   '),
            'title too long' => $this->album(title: str_repeat('t', 256)),
            'not an ISO date' => $this->album(albumDate: '20/04/2019'),
        ] as $case => $invalid) {
            try {
                $service->createImportedExternalAlbum($invalid);
                $this->fail('Created despite: ' . $case);
            } catch (GalleryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_albums')->fetchColumn());
    }

    public function testNoExternalAlbumIsCreatedWhileTheGalleryDisablesThem(): void
    {
        $this->allowExternal = false;
        $service = $this->service($this->createStub(OgImageStore::class));

        $this->assertFalse($service->externalAlbumsAllowed());
        $this->expectException(GalleryException::class);
        $service->createImportedExternalAlbum($this->album());
    }

    public function testAnAddressIsFoundAfterNormalisationAndOnlyThen(): void
    {
        $service = $this->service($this->createStub(OgImageStore::class));
        $id = $service->createImportedExternalAlbum($this->album(url: 'HTTPS://Photos.Example.org/album/42?x=1#cover'));
        $this->albums->create(
            Album::TYPE_LOCAL,
            'Local',
            null,
            '2019-04-20',
            null,
            $this->scoutYearId,
            null,
            null,
            $this->authorId
        );

        $this->assertSame($id, $service->findExternalAlbumByUrl(' https://photos.example.org/album/42?x=1 '));
        $this->assertSame($id, $service->findExternalAlbumByUrl('https://PHOTOS.example.org/album/42?x=1#autre'));
        $this->assertNull($service->findExternalAlbumByUrl('https://photos.example.org/album/42?x=2'));
        $this->assertNull(
            $service->findExternalAlbumByUrl('https://photos.example.org/album/42'),
            'A prefix is not the same album.'
        );
        $this->assertNull($service->findExternalAlbumByUrl('pas une adresse'));
    }

    public function testOnlyAnAbsoluteWebAddressIsAcceptable(): void
    {
        $service = $this->service($this->createStub(OgImageStore::class));

        $this->assertTrue($service->isAcceptableUrl('https://photos.example.org:8443/album'));
        $this->assertFalse($service->isAcceptableUrl('ftp://photos.example.org/album'));
        $this->assertFalse($service->isAcceptableUrl('https://photos.example.org:bad/album'));
        $this->assertFalse($service->isAcceptableUrl('pas une adresse'));
    }

    private function service(OgImageStore $images): ExternalAlbumImportService
    {
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturnCallback(
            fn (string $key, string $module, mixed $default): mixed
                => $key === 'gallery_allow_external' ? $this->allowExternal : $default
        );

        return new ExternalAlbumImportService($this->albums, $images, $settings);
    }

    private function album(
        string $url = 'https://Photos.Example.org/a#1',
        string $title = 'Camp de Pâques',
        string $albumDate = '2019-04-20',
        ?string $ogTitle = null,
        ?string $ogDescription = null,
        ?string $ogImageBytes = null
    ): ImportedExternalAlbum {
        return new ImportedExternalAlbum(
            $url,
            $title,
            ' Au bord de la Semois ',
            $albumDate,
            $this->sectionId,
            $this->scoutYearId,
            $this->authorId,
            $ogTitle,
            $ogDescription,
            $ogImageBytes
        );
    }
}
