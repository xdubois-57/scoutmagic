<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\EditableContentService;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

class TwigFactoryTest extends TestCase
{
    public function testFrenchDateFormatsAStringDate(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $twig->getLoader()->exists('base.html.twig');

        $env = $twig;
        $filter = null;
        foreach ($env->getFilters() as $f) {
            if ($f->getName() === 'french_date') {
                $filter = $f;
            }
        }

        $this->assertNotNull($filter);
        $callable = $filter->getCallable();
        $this->assertSame('12 juillet 2026', $callable('2026-07-12'));
        $this->assertSame('1 janvier 2026', $callable(new \DateTimeImmutable('2026-01-01')));
        $this->assertSame('', $callable(null));
        $this->assertSame('31 décembre 2026', $callable('2026-12-31 10:30:00'));
    }

    private function editableImageFunction(Environment $twig): callable
    {
        foreach ($twig->getFunctions() as $f) {
            if ($f->getName() === 'editable_image') {
                return $f->getCallable();
            }
        }
        $this->fail('editable_image() function not registered.');
    }

    /**
     * Reproduces a real bug: with no image set yet, editable_image()
     * rendered a bare placeholder box with no .editable-edit-btn at all —
     * editable.js only wires up clicks on that class, so clicking the
     * placeholder did nothing. It must render the same overlay/button as
     * the "already has an image" case (and as member_photo()/
     * section_photo()'s own empty states already correctly do).
     */
    public function testEditableImageRendersAClickableButtonWhenNoImageIsSetYet(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $service = $this->createMock(EditableContentService::class);
        $service->method('get')->willReturn(null);
        $twig->addGlobal('_editable_content_service', $service);
        $twig->addGlobal('config_mode', true);

        $html = ($this->editableImageFunction($twig))('home.hero', 'Photo d\'accueil');

        $this->assertStringContainsString('editable-edit-btn', $html);
        $this->assertStringContainsString('Ajouter', $html);
        $this->assertStringContainsString('data-key="home.hero"', $html);
    }

    public function testEditableImageRendersAChangerButtonWhenAnImageAlreadyExists(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $service = $this->createMock(EditableContentService::class);
        $service->method('get')->willReturn('42');
        $twig->addGlobal('_editable_content_service', $service);
        $twig->addGlobal('config_mode', true);

        $html = ($this->editableImageFunction($twig))('home.hero', 'Photo d\'accueil');

        $this->assertStringContainsString('editable-edit-btn', $html);
        $this->assertStringContainsString('Changer', $html);
        $this->assertStringContainsString('/files/42', $html);
    }

    public function testEditableImageRendersNothingOutsideConfigModeWithNoImage(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $service = $this->createMock(EditableContentService::class);
        $service->method('get')->willReturn(null);
        $twig->addGlobal('_editable_content_service', $service);
        $twig->addGlobal('config_mode', false);

        $html = ($this->editableImageFunction($twig))('home.hero');

        $this->assertSame('', $html);
    }
}
