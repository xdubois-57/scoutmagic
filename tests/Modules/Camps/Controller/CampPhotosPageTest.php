<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;
use Twig\Environment;

/**
 * What the photos page of a stay says, in each of the states it can be in
 * (issue #637).
 *
 * The page used to know two states, and the second of them — the gallery
 * module is on, but it refused this stay an album — was announced as the
 * first: « le module Galerie est désactivé sur ce site ». A chief sent to
 * the module settings found the gallery enabled and had nowhere left to
 * look. And a photo list that could not be read looked exactly like a stay
 * with no photo.
 *
 * `CampsAttachmentController::photos()` now hands the template the pieces
 * separately; this pins what each combination reads as.
 */
final class CampPhotosPageTest extends TestCase
{
    private const MODULE_OFF = 'le module Galerie est désactivé';
    private const ALBUM_REFUSED = 'Les photos ne sont pas disponibles pour ce séjour.';
    private const UNREADABLE = 'pas pu être affichées';
    private const NO_PHOTO = 'Aucune photo pour ce séjour.';
    private const UPLOAD_FORM = 'Ajouter une photo';

    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TestTwig::create(['camps']);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>, list<string>}>
     */
    public static function states(): array
    {
        return [
            'the gallery module is off' => [
                ['gallery_enabled' => false, 'album_available' => false, 'media_unreadable' => false],
                [self::MODULE_OFF],
                [self::ALBUM_REFUSED, self::UNREADABLE, self::NO_PHOTO, self::UPLOAD_FORM],
            ],
            // The case #637 is about: never « désactivé » while it is on.
            'the gallery is on but refused an album' => [
                ['gallery_enabled' => true, 'album_available' => false, 'media_unreadable' => false],
                [self::ALBUM_REFUSED],
                [self::MODULE_OFF, self::UNREADABLE, self::NO_PHOTO, self::UPLOAD_FORM],
            ],
            // Not « no photo »: the photos exist, they could not be read.
            'the album is there but could not be read' => [
                ['gallery_enabled' => true, 'album_available' => true, 'media_unreadable' => true],
                [self::UNREADABLE, self::UPLOAD_FORM],
                [self::MODULE_OFF, self::ALBUM_REFUSED, self::NO_PHOTO],
            ],
            'the album is there and empty' => [
                ['gallery_enabled' => true, 'album_available' => true, 'media_unreadable' => false],
                [self::NO_PHOTO, self::UPLOAD_FORM],
                [self::MODULE_OFF, self::ALBUM_REFUSED, self::UNREADABLE],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $says
     * @param list<string> $neverSays
     */
    #[DataProvider('states')]
    public function testEachStateSaysWhatIsTrueAndNothingElse(array $state, array $says, array $neverSays): void
    {
        $html = $this->twig->render('@camps/photos.html.twig', $state + [
            'camp' => (object) ['id' => 12],
            'camp_label' => 'Juillet 2028',
            'place' => null,
            'media' => [],
        ]);

        foreach ($says as $sentence) {
            $this->assertStringContainsString($sentence, $html);
        }
        foreach ($neverSays as $sentence) {
            $this->assertStringNotContainsString($sentence, $html);
        }
    }

    /**
     * The server half of issue #756: the page has to ASK for the thumbnail
     * and for the submit lock, or the shared JavaScript leaves it exactly
     * as it was. Both are opt-ins, and an opt-in nobody wrote down is a
     * feature that silently does not exist — which is what a unit getting
     * the same photo twice was.
     *
     * One assertion per promise, read off the rendered page rather than
     * off the template file: a `data` hash that stopped reaching the
     * partial would still look right in the source.
     */
    public function testThePageAsksForThePreviewAndTheSubmitLock(): void
    {
        $html = $this->twig->render('@camps/photos.html.twig', [
            'gallery_enabled' => true,
            'album_available' => true,
            'media_unreadable' => false,
            'camp' => (object) ['id' => 12],
            'camp_label' => 'Juillet 2028',
            'place' => null,
            'media' => [],
        ]);

        $this->assertStringContainsString(
            'data-drop-zone-preview="image"',
            $html,
            'the photo zone no longer asks for a thumbnail, so picking a photo says only '
            . 'IMG_4821.HEIC again (issue #756).'
        );
        $this->assertStringContainsString(
            'data-submit-lock',
            $html,
            'the upload form no longer asks for the submit lock, so a second tap during the '
            . 'several seconds a phone photo takes to leave sends it twice (issue #756).'
        );
        $this->assertStringContainsString(
            'form-submit-lock.js',
            $html,
            'the page asks for the submit lock and never loads the script that honours it.'
        );
    }
}
