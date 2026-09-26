<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Modules\News\Api\ArticleAction;
use Modules\News\Api\ArticleActionProviderInterface;

/**
 * The actions other modules contribute to an article's editor
 * (ARCHITECTURE.md §7.6): built in the news module's block of the
 * composition root, appended to by the contributing modules' blocks that
 * run after it. A provider that throws is skipped.
 */
final class ArticleActionRegistry
{
    /** @var list<ArticleActionProviderInterface> */
    private array $providers = [];

    public function register(ArticleActionProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * @return list<ArticleAction>
     */
    public function collect(int $articleId): array
    {
        $actions = [];
        foreach ($this->providers as $provider) {
            try {
                foreach ($provider->actionsFor($articleId) as $action) {
                    $actions[] = $action;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $actions;
    }
}
