<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Finance;

use Modules\Finance\Api\ReceivableSourceDescriberInterface;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;

/**
 * What « Paiements attendus » calls this module's expectations.
 *
 * A paid form's group used to be headed « Formulaire #12 » — the form's
 * primary key — above one row per family that answered it. The form has no
 * name of its own: it belongs to an article, and the article's title is
 * what everybody calls it (« Inscription au week-end d'unité »), so that
 * is what this returns.
 *
 * Two lookups per group rendered, never per row.
 */
class NewsReceivableDescriber implements ReceivableSourceDescriberInterface
{
    /** The string Service\ResponseService passes to createReceivable(). */
    public const SOURCE_MODULE = 'news';

    public function __construct(
        private FormRepository $forms,
        private ArticleRepository $articles
    ) {
    }

    public function sourceModule(): string
    {
        return self::SOURCE_MODULE;
    }

    public function sourceLabel(): string
    {
        return 'Formulaires';
    }

    public function describeInstance(int $sourceReferenceId): ?string
    {
        $form = $this->forms->findById($sourceReferenceId);
        if ($form === null) {
            return null;
        }

        // The form's own name is its article's title; a form whose article
        // is gone has none, and finance falls back to the id.
        return $this->articles->findById($form->newsArticleId)?->title;
    }
}
