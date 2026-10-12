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
 * - decide() — what the dialog posts: apply(), then the note.
 *
 * **apply() is the one way a decision is taken** (IT-08): the dialog, the
 * fiche's « Accepter » / « Refuser » and the bulk « Tout refuser » all go
 * through it, so whatever a decision must also do happens on every path —
 * a change of decision cancels any e-mail planned for the old one, inside
 * the same transaction (RegistrationRequestRepository::updateStatus()),
 * and the automatic sending of IT-09 will plan the new one here. Today no
 * e-mail leaves: the decision e-mails are sent by hand from the fiche.
 *
 * confirmation() writes what every one of those paths says afterwards,
 * which answers « un e-mail va-t-il partir ? » (D8).
 */
class RequestDecisionService
{
    public const DECISION_ACCEPT = 'accept';
    public const DECISION_REFUSE = 'refuse';

    /** Where the confirmation is read: the list (the dialog), the fiche, or the bulk refusal. */
    public const FROM_LIST = 'list';
    public const FROM_REQUEST_PAGE = 'request_page';
    public const FROM_BULK = 'bulk';

    /**
     * What happens to a decision's e-mail once it is taken — the answer
     * confirmation() gives. Two cases today; IT-09 adds the automatic one
     * (planned for « le … à … ») to emailOutcome() and to the sentence.
     */
    private const EMAIL_BY_HAND = 'by_hand';
    private const EMAIL_TEMPLATE_MISSING = 'template_missing';

    private const MESSAGE_UNKNOWN_DECISION = 'Décision inconnue : choisissez « Accepter » ou « Refuser ».';

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
            throw new RegistrationException(self::MESSAGE_UNKNOWN_DECISION);
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
            $this->apply($request, $decision, $refusalReason);

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
     * Takes $decision on $request — accept it, or refuse it with
     * $refusalReason — through Service\RequestStatusService's transition,
     * in one transaction. The one door every decision goes through (see
     * the class comment); it checks what the transition itself checks —
     * the status the request may leave, the reason of a refusal — and
     * nothing about where the decision came from: the dialog only decides
     * a pending request of a current year (decide()), while the fiche may
     * still refuse an accepted request, or close one of a past year.
     *
     * @throws RegistrationException when the decision cannot be taken
     */
    public function apply(RegistrationRequest $request, string $decision, ?string $refusalReason = null): void
    {
        if ($decision !== self::DECISION_ACCEPT && $decision !== self::DECISION_REFUSE) {
            throw new RegistrationException(self::MESSAGE_UNKNOWN_DECISION);
        }

        $this->requestRepository->transactional(function () use ($request, $decision, $refusalReason): void {
            if ($decision === self::DECISION_ACCEPT) {
                $this->statusService->accept($request);
            } else {
                $this->statusService->refuse($request, (string) $refusalReason);
            }
        });
    }

    /**
     * What is said once $decision has been taken on $count requests, read
     * $from the list, the fiche or the bulk refusal: the decision, then
     * whether an e-mail is leaving — never on its own yet, so « Aucun e-mail
     * n'est parti », with where to send it from, or why it cannot be sent
     * at all (its template is not written).
     */
    public function confirmation(
        string $decision,
        ?string $refusalReason = null,
        int $count = 1,
        string $from = self::FROM_LIST
    ): string {
        $refused = $decision === self::DECISION_REFUSE;
        $reason = $refused ? (RegistrationRequest::REFUSAL_REASON_LABELS[(string) $refusalReason] ?? null) : null;
        $many = $count > 1;

        if ($count <= 0) {
            return $refused ? 'Aucune demande à refuser.' : 'Aucune demande à accepter.';
        }

        $done = ($many ? $count . ' demandes ' : 'Demande ')
            . ($refused ? 'refusée' : 'acceptée') . ($many ? 's' : '')
            . ($reason !== null ? ' (' . $reason . ')' : '') . '.';

        $email = match ($this->emailOutcome($decision)) {
            self::EMAIL_TEMPLATE_MISSING => "Aucun e-mail n'est parti : le gabarit "
                . ($refused ? 'de refus' : "d'acceptation") . " n'est pas encore rédigé (voir Gabarits). "
                . ($many
                    ? "Les familles voient « en attente » jusqu'à l'envoi de leur e-mail."
                    : "La famille voit « en attente » jusqu'à son envoi."),
            default => "Aucun e-mail n'est parti : " . match (true) {
                $many => 'envoyez chacun depuis la fiche de sa demande. Les familles voient',
                $from === self::FROM_REQUEST_PAGE
                    => "envoyez-le avec « Envoyer maintenant », ci-dessous. La famille voit",
                default => 'envoyez-le depuis la fiche de la demande. La famille voit',
            } . ' « en attente » jusque-là.',
        };

        return $done . ' ' . $email;
    }

    /**
     * « Note interne », saved on its own from the fiche (IT-08): the note
     * and nothing else — no status, no decision, no e-mail. Stored the way
     * the dialog stores it (normalizeNote(), empty clears it), so the two
     * compare equal afterwards. The journal says that a note was saved,
     * never what it says.
     */
    public function saveNote(RegistrationRequest $request, string $note): void
    {
        $note = self::normalizeNote($note);
        $this->requestRepository->updateInternalNotes($request->id, $note !== '' ? $note : null);

        $this->journalService->log(
            'registration',
            'registration_request_note_saved',
            'info',
            "Note interne d'une demande d'inscription enregistrée",
            ['request_id' => $request->id]
        );
    }

    /**
     * What becomes of the e-mail of a decision just taken.
     */
    private function emailOutcome(string $decision): string
    {
        return $this->isEmailReady($decision) ? self::EMAIL_BY_HAND : self::EMAIL_TEMPLATE_MISSING;
    }

    /**
     * A note as it is compared and stored: trimmed, with LF line endings.
     */
    private static function normalizeNote(string $note): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $note));
    }
}
