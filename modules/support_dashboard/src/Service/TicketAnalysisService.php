<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Journal\JournalService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;
use Modules\SupportDashboard\Repository\SupportTicketAnalysisRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;

/**
 * Groups the tickets by symptom and surfaces what keeps coming back
 * (roadmap IT-28).
 *
 * **Optional, nullable, silently degrading** (ARCHITECTURE.md §7.5).
 * Without `llm_connector`, or with no active provider, `isAvailable()` is
 * false and the page renders exactly as it did before — no block, no
 * error, no mention of a feature this installation does not have.
 *
 * **A maintainer asks; the page never sends on its own.** The roadmap's
 * own pitfall, and the reason behind it is not performance: the
 * descriptions are what people wrote about their own installations, and
 * sending them to an external provider makes that provider a
 * **sub-processor** (AGENTS.md, RGPD) — which holds whether the call is
 * made by this module or, as here, through another module's API. Making
 * the transmission the consequence of somebody pressing a button rather
 * than of anybody opening a page is the difference between an operation
 * the maintainer performs and one that happens to them. The result is
 * persisted for the same reason.
 *
 * **What is sent, and what is not.** The category, the description and the
 * resolution note — the three things a symptom is made of. Never the
 * contact address, never the instance URL, never the installation id:
 * grouping bug reports by symptom needs none of them, and a provider that
 * receives « ce site-là a ce problème-là » has been told something the
 * question never required.
 */
class TicketAnalysisService
{
    /**
     * Enough tickets for a pattern, few enough to stay inside a prompt.
     * The most recent ones, because a recurrence that stopped six months
     * ago is history rather than a finding.
     */
    public const MAX_TICKETS = 60;

    /** A description is a paragraph; past this it is a log paste. */
    private const MAX_DESCRIPTION_CHARS = 1500;

    public function __construct(
        private SupportTicketRepository $tickets,
        private SupportTicketAnalysisRepository $analyses,
        private JournalService $journal,
        private ?LlmConnectorInterface $llmConnector = null
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->llmConnector !== null && $this->llmConnector->isAvailable();
    }

    /**
     * The stored result of the last run, or null when none was asked for.
     *
     * @return array{requested_at: string, ticket_count: int, result: string}|null
     */
    public function latest(): ?array
    {
        return $this->analyses->latest();
    }

    /**
     * How many tickets a run would send, so the button can say it before
     * anybody starts a transmission blind.
     */
    public function pendingCount(): int
    {
        return $this->isAvailable() ? count($this->analysable()) : 0;
    }

