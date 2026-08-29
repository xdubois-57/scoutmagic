<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\DeskImportListener;
use Core\Import\DeskImportListenerRegistry;
use PHPUnit\Framework\TestCase;

class DeskImportListenerRegistryTest extends TestCase
{
    public function testStartsEmptyAndReturnsListenersInRegistrationOrder(): void
    {
        $registry = new DeskImportListenerRegistry();
        $this->assertSame([], $registry->all());

        $first = $this->createMock(DeskImportListener::class);
        $second = $this->createMock(DeskImportListener::class);
        $registry->register($first);
        $registry->register($second);

        $this->assertSame([$first, $second], $registry->all());
    }

    public function testAListenerRegisteredAfterTheServiceWasBuiltStillReconciles(): void
    {
        // The reason this is an object and not an array passed by value:
        // DeskImportService is constructed near the top of the composition
        // root, module blocks register thousands of lines later, and the
        // import must still see them.
        $registry = new DeskImportListenerRegistry();
        $snapshotTakenAtConstruction = $registry->all();

        $late = $this->createMock(DeskImportListener::class);
        $registry->register($late);

        $this->assertSame([], $snapshotTakenAtConstruction);
        $this->assertSame([$late], $registry->all());
    }
}
