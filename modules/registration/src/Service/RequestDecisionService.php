<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Journal\JournalService;
use Core\ScoutYear\ScoutYearResolver;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * The decision dialog of the « Demandes » list (issue #843, IT-04): the
 * « Décider » button of the « Action rapide » column opens it on a pending
 * request, with the recommendation and its explanation, the internal note
 * and Accepter / Refuser (with its reason) / Annuler.
 *
 * Two halves:
 *
 * - dialogContext() — what the list needs to offer the dialog on one scout
 *   year: whether a decision may be taken there at all (never on a past
 *   year, consultation only — R8), and, only while waitlists are managed,
 *   each pending request's recommendation (Service\RecommendationService,
 *   computed once for the whole year);
 * - decide() — what the dialog posts: the existing transition of
 *   Service\RequestStatusService (accept, or refuse with its reason), then
 *   the note. Nothing else: no e-mail leaves (the decision e-mails are
 *   still sent by hand from the fiche, until IT-05 and IT-09 plan them).
 */
class RequestDecisionService
{
    public const DECISION_ACCEPT = 'accept';
    public const DECISION_REFUSE = 'refuse';

    public function __construct(
        private RegistrationRequestRepository $requestRepository,
        private RequestStatusService $statusService,
        private RecommendationService $recommendationService,
        private RequestEmailService $emailService,
        private ScoutYearService $scoutYearService,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journalService
    ) {
    }

    /**
     * What the « Demandes » list of $scoutYearId needs for its dialogs.
     *
     * `decidable`: the year is the current one or a later one — a past year
     * shows no « Décider » button at all. `recommendations`: keyed by request
     * id, empty while waitlists are not managed (`show_recommendation`
     * false) — the dialog then shows no recommendation box at all, not an
     * empty one. The two `*_email_ready` flags say whether the decision
     * e-mails could be sent from the fiche, which is what the dialog's last
     * sentences are about.
     *
     * $anyPendingShown false — the list, filtered, shows no pending request,
     * hence no dialog — spares the whole year's queue, which decrypts every
     * pending request's birth date.
     *
     * @return array{
     *   decidable: bool,
     *   show_recommendation: bool,
     *   recommendations: array<int, Recommendation>,
     *   accepted_email_ready: bool,
     *   refused_email_ready: bool
     * }
     */
    public function dialogContext(int $scoutYearId, bool $anyPendingShown = true): array
    {
        $decidable = $this->isDecidableYear($scoutYearId);
        $showRecommendation = $decidable && $this->recommendationService->isAvailableForYear($scoutYearId);

        return [
            'decidable' => $decidable,
            'show_recommendation' => $showRecommendation,
            'recommendations' => $showRecommendation && $anyPendingShown
                ? $this->recommendationService->forYear($scoutYearId)
                : [],
            'accepted_email_ready' => $this->emailService->isAcceptedBodyReady(),
            'refused_email_ready' => $this->emailService->isRefusedBodyReady(),
        ];
    }

    /**
     * Whether a decision may be taken on a request of $scoutYearId: the
     * current public year or a later one. A past year is consultation only
     * (issue #843 R8), and an unknown one is never decidable.
     */
    public function isDecidableYear(int $scoutYearId): bool
    {
        $year = $this->scoutYearService->findById($scoutYearId);
        if ($year === null) {
            return false;
        }

        return $year['start_date'] >= $this->scoutYearResolver->getCurrentPublicYear()['start_date'];
    }

    /**
     * Whether the e-mail of $decision could be sent from the fiche — what the
     * confirmation after a decision says.
     */
    public function isEmailReady(string $decision): bool
    {
        return $decision === self::DECISION_REFUSE
            ? $this->emailService->isRefusedBodyReady()
            : $this->emailService->isAcceptedBodyReady();
    }

    /**
     * Takes the dialog's decision on $request: accept it, or refuse it with
     * $refusalReason (« Pas de place » / « Autre », RegistrationRequest::
     * REFUSAL_*), then store $note as its internal note.
     *
     * Everything that can refuse is checked before anything is written —
     * the decision, the year, the request still pending, and (inside
     * RequestStatusService::refuse()) the reason — and the status, the
     * note and their journal entries are written in one transaction, so a
     * refused or failed decision changes nothing, the note included.
     *
     * The note is written only when it differs from $noteAsShown, the note
     * the dialog displayed when the page was rendered (the stored one when
     * null): left untouched, it must not overwrite a note somebody saved on
     * the fiche since that page was loaded. Line endings do not count — a
     * browser posts a textarea with CRLF. An emptied note clears it,
     * exactly as the fiche's own field does.
     *
     * Journaled without any personal data: the request id, the decision,
     * and whether the note changed — never the note itself, which the fiche
     * never journals either. The status change journals itself, reason
     * code included (RequestStatusService).
     *
     * @throws RegistrationException when the decision cannot be taken
     */
    public function decide(
        RegistrationRequest $request,
        string $decision,
        string $note,
        ?string $refusalReason,
        ?string $noteAsShown = null
    ): void {
        if ($decision !== self::DECISION_ACCEPT && $decision !== self::DECISION_REFUSE) {
            throw new RegistrationException('Décision inconnue : choisissez « Accepter » ou « Refuser ».');
        }
        if (!$this->isDecidableYear($request->scoutYearId)) {
            throw new RegistrationException(
                "Cette demande appartient à une année passée, qui se consulte seulement : rien n'a été modifié."
            );
        }
        if ($request->status !== RegistrationRequest::STATUS_PENDING) {
            throw new RegistrationException(
                "Cette demande n'est plus en attente : rien n'a été modifié. Ouvrez sa fiche pour voir sa décision."
            );
        }

        $note = self::normalizeNote($note);
        $noteChanged = $note !== self::normalizeNote($noteAsShown ?? (string) $request->internalNotes);

        $write = function () use ($request, $decision, $note, $noteChanged, $refusalReason): void {
            if ($decision === self::DECISION_ACCEPT) {
                $this->statusService->accept($request);
            } else {
                $this->statusService->refuse($request, (string) $refusalReason);
            }

            if ($noteChanged) {
                $this->requestRepository->updateInternalNotes($request->id, $note !== '' ? $note : null);
            }

            $this->journalService->log(
                'registration',
                'registration_request_decided',
                'info',
                $decision === self::DECISION_ACCEPT
                    ? "Demande d'inscription acceptée depuis le dialogue de décision"
                    : "Demande d'inscription refusée depuis le dialogue de décision",
                ['request_id' => $request->id, 'decision' => $decision, 'note_changed' => $noteChanged]
            );
        };
        $this->requestRepository->transactional($write);
    }

    /**
     * A note as it is compared and stored: trimmed, with LF line endings.
     */
    private static function normalizeNote(string $note): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $note));
    }
}
