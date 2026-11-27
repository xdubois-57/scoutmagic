<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Modules\News\Repository\FormResponseRepository;

/**
 * « 2 réponses au formulaire de « Souper » contiennent une quantité
 * invalide » (issue #1525): one point per article whose form still holds a
 * response stored with a negative or fractional quantity, from before the
 * server refused them.
 *
 * Such a response is wrong in three places at once — what it owes, the
 * places it holds, and what its ticket says at the door — and nothing else
 * on the site would say so: the responses page shows the raw « -8 » to a
 * reader who already knows to look for it.
 *
 * The responses are marked once, by the reprise
 * (ResponseService::flagResponsesWithInvalidQuantities()), so this reads
 * one aggregate query and decrypts nothing. A point disappears when its
 * responses are corrected — saving one clears its mark — or deleted.
 */
class NewsAttentionProvider implements AttentionPointProvider
{
    public function __construct(private FormResponseRepository $responses)
    {
    }

    public function sourceLabel(): string
    {
        return 'Actualités';
    }

    /**
     * @return AttentionPoint[]
     */
    public function collect(int $scoutYearId): array
    {
        $points = [];
        foreach ($this->responses->countInvalidQuantitiesByArticle() as $article) {
            $points[] = new AttentionPoint(
                title: ($article['count'] === 1
                    ? '1 réponse au formulaire de « ' . $article['title'] . ' » contient'
                    : $article['count'] . ' réponses au formulaire de « ' . $article['title'] . ' » contiennent')
                    . ' une quantité invalide',
                why: 'Une quantité négative ou non entière, enregistrée avant que le site ne la refuse, fausse le '
                    . 'montant dû, les places restantes et le billet présenté à l\'entrée. Corrigez ces réponses '
                    . '(la quantité est visible dans la liste des réponses) ou supprimez-les.',
                actionLabel: 'Voir les réponses',
                actionUrl: '/news/' . $article['article_id'] . '/form/responses',
                severity: AttentionPoint::SEVERITY_ATTENTION
            );
        }

        return $points;
    }
}
