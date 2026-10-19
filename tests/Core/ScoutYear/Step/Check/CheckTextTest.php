<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\ScoutYear\Step\Check\CheckText;
use PHPUnit\Framework\TestCase;

/**
 * The counts the step checks write: French keeps 0 and 1 in the singular.
 */
class CheckTextTest extends TestCase
{
    public function testCountIsSingularUpToOne(): void
    {
        $this->assertSame('0 foyer à corriger', CheckText::count(0, 'foyer à corriger', 'foyers à corriger'));
        $this->assertSame('1 foyer à corriger', CheckText::count(1, 'foyer à corriger', 'foyers à corriger'));
        $this->assertSame('3 foyers à corriger', CheckText::count(3, 'foyer à corriger', 'foyers à corriger'));
    }
}
