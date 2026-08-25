<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

/**
 * What happened when a place's summary was (not) written.
 *
 * This used to be a `bool`, and the false covered five different
 * situations: no connector, no model for the tier this module asks for, a
 * place with nothing to sum up, a provider that refused, and a model that
 * answered nothing. The page could not tell them apart, so it named all
 * five at once — "il n'y a pas assez à raconter, ou le connecteur IA
 * n'est pas disponible" — and a chief who had just written a comment and
 * given four stars was told their material was too thin when the real
 * answer was that no model was assigned to the `cheap` tier.
 *
 * A wrong diagnosis is worse than no diagnosis: it sends the one person
 * who could fix it (write more) off doing the one thing that cannot help.
 * So the outcome is named, and the sentence belongs to the outcome.
 */
enum SummaryOutcome
{
    /** Written and stored. */
    case Written;

    /** Nothing to sum up: no stay, or stays with no review and no price. */
    case NothingToSummarise;

    /**
     * No connector at all, or no model assigned to the tier this module
     * asks for. Nothing was sent anywhere, which is also why nothing
     * about it is in the journal.
     */
    case Unavailable;

    /** The provider was called and refused (see the journal for its words). */
    case ModelRefused;

    /** The provider answered, and its answer was empty. */
    case EmptyAnswer;

    public function wasWritten(): bool
    {
        return $this === self::Written;
    }

    /**
     * The sentence a chief reads. Each one says what happened AND what to
     * do about it, because the three failures are fixed by three
     * different people: the one writing reviews, the administrator, and
     * nobody at all (try again later).
     */
    public function message(): string
    {
        return match ($this) {
            self::Written => 'Résumé régénéré.',
            self::NothingToSummarise => 'Rien à résumer pour l\'instant : ce lieu n\'a encore ni avis, '
                . 'ni prix à comparer. Laissez un avis sur un séjour et le résumé aura de quoi s\'écrire.',
            // Names the connector's OWN vocabulary — « Modèle économique »
            // is the label on Configuration > Intelligence artificielle,
            // so the person told to go and look finds the field they were
            // sent to.
            self::Unavailable => 'Le résumé n\'a pas pu être écrit : le connecteur IA n\'a pas de '
                . '« modèle économique » configuré pour cette tâche. C\'est un réglage d\'administrateur '
                . '(Configuration > Intelligence artificielle) — vos avis n\'y sont pour rien.',
            self::ModelRefused => 'Le résumé n\'a pas pu être écrit : le fournisseur IA a refusé la demande. '
                . 'Le détail est dans le journal, et vos avis n\'y sont pour rien.',
            self::EmptyAnswer => 'Le fournisseur IA n\'a rien renvoyé. Réessayez dans un moment — '
                . 'le résumé précédent, s\'il y en avait un, est intact.',
        };
    }
}
