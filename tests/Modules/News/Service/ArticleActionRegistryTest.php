<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Modules\News\Api\ArticleAction;
use Modules\News\Api\ArticleActionProviderInterface;
use Modules\News\Api\ArticleNote;
use Modules\News\Service\ArticleActionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The actions other modules offer on an article: gathered in order, and a
 * contributor that fails costs only its own button.
 */
final class ArticleActionRegistryTest extends TestCase
{
    public function testNothingRegisteredOffersNothing(): void
    {
        $this->assertSame([], (new ArticleActionRegistry())->collect(3));
    }

    public function testActionsAreGatheredAndAFailingProviderIsSkipped(): void
    {
        $registry = new ArticleActionRegistry();
        $registry->register($this->provider(static fn (int $id): array => [new ArticleAction('Partager', '/p/' . $id, 'bi-share')]));
        $registry->register($this->provider(static fn (int $id): array => throw new \RuntimeException('down')));
        $registry->register($this->provider(static fn (int $id): array => [new ArticleAction('Imprimer', '/i/' . $id, 'bi-printer')]));

        $actions = $registry->collect(3);

        $this->assertSame(['/p/3', '/i/3'], array_map(static fn (ArticleAction $a): string => $a->url, $actions));
    }

    public function testNothingRegisteredHasNothingToExplain(): void
    {
        $this->assertSame([], (new ArticleActionRegistry())->notes(3));
    }

    /**
     * The line a provider wants said when it is offering no button — and,
     * as with the buttons, one provider in trouble must not take the page
     * down with it.
     */
    public function testNotesAreGatheredAndAFailingProviderIsSkipped(): void
    {
        $registry = new ArticleActionRegistry();
        $registry->register($this->provider(static fn (int $id): array => [], new ArticleNote('Rien ici.')));
        $registry->register(new class implements ArticleActionProviderInterface {
            public function actionsFor(int $id): array
            {
                return [];
            }

            public function noteFor(int $id): ?ArticleNote
            {
                throw new \RuntimeException('down');
            }
        });
        $registry->register($this->provider(
            static fn (int $id): array => [],
            new ArticleNote('Et là non plus.', 'Configurer', '/config')
        ));

        $notes = $registry->notes(3);

        $this->assertSame(
            ['Rien ici.', 'Et là non plus.'],
            array_map(static fn (ArticleNote $n): string => $n->text, $notes)
        );
        $this->assertSame('/config', $notes[1]->linkUrl);
    }

    /** A provider with a button and nothing to explain contributes no line. */
    public function testAProviderWithNothingToExplainContributesNoLine(): void
    {
        $registry = new ArticleActionRegistry();
        $registry->register($this->provider(static fn (int $id): array => []));

        $this->assertSame([], $registry->notes(3));
    }

    /**
     * @param \Closure(int): list<ArticleAction> $actions
     */
    private function provider(\Closure $actions, ?ArticleNote $note = null): ArticleActionProviderInterface
    {
        return new class ($actions, $note) implements ArticleActionProviderInterface {
            /** @param \Closure(int): list<ArticleAction> $actions */
            public function __construct(private readonly \Closure $actions, private readonly ?ArticleNote $note)
            {
            }

            public function actionsFor(int $albumId): array
            {
                return ($this->actions)($albumId);
            }

            public function noteFor(int $albumId): ?ArticleNote
            {
                return $this->note;
            }
        };
    }
}
