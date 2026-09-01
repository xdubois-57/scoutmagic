<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\Journal\JournalService;
use Modules\InboundMail\Api\AnalysisResult;

/**
 * What the analysis pipeline leaves behind in the event journal.
 *
 * **Why it exists.** Until now the pipeline was silent from end to end: a
 * message arrived, every module was asked what it made of it, every module
 * said nothing, and there was no trace anywhere that the question had even
 * been put. A unit whose `camps@` box produced no stay could not tell a
 * mailbox that never synchronised from a module that was never asked, from
 * a module that was asked and declined — three completely different
 * problems with one identical symptom. Worse, a consumer that *threw* was
 * swallowed on purpose, so the one case that is unambiguously a defect was
 * the least visible of all.
 *
 * **What it may say, and what it may never say.** Journal entries are read
 * by a superadmin and travel in a support archive, so nothing here names a
 * sender, a subject, a recipient or a file (§7.9). What is left is enough:
 * an internal message id, a mailbox id and its name (organisational, and
 * already in the journal for every mailbox event), the consumer ids asked,
 * and what each one answered. A message id is the handle a superadmin can
 * then open in « Courrier » if they are entitled to.
 *
 * **Bounded by the mail itself.** One entry per stored message, and one
 * summary per deferred run that actually examined something. A unit
 * receives a handful of messages a day; a task that examines none writes
 * nothing at all, which is what keeps an hourly pass from filling the
 * journal the way the scheduler once did.
 */
class AnalysisJournal
{
    public const CATEGORY = 'inbound_mail';

    /** The arrival pass, inside the synchronisation. */
    public const PASS_ARRIVAL = 'arrivee';

    /** The deferred content pass, which may read an attachment's bytes. */
    public const PASS_STORED = 'differe';

    /**
     * How much of a failure's own message is kept. Long enough to name a
     * missing column or a refused connection, short enough that a stack
     * trace's first quoted line cannot drag a subject in with it.
     */
    public const MAX_REASON_CHARS = 300;

    public function __construct(private ?JournalService $journal = null)
    {
    }

    /**
     * What every module made of one message.
     *
     * Written for a message that produced nothing just as much as for one
     * that produced an association — « personne ne l'a reconnu » is the
     * answer a unit most often needs and the one that was hardest to get.
     *
     * @param string[] $consumerIds the consumers this box was opened to
     * @param array<string, AnalysisResult> $results keyed by consumer id
     */
    public function analysed(
        int $messageId,
        int $mailboxId,
        string $mailboxName,
        array $consumerIds,
        array $results,
        string $pass
    ): void {
        $this->journal?->log(
            self::CATEGORY,
            'inbound_message_analysed',
            'info',
            sprintf(
                'Message #%d (boîte « %s ») : %s',
                $messageId,
                $mailboxName,
                self::summarise($consumerIds, $results)
            ),
            [
                'message_id' => $messageId,
                'mailbox_id' => $mailboxId,
                'pass' => $pass,
                'asked' => $consumerIds,
                'outcome' => self::outcomes($consumerIds, $results),
            ]
        );
    }

    /**
     * A consumer that threw while analysing.
     *
     * `error`, and never anything softer: the pipeline goes on without it,
     * so nothing else in the application will ever complain, and a module
     * silently declining to look at a unit's mail for weeks is exactly the
     * failure this journal exists to end.
     */
    public function failed(string $consumerId, int $mailboxId, string $pass, \Throwable $error): void
    {
        $this->journal?->log(
            self::CATEGORY,
            'inbound_analysis_failed',
            'error',
            sprintf(
                'Le module « %s » a échoué en analysant un message : %s',
                $consumerId,
                self::redact($error->getMessage())
            ),
            [
                'consumer' => $consumerId,
                'mailbox_id' => $mailboxId,
                'pass' => $pass,
                'error_class' => $error::class,
            ]
        );
    }

    /**
     * The deferred run's own line, written only when it examined something.
     *
     * An hourly task that finds nothing to do says nothing: the interesting
     * fact is « il reste du courrier à analyser » or « rien n'a été
     * reconnu », not « la tâche a tourné », which `scheduler_task_done`
     * already records.
     */
    public function storedPassDone(int $examined, int $linked, int $proposed): void
    {
        if ($examined === 0) {
            return;
        }

        $this->journal?->log(
            self::CATEGORY,
            'inbound_stored_analysis_done',
            'info',
            sprintf(
                'Analyse différée : %d message(s) examiné(s), %d rattachement(s), %d proposition(s).',
                $examined,
                $linked,
                $proposed
            ),
            ['examined' => $examined, 'linked' => $linked, 'proposed' => $proposed]
        );
    }

    /**
     * Nothing to analyse at all, because no module is allowed to.
     *
     * Distinct from « tout le monde a dit non » on purpose: a box no module
     * was opened to is a configuration answer, and telling a superadmin to
     * go and look at the scope screen is a different instruction from
     * telling them their mail does not match anything.
     */
    public function noConsumerAllowed(int $mailboxId, string $mailboxName): void
    {
        $this->journal?->log(
            self::CATEGORY,
            'inbound_no_module_analyses',
            'warning',
            sprintf(
                'Boîte « %s » : aucun module n\'est autorisé à analyser son courrier. '
                . 'Le courrier est conservé, rien ne le classe.',
                $mailboxName
            ),
            ['mailbox_id' => $mailboxId]
        );
    }

    /**
     * One consumer's answer, as a word: what a reader scans a journal for.
     *
     * @param string[] $consumerIds
     * @param array<string, AnalysisResult> $results
     * @return array<string, string>
     */
    private static function outcomes(array $consumerIds, array $results): array
    {
        $outcomes = [];
        foreach ($consumerIds as $consumerId) {
            $result = $results[$consumerId] ?? null;
            $outcomes[$consumerId] = match (true) {
                $result === null || $result->isEmpty() => 'rien',
                $result->links !== [] && $result->candidates !== [] => 'rattache_et_propose',
                $result->links !== [] => 'rattache',
                default => 'propose',
            };
        }

        return $outcomes;
    }

    /**
     * @param string[] $consumerIds
     * @param array<string, AnalysisResult> $results
     */
    private static function summarise(array $consumerIds, array $results): string
    {
        if ($consumerIds === []) {
            return 'aucun module n\'analyse cette boîte.';
        }

        $said = [];
        foreach (self::outcomes($consumerIds, $results) as $consumerId => $outcome) {
            $said[] = $consumerId . ' : ' . match ($outcome) {
                'rattache' => 'rattaché',
                'propose' => 'proposition',
                'rattache_et_propose' => 'rattaché et proposition',
                default => 'rien',
            };
        }

        return implode(' · ', $said);
    }

    /**
     * An exception's own words, bounded, and with anything that looks like
     * an address taken out — a driver echoing a failing statement can quote
     * a sender back at us, and the journal is not where that belongs.
     */
    public static function redact(string $reason): string
    {
        $clean = (string) preg_replace(
            '/[^\s<>"\']+@[^\s<>"\']+/',
            '[adresse]',
            trim($reason)
        );

        return mb_strlen($clean) > self::MAX_REASON_CHARS
            ? mb_substr($clean, 0, self::MAX_REASON_CHARS) . '…'
            : $clean;
    }
}
