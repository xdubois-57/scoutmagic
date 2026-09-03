<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

/**
 * What became of one cross-ticket analysis.
 *
 * **`run()` used to answer `true` or `false`, and `false` meant four
 * different things.** The screen said the same sentence for all of them —
 * « le fournisseur n'a rien renvoyé d'exploitable » — including for the
 * case where no provider was ever contacted because there was nothing to
 * analyse. That is not a vague message, it is a false one: it blames a
 * third party for a request nobody made.
 *
 * It was reported from the other end. The message surfaced on the
 * Maintenance page — a flash lives until a page renders it, and a request
 * whose answer nobody waited for hands its message to whatever page comes
 * next — where « L'analyse n'a pas abouti » reads as the maintenance
 * update having failed. Hence the second rule here: **every message names
 * what it is about**, so a sentence that arrives on the wrong page is
 * merely misplaced rather than misleading.
 */
enum TicketAnalysisOutcome: string
{
    /** A result came back and was stored. */
    case STORED = 'stored';

    /** No `llm_connector`, or no active provider. Nothing was sent. */
    case UNAVAILABLE = 'unavailable';

    /**
     * No ticket carries a description worth reading. **Nothing left this
     * installation** — the distinction the old message erased.
     */
    case NO_TICKETS = 'no_tickets';

    /** The call was made and failed. */
    case PROVIDER_FAILED = 'provider_failed';

    /** The provider answered, and the answer was empty. */
    case EMPTY_ANSWER = 'empty_answer';

    public function isSuccess(): bool
    {
        return $this === self::STORED;
    }

    /**
     * The sentence a maintainer reads, naming its own subject.
     */
    public function message(): string
    {
        return match ($this) {
            self::STORED => 'Analyse transversale des tickets de support mise à jour.',
            self::UNAVAILABLE => "L'analyse transversale des tickets de support n'est pas disponible "
                . 'sur cette installation.',
            self::NO_TICKETS => "Aucun des tickets de support n'est analysable : rien n'a été "
                . 'envoyé au fournisseur IA.',
            self::PROVIDER_FAILED => "L'analyse transversale des tickets de support n'a pas abouti : "
                . "l'appel au fournisseur IA a échoué.",
            self::EMPTY_ANSWER => "L'analyse transversale des tickets de support n'a pas abouti : "
                . "le fournisseur IA n'a rien renvoyé d'exploitable.",
        };
    }

    /**
     * The flash type. « Rien à analyser » is not a failure — it is the
     * ordinary state of a receiver with no open complaints, and colouring
     * it red teaches people to ignore red.
     */
    public function flashType(): string
    {
        return match ($this) {
            self::STORED => 'success',
            self::NO_TICKETS => 'warning',
            default => 'error',
        };
    }

    /**
     * The journal event this outcome writes, or null for the one that
     * already has its own entry.
     *
     * Only `PROVIDER_FAILED` used to be written down; the other two
     * refusals answered with the same silence, which is how « j'ai
     * demandé une analyse et rien ne s'est passé » had three possible
     * causes and no way to tell them apart.
     */
    public function journalEventType(): ?string
    {
        return match ($this) {
            self::STORED => 'support_ticket_analysis_run',
            self::UNAVAILABLE => null,
            self::NO_TICKETS => 'support_ticket_analysis_skipped',
            self::PROVIDER_FAILED => 'support_ticket_analysis_failed',
            self::EMPTY_ANSWER => 'support_ticket_analysis_empty',
        };
    }
}
