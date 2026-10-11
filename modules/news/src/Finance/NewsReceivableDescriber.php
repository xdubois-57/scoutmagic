<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Finance;

use Core\Security\Role;
use Modules\Finance\Api\ReceivableDestination;
use Modules\Finance\Api\ReceivableSourceDescriberInterface;
use Modules\Finance\Api\ReceivableSourceDestinationInterface;
use Modules\Finance\Api\ReceivableViewer;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;

/**
 * What « Contrôle des créances » calls this module's expectations, and
 * where they are managed.
 *
 * A paid form's group used to be headed « Formulaire #12 » — the form's
 * primary key — above one row per family that answered it. The form has no
 * name of its own: it belongs to an article, and the article's title is
 * what everybody calls it (« Inscription au week-end d'unité »), so that
 * is what this returns.
 *
 * Two lookups per group rendered, never per row.
 *
 * Where: the form's answers, for whoever may read them (issue #836) — the
 * route's floor and the form's own `response_role_min`, the two tests
 * Controller\FormController::responses() applies.
 */
class NewsReceivableDescriber implements ReceivableSourceDescriberInterface, ReceivableSourceDestinationInterface
{
    /** `/news/{id}/form/responses`'s role_min in module.json. */
    private const RESPONSES_ROUTE_ROLE_MIN = Role::INTENDANT;

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

    public function destinationFor(int $sourceReferenceId, ReceivableViewer $viewer): ?ReceivableDestination
    {
        $form = $this->forms->findById($sourceReferenceId);
        if ($form === null || $this->articles->findById($form->newsArticleId) === null) {
            return null;
        }

        if (!$viewer->role->hasAccess(self::RESPONSES_ROUTE_ROLE_MIN)
            || !$viewer->role->hasAccess(Role::fromString($form->responseRoleMin))
        ) {
            return null;
        }

        return new ReceivableDestination(
            'Ouvrir les réponses du formulaire',
            '/news/' . $form->newsArticleId . '/form/responses'
        );
    }
}
