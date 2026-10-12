<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Journal\JournalService;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * The automatic acceptance of the « Oui » requests (chantier #843, D7.2
 * and IT-10) — one pass, run by Task\AutoAcceptRequestsHandler.
 *
 * **Per scout year still open to a decision** — the current one and the
 * later ones, never a past year (R8) — and **per slot** (branch × year in
 * branch), **in queue order, one request at a time**: the slot's queue is
 * recomputed (Service\RecommendationService) after every acceptance, and
 * the next request to take is chosen by Service\AutomaticAcceptancePolicy::
 * nextAcceptable() — which stops at the first request that is not « Oui »,
 * never takes the slot past its capacity except for « Toujours Oui », and
 * leaves aside the requests filed before the date of effect.
 *
 * **Under a lock per slot** (RegistrationRequestRepository::
 * acquireAutoAcceptLock(), a named GET_LOCK with timeout 0): a second pass
 * reaching a slot another pass is working on leaves it to that one. Each
 * acceptance then reads the request's status under a row lock, in the
 * transaction of the decision, so a decision a chief took since the queue
 * was computed always wins — the site accepts only a request still
 * pending. Two passes close together, or overlapping, therefore accept a
 * request once.
 *
 * **The same door as a decision by hand**: RequestDecisionService::apply(),
 * flagged automatic — the status change, the cancellation of any
 * obsolete e-mail, and the acceptance e-mail planned by the automatic
 * sending (IT-09), all in one transaction. An acceptance whose e-mail
 * could not be planned is rolled back and ends the pass: an accepted
 * family is never left without an answer. Anything else unexpected about
 * one request is rolled back and journaled too, and leaves that slot to a
 * person; the other slots go on.
 *
 * **Waitlists switched off elsewhere** (Configuration > Paramètres) switch
 * the automatic acceptance off at the next pass, as the box does at once.
 *
 * **The journal tells these acceptances apart**: the status change says
 * « acceptation automatique » (`automatic: true`), and a
 * `registration_request_auto_accepted` entry gives the slot, the rule and
 * the e-mail's due date — ids and codes only, nothing about the family.
 */
class AutomaticAcceptanceService
{
    /** Acceptances per pass: the pass re-arms at once after a full one. */
    public const MAX_ACCEPTANCES_PER_RUN = 50;

    private const ACCEPTED = 'accepted';
    private const NOT_PENDING = 'not_pending';
    private const FAILED = 'failed';
    private const UNPLANNED = 'unplanned';

    public function __construct(
        private AutomaticAcceptancePolicy $policy,
        private RecommendationService $recommendationService,
        private RequestDecisionService $decisionService,
        private RegistrationRequestRepository $requestRepository,
        private ScoutYearService $scoutYearService,
        private JournalService $journalService
    ) {
    }

    /**
     * One pass. `accepted`: the requests accepted; `busy`: the slots left
     * to another pass holding their lock; `more`: the pass stopped at
     * MAX_ACCEPTANCES_PER_RUN with possibly more to take.
     *
     * @return array{accepted: int, busy: int, more: bool}
     */
    public function run(): array
    {
        $outcome = ['accepted' => 0, 'busy' => 0, 'more' => false];
        $this->switchOffWithoutWaitlists();
        $from = $this->policy->effectiveFrom();
        if ($from === null || !$this->policy->isActive()) {
            return $outcome;
        }

        foreach ($this->openYearIds() as $yearId) {
            $queues = AutomaticAcceptancePolicy::queuesBySlot($this->recommendationService->forYear($yearId));
            foreach ($queues as $slotKey => $queue) {
                // Nothing to take here: no lock to hold either.
                if (AutomaticAcceptancePolicy::nextAcceptable($queue, $from) === null) {
                    continue;
                }
                $budget = self::MAX_ACCEPTANCES_PER_RUN - $outcome['accepted'];
                $result = $this->processSlot($yearId, $slotKey, $from, $budget);
                $outcome['accepted'] += $result['accepted'];
                $outcome['busy'] += $result['busy'] ? 1 : 0;
                if ($result['stop'] || $outcome['accepted'] >= self::MAX_ACCEPTANCES_PER_RUN) {
                    $outcome['more'] = !$result['stop'];
                    break 2;
                }
            }
        }

        if ($outcome['accepted'] > 0) {
            $this->journalService->log(
                'registration',
                'registration_requests_auto_accept_run',
                'info',
                'Acceptation automatique des demandes « Oui »',
                $outcome
            );
        }

        return $outcome;
    }

