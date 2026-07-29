<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Photo\SectionPhotoService;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Core section_photo() component (TwigFactory::create()): resolves a
 * section's staff group photo for the effective scout year, renders
 * nothing when none exists (unlike member_photo() there's no per-person
 * initials fallback), and shows a click-to-replace overlay in
 * configuration mode.
 */
class SectionPhotoFunctionTest extends TestCase
{
    private function buildEnvironment(?int $fileId, bool $configMode): \Twig\Environment
    {
        $service = $this->createMock(SectionPhotoService::class);
        $service->method('resolveFileId')->willReturn($fileId);

        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $twig->setLoader(new ArrayLoader(['test.twig' => '{{ section_photo(5, "Louveteaux") }}']));
        $twig->addGlobal('_section_photo_service', $service);
        $twig->addGlobal('effective_scout_year_id', 1);
        $twig->addGlobal('config_mode', $configMode);

        return $twig;
    }

    public function testRendersPhotoWhenAvailable(): void
    {
        $twig = $this->buildEnvironment(42, false);

        $html = $twig->render('test.twig');

        $this->assertStringContainsString('/files/42', $html);
        $this->assertStringContainsString('aspect-ratio:4/3', $html);
    }

    public function testRendersNothingWhenNoPhotoOutsideConfigMode(): void
    {
        $twig = $this->buildEnvironment(null, false);

        $html = $twig->render('test.twig');

        $this->assertSame('', trim($html));
    }

    public function testConfigModeShowsAddPlaceholderWhenNoPhoto(): void
    {
        $twig = $this->buildEnvironment(null, true);

        $html = $twig->render('test.twig');

        $this->assertStringContainsString('editable-image', $html);
        $this->assertStringContainsString('Cliquer pour ajouter la photo du staff', $html);
    }

    public function testConfigModeAddsEditableOverlay(): void
    {
        $twig = $this->buildEnvironment(42, true);

        $html = $twig->render('test.twig');

        $this->assertStringContainsString('editable-image', $html);
        $this->assertStringContainsString('data-context="section_photo"', $html);
        $this->assertStringContainsString('data-key="5:1"', $html);
        $this->assertStringContainsString('/files/42', $html);
    }

    public function testNoOverlayOutsideConfigMode(): void
    {
        $twig = $this->buildEnvironment(42, false);

        $html = $twig->render('test.twig');

        $this->assertStringNotContainsString('editable-image', $html);
    }
}