    /**
     * Run one analysis and store it.
     *
     * **It says which of the four non-results it was**
     * (`Service\TicketAnalysisOutcome`). It used to answer a bare
     * `false`, and the screen turned every one of them into « le
     * fournisseur n'a rien renvoyé d'exploitable » — including the case
     * where nothing was sent to any provider because there was nothing to
     * analyse. A maintainer reading that had been told something untrue
     * about a third party, and had no way to find out.
     */
    public function run(\DateTimeImmutable $now): TicketAnalysisOutcome
    {
        if (!$this->isAvailable()) {
            return TicketAnalysisOutcome::UNAVAILABLE;
        }

        $tickets = $this->analysable();
        if ($tickets === []) {
            // Nothing left this installation, and the journal says so:
            // « j'ai demandé une analyse et rien ne s'est passé » had
            // three possible causes and only one of them was written down.
            $this->journalOutcome(TicketAnalysisOutcome::NO_TICKETS, 0);

            return TicketAnalysisOutcome::NO_TICKETS;
        }

        \assert($this->llmConnector !== null);

        try {
            $response = $this->llmConnector->complete(new LlmRequest(
                tier: LlmTier::CHEAP,
                prompt: $this->promptFor($tickets),
                systemPrompt: "Tu lis des tickets de support d'un logiciel de gestion d'unité scoute, "
                    . "envoyés par des installations différentes. Regroupe-les par symptôme et fais "
                    . "ressortir ce qui revient. Écris en français, en Markdown simple : un titre de "
                    . "niveau 3 par groupe, puis le nombre de tickets concernés et ce qu'ils ont en "
                    . "commun. Termine par les récurrences que tu juges les plus utiles à traiter. "
                    . "N'invente aucun ticket, ne cite aucune adresse et ne conclus rien qui ne "
                    . "s'appuie pas sur les textes fournis. Un ticket isolé n'est pas une récurrence : "
                    . "dis-le plutôt que de forcer un regroupement.",
                maxTokens: 4000,
            ));
        } catch (LlmException) {
            // An unavailable model costs the maintainer nothing but the
            // absence of a summary. The exception's message is not kept:
            // it can quote the request, and the request carries what
            // people wrote.
            $this->journalOutcome(TicketAnalysisOutcome::PROVIDER_FAILED, count($tickets));

            return TicketAnalysisOutcome::PROVIDER_FAILED;
        }

        $result = trim($response->content);
        if ($result === '') {
            // The transmission DID happen — the descriptions left this
            // installation and the answer was empty. That is a different
            // fact from « rien n'a été envoyé », and the only one of the
            // two that the old message described correctly.
            $this->journalOutcome(TicketAnalysisOutcome::EMPTY_ANSWER, count($tickets));

            return TicketAnalysisOutcome::EMPTY_ANSWER;
        }

        $this->analyses->store($result, count($tickets), $now);

        // Counts, never a word of a description: the entry says a
        // transmission happened and how big it was, which is what a
        // journal is for here.
        $this->journalOutcome(TicketAnalysisOutcome::STORED, count($tickets));

        return TicketAnalysisOutcome::STORED;
    }

    /**
     * One entry per outcome, in the words of the outcome itself.
     *
     * Counts, never a word of a description (§7.9): a journal entry says
     * that a transmission happened and how big it was, and travels in the
     * diagnostic archive where a ticket's text has no business being.
     */
    private function journalOutcome(TicketAnalysisOutcome $outcome, int $ticketCount): void
    {
        $type = $outcome->journalEventType();
        if ($type === null) {
            return;
        }

        $this->journal->log(
            'support_dashboard',
            $type,
            $outcome->isSuccess() ? 'info' : 'warning',
            $outcome->message(),
            ['tickets' => $ticketCount, 'outcome' => $outcome->value]
        );
    }

    /**
     * The tickets a run would read: the most recent ones that actually
     * carry a description.
     *
     * @return list<array<string, mixed>>
     */
    private function analysable(): array
    {
        $tickets = [];
        foreach ($this->tickets->findAllWithInstallation() as $ticket) {
            if (trim((string) $ticket['description']) === '') {
                continue;
            }
            $tickets[] = $ticket;
            if (count($tickets) >= self::MAX_TICKETS) {
                break;
            }
        }

        return $tickets;
    }

    /**
     * @param list<array<string, mixed>> $tickets
     */
    private function promptFor(array $tickets): string
    {
        $lines = [];
        foreach ($tickets as $index => $ticket) {
            // Numbered locally, never by reference or id: the provider is
            // asked to read texts, not to be able to name a ticket.
            $lines[] = '## Ticket ' . ($index + 1);
            $lines[] = 'Catégorie : ' . ($ticket['category']?->label() ?? 'non précisée');
            $lines[] = 'Version du site : ' . ((string) ($ticket['site_version'] ?? '') ?: 'inconnue');
            $lines[] = 'Description : ' . self::clamp((string) $ticket['description']);

            $note = (string) ($ticket['resolution_note'] ?? '');
            if (trim($note) !== '') {
                $lines[] = 'Résolution : ' . self::clamp($note);
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private static function clamp(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > self::MAX_DESCRIPTION_CHARS
            ? mb_substr($text, 0, self::MAX_DESCRIPTION_CHARS) . ' […]'
            : $text;
    }
}
