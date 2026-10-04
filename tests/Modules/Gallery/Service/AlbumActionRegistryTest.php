<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Api\AlbumAction;
use Modules\Gallery\Api\AlbumActionProviderInterface;
use Modules\Gallery\Api\AlbumNote;
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

    public function testNothingRegisteredHasNothingToExplain(): void
    {
        $this->assertSame([], (new AlbumActionRegistry())->notes(3));
    }

    /**
     * The line a provider wants said when it is offering no button — and,
     * as with the buttons, one provider in trouble must not take the page
     * down with it.
     */
    public function testNotesAreGatheredAndAFailingProviderIsSkipped(): void
    {
        $registry = new AlbumActionRegistry();
        $registry->register($this->provider(static fn (int $id): array => [], new AlbumNote('Rien ici.')));
        $registry->register(new class implements AlbumActionProviderInterface {
            public function actionsFor(int $id): array
            {
                return [];
            }

            public function noteFor(int $id): ?AlbumNote
            {
                throw new \RuntimeException('down');
            }
        });
        $registry->register($this->provider(
            static fn (int $id): array => [],
            new AlbumNote('Et là non plus.', 'Configurer', '/config')
        ));

        $notes = $registry->notes(3);

        $this->assertSame(
            ['Rien ici.', 'Et là non plus.'],
            array_map(static fn (AlbumNote $n): string => $n->text, $notes)
        );
        $this->assertSame('/config', $notes[1]->linkUrl);
    }

    /** A provider with a button and nothing to explain contributes no line. */
    public function testAProviderWithNothingToExplainContributesNoLine(): void
    {
        $registry = new AlbumActionRegistry();
        $registry->register($this->provider(static fn (int $id): array => []));

        $this->assertSame([], $registry->notes(3));
    }

    /**
     * @param \Closure(int): list<AlbumAction> $actions
     */
    private function provider(\Closure $actions, ?AlbumNote $note = null): AlbumActionProviderInterface
    {
        return new class ($actions, $note) implements AlbumActionProviderInterface {
            /** @param \Closure(int): list<AlbumAction> $actions */
            public function __construct(private readonly \Closure $actions, private readonly ?AlbumNote $note)
            {
            }

            public function actionsFor(int $albumId): array
            {
                return ($this->actions)($albumId);
            }

            public function noteFor(int $albumId): ?AlbumNote
            {
                return $this->note;
            }
        };
    }
}
