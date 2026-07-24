<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;

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
}
