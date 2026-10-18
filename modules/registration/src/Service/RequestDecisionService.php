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
use Core\View\DateFilterExtension;
use Modules\Registration\Repository\DecisionEmail;
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
 * and, while the automatic sending is on (IT-09), the new decision's
 * e-mail is planned in that same transaction, with a full delay
 * (Service\AutomaticDecisionEmailService::plan()). Otherwise no e-mail
 * leaves on its own: the decision e-mails are sent by hand from the fiche.
 *
 * confirmation() writes what every one of those paths says afterwards,
 * which answers « un e-mail va-t-il partir ? » (D8).
 *
 * The automatic acceptance of the « Oui » requests (IT-10,
 * Service\AutomaticAcceptanceService) takes its decisions through apply()
 * as well — no parallel path —, flagged `automatic` for the journal; the
 * rules that decide which requests it may take are
 * Service\AutomaticAcceptancePolicy's, which the dialog reads to say so.
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
     * confirmation() gives: planned by the automatic sending (« partira le
     * … ») or to send by hand.
     */
    private const EMAIL_AUTOMATIC = 'automatic';
    private const EMAIL_BY_HAND = 'by_hand';

    private const MESSAGE_UNKNOWN_DECISION = 'Décision inconnue : choisissez « Accepter » ou « Refuser ».';

    public function __construct(
        private RegistrationRequestRepository $requestRepository,
        private RequestStatusService $statusService,
        private RecommendationService $recommendationService,
        private ScoutYearService $scoutYearService,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journalService,
        private AutomaticDecisionEmailService $automaticEmails,
        private AutomaticAcceptancePolicy $automaticAcceptance
    ) {
    }

    /**
     * The automatic acceptance of the « Oui » requests (IT-10) — its switch,
     * its date of effect and the rules it accepts by.
     */
    public function automaticAcceptance(): AutomaticAcceptancePolicy
    {
        return $this->automaticAcceptance;
    }

    /**
     * The automatic sending of the decision e-mails (IT-09) — its switch,
     * its delay and the box on « Réglages » that sets them.
     */
    public function automaticSending(): AutomaticDecisionEmailService
    {
        return $this->automaticEmails;
    }

    /**
     * What the « Demandes » list of $scoutYearId needs for its dialogs.
     *
     * `decidable`: the year is the current one or a later one — a past year
     * shows no « Décider » button at all. `recommendations`: keyed by request
     * id, empty while waitlists are not managed (`show_recommendation`
     * false) — the dialog then shows no recommendation box at all, not an
     * empty one. The two `*_email_ready` flags say whether the decision
     * e-mails could be sent from the fiche, and the two `*_auto_send_at`
     * when each would leave on its own if decided now — null while the
     * automatic sending is off (IT-09): what the dialog's last sentences
     * are about.
     *
     * `auto_accept` says whether the automatic acceptance of the « Oui »
     * requests is running (IT-10), and `auto_accept_ids` which of the
     * year's requests it would take at its next pass if nobody decides
     * first — the same rule as the pass itself
     * (AutomaticAcceptancePolicy::acceptableRequestIds()), so the dialog
     * never announces an acceptance the pass would not make.
     *
     * $anyPendingShown false — the list, filtered, shows no pending request,
     * hence no dialog — spares the whole year's queue, which decrypts every
     * pending request's birth date.
     *
     * @return array{
     *   decidable: bool,
     *   show_recommendation: bool,
     *   recommendations: array<int, Recommendation>,
     *   accepted_auto_send_at: ?\DateTimeImmutable,
     *   refused_auto_send_at: ?\DateTimeImmutable,
     *   auto_accept: bool,
     *   auto_accept_ids: array<int, int>
     * }
     */
    public function dialogContext(int $scoutYearId, bool $anyPendingShown = true): array
    {
        $decidable = $this->isDecidableYear($scoutYearId);
        $showRecommendation = $decidable && $this->recommendationService->isAvailableForYear($scoutYearId);
        $recommendations = $showRecommendation && $anyPendingShown
            ? $this->recommendationService->forYear($scoutYearId)
            : [];
        $autoAccept = $showRecommendation && $this->automaticAcceptance->isActive();
        $from = $autoAccept ? $this->automaticAcceptance->effectiveFrom() : null;

        return [
            'decidable' => $decidable,
            'show_recommendation' => $showRecommendation,
            'recommendations' => $recommendations,
            'accepted_auto_send_at' => $this->automaticEmails->plannedDueAtIfDecidedNow(),
            'refused_auto_send_at' => $this->automaticEmails->plannedDueAtIfDecidedNow(),
            'auto_accept' => $autoAccept,
            'auto_accept_ids' => $from !== null
                ? AutomaticAcceptancePolicy::acceptableRequestIds($recommendations, $from)
                : [],
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
     * @return \DateTimeImmutable|null when the decision's e-mail is planned
     *         to leave (apply()), null when it is not
     *
     * @throws RegistrationException when the decision cannot be taken
     */
    public function decide(
        RegistrationRequest $request,
        string $decision,
        string $note,
        ?string $refusalReason,
        ?string $noteAsShown = null
    ): ?\DateTimeImmutable {
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

        $write = function () use ($request, $decision, $note, $noteChanged, $refusalReason): ?\DateTimeImmutable {
            $plannedFor = $this->apply($request, $decision, $refusalReason);

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

            return $plannedFor;
        };

        return $this->requestRepository->transactional($write);
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
     * Then, while the automatic sending is on, the e-mail of the new
     * decision is planned for now + the delay, in the same transaction —
     * after the transition, which has just cancelled the e-mail planned for
     * the old decision, so a change of decision always starts a full delay
     * again (D8). A decision that fails plans nothing, and neither does one
     * on a request of a past scout year (consultation only, R8): closing a
     * request left open a year ago is not a decision to announce on its
     * own — its e-mail, if any, is sent by hand.
     *
     * $automatic marks an acceptance taken by the site itself (IT-10,
     * Service\AutomaticAcceptanceService) — the journal says so. A refusal
     * is never automatic.
     *
     * @return \DateTimeImmutable|null when the e-mail is planned to leave,
     *         null when nothing was planned
     *
     * @throws RegistrationException when the decision cannot be taken
     */
    public function apply(
        RegistrationRequest $request,
        string $decision,
        ?string $refusalReason = null,
        bool $automatic = false
    ): ?\DateTimeImmutable {
        if ($decision !== self::DECISION_ACCEPT && $decision !== self::DECISION_REFUSE) {
            throw new RegistrationException(self::MESSAGE_UNKNOWN_DECISION);
        }
        if ($automatic && $decision !== self::DECISION_ACCEPT) {
            throw new \LogicException('Only an acceptance can be automatic: the site never refuses a request.');
        }

        return $this->requestRepository->transactional(
            function () use ($request, $decision, $refusalReason, $automatic): ?\DateTimeImmutable {
                if ($decision === self::DECISION_ACCEPT) {
                    $this->statusService->accept($request, $automatic);
                } else {
                    $this->statusService->refuse($request, (string) $refusalReason);
                }

                // The switch first: it is a cached setting, the year two
                // queries — and a bulk refusal asks once per request.
                if (!$this->automaticEmails->isEnabled() || !$this->isDecidableYear($request->scoutYearId)) {
                    return null;
                }

                return $this->automaticEmails->plan($request, self::emailDecision($decision))?->dueAt;
            }
        );
    }

    /**
     * What is said once $decision has been taken on $count requests, read
     * $from the list, the fiche or the bulk refusal: the decision, then
     * whether an e-mail is leaving — on its own at $plannedFor, the due
     * date apply() answered (the latest one, for a bulk refusal), or not:
     * « Aucun e-mail n'est parti », with where to send it from.
     */
    public function confirmation(
        string $decision,
        ?string $refusalReason = null,
        int $count = 1,
        string $from = self::FROM_LIST,
        ?\DateTimeImmutable $plannedFor = null
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

        $email = match ($this->emailOutcome($plannedFor)) {
            self::EMAIL_AUTOMATIC => ($many
                ? 'Les e-mails ' . ($refused ? 'de refus' : "d'acceptation") . ' partiront vers le '
                : ($refused ? "L'e-mail de refus partira le " : "L'e-mail d'acceptation partira le "))
                . DateFilterExtension::datetimeFr($plannedFor)
                . " ; d'ici là, vous pouvez changer la décision sans que rien ne soit envoyé. "
                . ($many ? 'Les familles voient' : 'La famille voit') . ' « en attente » jusque-là.',
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
     * What becomes of the e-mail of a decision just taken: planned when
     * apply() answered a due date, else by hand.
     */
    private function emailOutcome(?\DateTimeImmutable $plannedFor): string
    {
        return $plannedFor !== null ? self::EMAIL_AUTOMATIC : self::EMAIL_BY_HAND;
    }

    /**
     * The decision e-mail (DecisionEmail::DECISION_*) of a decision taken
     * here (self::DECISION_*).
     */
    private static function emailDecision(string $decision): string
    {
        return $decision === self::DECISION_REFUSE ? DecisionEmail::DECISION_REFUSED : DecisionEmail::DECISION_ACCEPTED;
    }

    /**
     * A note as it is compared and stored: trimmed, with LF line endings.
     */
    private static function normalizeNote(string $note): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $note));
    }
}
