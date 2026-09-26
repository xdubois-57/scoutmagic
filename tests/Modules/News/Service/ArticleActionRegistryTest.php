<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Modules\News\Api\ArticleAction;
use Modules\News\Api\ArticleActionProviderInterface;
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

    /**
     * @param \Closure(int): list<ArticleAction> $actions
     */
    private function provider(\Closure $actions): ArticleActionProviderInterface
    {
        return new class ($actions) implements ArticleActionProviderInterface {
            /** @param \Closure(int): list<ArticleAction> $actions */
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
