<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Api\AlbumAction;
use Modules\Gallery\Api\AlbumActionProviderInterface;
use Modules\Gallery\Service\AlbumActionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The actions other modules offer on an album: gathered in order, and a
 * contributor that fails costs only its own button.
 */
final class AlbumActionRegistryTest extends TestCase
{
    public function testNothingRegisteredOffersNothing(): void
    {
        $this->assertSame([], (new AlbumActionRegistry())->collect(3));
    }

    public function testActionsAreGatheredAndAFailingProviderIsSkipped(): void
    {
        $registry = new AlbumActionRegistry();
        $registry->register($this->provider(static fn (int $id): array => [new AlbumAction('Partager', '/p/' . $id, 'bi-share')]));
        $registry->register($this->provider(static fn (int $id): array => throw new \RuntimeException('down')));
        $registry->register($this->provider(static fn (int $id): array => [new AlbumAction('Imprimer', '/i/' . $id, 'bi-printer')]));

        $actions = $registry->collect(3);

        $this->assertSame(['/p/3', '/i/3'], array_map(static fn (AlbumAction $a): string => $a->url, $actions));
    }

    /**
     * @param \Closure(int): list<AlbumAction> $actions
     */
    private function provider(\Closure $actions): AlbumActionProviderInterface
    {
        return new class ($actions) implements AlbumActionProviderInterface {
            /** @param \Closure(int): list<AlbumAction> $actions */
            public function __construct(private readonly \Closure $actions)
            {
            }

            public function actionsFor(int $albumId): array
            {
                return ($this->actions)($albumId);
            }
        };
    }
}