    /**
     * The slot $slotKey (« age branch id:year in branch ») of $yearId, under
     * its lock: take its next acceptable request, recompute, again, until
     * nothing is left to take or $budget is spent. `stop` ends the whole
     * pass — an acceptance whose e-mail could not be planned.
     *
     * @return array{accepted: int, busy: bool, stop: bool}
     */
    private function processSlot(int $yearId, string $slotKey, \DateTimeImmutable $from, int $budget): array
    {
        [$ageBranchId, $yearInBranch] = array_map('intval', explode(':', $slotKey));
        if (!$this->requestRepository->acquireAutoAcceptLock($yearId, $ageBranchId, $yearInBranch)) {
            return ['accepted' => 0, 'busy' => true, 'stop' => false];
        }

        $accepted = 0;
        $tried = [];
        try {
            while ($accepted < $budget) {
                // Recomputed under the lock, after every acceptance: the
                // queue moves with each decision, by hand or not.
                $queues = AutomaticAcceptancePolicy::queuesBySlot($this->recommendationService->forYear($yearId));
                $next = AutomaticAcceptancePolicy::nextAcceptable($queues[$slotKey] ?? [], $from);
                // A request already tried is never tried twice in a pass.
                if ($next === null || isset($tried[$next->requestId])) {
                    break;
                }
                $tried[$next->requestId] = true;
                $result = $this->acceptOne($next, $yearId);
                if ($result === self::UNPLANNED) {
                    return ['accepted' => $accepted, 'busy' => false, 'stop' => true];
                }
                // FAILED: this request needs a person — the slot waits for
                // it, the other slots go on.
                if ($result === self::FAILED) {
                    break;
                }
                // NOT_PENDING: decided by hand meanwhile — the recomputed
                // queue no longer holds it.
                if ($result === self::ACCEPTED) {
                    $accepted++;
                }
            }
        } finally {
            $this->requestRepository->releaseAutoAcceptLock($yearId, $ageBranchId, $yearInBranch);
        }

        return ['accepted' => $accepted, 'busy' => false, 'stop' => false];
    }

    /**
     * Accepts the request $entry recommends, through the decision service,
     * if it is still pending once its row is locked.
     *
     * Anything unexpected on the way — a request that no longer decrypts,
     * a lock wait that times out — rolls that acceptance back and is
     * journaled with the error's class only; the pass goes on with the
     * other slots (FAILED), as one e-mail never stops the automatic
     * sending's batch (IT-09).
     *
     * @return string self::ACCEPTED, NOT_PENDING, FAILED or UNPLANNED
     */
    private function acceptOne(Recommendation $entry, int $yearId): string
    {
        try {
            return $this->requestRepository->transactional(function () use ($entry, $yearId): string {
                if ($this->requestRepository->lockStatusForDecision($entry->requestId)
                    !== RegistrationRequest::STATUS_PENDING
                ) {
                    return self::NOT_PENDING;
                }
                $request = $this->requestRepository->findById($entry->requestId);
                if ($request === null) {
                    return self::NOT_PENDING;
                }

                $dueAt = $this->decisionService->apply($request, RequestDecisionService::DECISION_ACCEPT, null, true);
                if ($dueAt === null) {
                    // Rolls the acceptance back: never a family accepted
                    // without its e-mail on its way.
                    throw new AutomaticAcceptanceUnplanned();
                }

                $this->journalService->log(
                    'registration',
                    'registration_request_auto_accepted',
                    'info',
                    "Demande d'inscription acceptée automatiquement (recommandation « Oui »)",
                    [
                        'request_id' => $entry->requestId,
                        'scout_year_id' => $yearId,
                        'age_branch_id' => $entry->ageBranchId,
                        'year_in_branch' => $entry->yearInBranch,
                        'rule' => $entry->kind,
                        'position' => $entry->position,
                        'decision_email_due_at' => $dueAt->format('Y-m-d H:i:s'),
                    ]
                );

                return self::ACCEPTED;
            });
        } catch (AutomaticAcceptanceUnplanned) {
            $this->journalService->log(
                'registration',
                'registration_request_auto_accept_unplanned',
                'warning',
                "Acceptation automatique interrompue : l'e-mail d'acceptation n'a pas pu être planifié, "
                    . "la demande reste en attente",
                ['request_id' => $entry->requestId]
            );

            return self::UNPLANNED;
        } catch (\Throwable $e) {
            $this->journalService->log(
                'registration',
                'registration_request_auto_accept_failed',
                'warning',
                "Acceptation automatique impossible pour une demande, qui reste en attente : à décider à la main",
                ['request_id' => $entry->requestId, 'error' => $e::class]
            );

            return self::FAILED;
        }
    }

    /**
     * Waitlists switched off where the « Décisions automatiques » box does
     * not see it — Configuration > Paramètres —: the automatic acceptance
     * is switched off as the box would have done, so that switching
     * waitlists back on never brings it back unnoticed.
     */
    private function switchOffWithoutWaitlists(): void
    {
        if ($this->policy->missingPrerequisite() !== AutomaticAcceptancePolicy::MISSING_WAITLIST
            || !$this->policy->switchOff()
        ) {
            return;
        }
        $this->journalService->log(
            'registration',
            'registration_auto_accept_switched_off',
            'warning',
            "Acceptation automatique des demandes « Oui » désactivée : la gestion des listes d'attente est coupée",
            []
        );
    }

    /**
     * The scout years still open to a decision — the dialog's own rule
     * (RequestDecisionService::isDecidableYear()): the current public year
     * and every later one, a past year being consultation only (R8).
     *
     * @return array<int, int>
     */
    private function openYearIds(): array
    {
        $ids = [];
        // Oldest first (getAll()'s order): the current year, then the next.
        foreach ($this->scoutYearService->getAll() as $year) {
            if ($this->decisionService->isDecidableYear($year['id'])) {
                $ids[] = $year['id'];
            }
        }

        return $ids;
    }
}
