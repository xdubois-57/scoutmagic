<?php

declare(strict_types=1);

namespace Tests\Modules\News\Repository;

use Modules\News\Repository\NewsForm;
use PHPUnit\Framework\TestCase;

class NewsFormTest extends TestCase
{
    private function build(bool $isForceClosed, ?string $opensAt, ?string $closesAt): NewsForm
    {
        return new NewsForm(1, 1, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, $opensAt, $closesAt, $isForceClosed, 'chief', false, null, null, '2026-01-01 00:00:00');
    }

    public function testOpenWithNoDates(): void
    {
        $this->assertTrue($this->build(false, null, null)->isOpen());
    }

    public function testForceClosedOverridesDates(): void
    {
        $this->assertFalse($this->build(true, null, null)->isOpen());
    }

    public function testClosedBeforeOpensAt(): void
    {
        $form = $this->build(false, '2026-06-01', null);
        $this->assertFalse($form->isOpen(new \DateTimeImmutable('2026-05-01')));
        $this->assertTrue($form->isOpen(new \DateTimeImmutable('2026-06-01')));
    }

    public function testClosedAfterClosesAt(): void
    {
        $form = $this->build(false, null, '2026-06-01');
        $this->assertTrue($form->isOpen(new \DateTimeImmutable('2026-06-01')));
        $this->assertFalse($form->isOpen(new \DateTimeImmutable('2026-06-02')));
    }
}
