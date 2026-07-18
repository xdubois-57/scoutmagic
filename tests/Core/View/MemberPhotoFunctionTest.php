<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Photo\MemberPhotoService;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Core member_photo() component (TwigFactory::create()): resolves a member's
 * photo for the effective scout year, falls back to an initials-in-a-circle
 * avatar (same style as the account menu) when none exists, and shows a
 * click-to-replace overlay in configuration mode.
 */
class MemberPhotoFunctionTest extends TestCase
{
    private function buildEnvironment(?int $fileId, bool $configMode): \Twig\Environment
    {
        $service = $this->createMock(MemberPhotoService::class);
        $service->method('resolveFileId')->willReturn($fileId);

        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $twig->setLoader(new ArrayLoader(['test.twig' => '{{ member_photo(5, "Jean") }}']));
        $twig->addGlobal('_member_photo_service', $service);
        $twig->addGlobal('effective_scout_year_id', 1);
        $twig->addGlobal('config_mode', $configMode);

        return $twig;
    }

    public function testRendersPhotoWhenAvailable(): void
    {
        $twig = $this->buildEnvironment(42, false);

        $html = $twig->render('test.twig');

        $this->assertStringContainsString('/files/42', $html);
        $this->assertStringNotContainsString('member-photo-placeholder', $html);
    }

    public function testRendersInitialsAvatarWhenNoPhoto(): void
    {
        $twig = $this->buildEnvironment(null, false);

        $html = $twig->render('test.twig');

        $this->assertStringContainsString('member-photo-placeholder', $html);
        $this->assertStringContainsString('member-photo-initials', $html);
        $this->assertStringContainsString('>JE<', $html);
    }

    public function testConfigModeAddsEditableOverlay(): void
    {
        $twig = $this->buildEnvironment(null, true);

        $html = $twig->render('test.twig');

        $this->assertStringContainsString('editable-image', $html);
        $this->assertStringContainsString('data-context="member_photo"', $html);
        $this->assertStringContainsString('data-key="5:1"', $html);
    }

    public function testNoOverlayOutsideConfigMode(): void
    {
        $twig = $this->buildEnvironment(42, false);

        $html = $twig->render('test.twig');

        $this->assertStringNotContainsString('editable-image', $html);
    }
}
