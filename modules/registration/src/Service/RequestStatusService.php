<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Journal\JournalService;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * The manual side of a request's progression (module spec: "en attente →
 * acceptée → encodée dans Desk", the last step never manual — see
 * ReconciliationService). Each method validates the current status before
 * writing, so an inconsistent transition (e.g. accepting an already-
 * encoded request) is refused rather than silently applied.
 *
 * visibleStatus() is the other half of the module spec's own design rule:
 * the tracking page must never reveal an acceptance or a refusal before
 * the corresponding email has actually gone out (Service\
 * RequestEmailService) — a chief deciding on Friday and sending Monday
 * must not let the parent find out in between.
 *
 * A transition also makes the old decision's e-mails obsolete (issue #843,
 * D8): RegistrationRequestRepository::updateStatus() supersedes them in
 * the same transaction, cancelling any still scheduled, and this service
 * journals how many it cancelled.
 */
class RequestStatusService
{
    /**
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        RegistrationRequest::STATUS_PENDING => [RegistrationRequest::STATUS_ACCEPTED,
            RegistrationRequest::STATUS_REFUSED, RegistrationRequest::STATUS_WITHDRAWN],
        RegistrationRequest::STATUS_ACCEPTED => [RegistrationRequest::STATUS_REFUSED,
            RegistrationRequest::STATUS_WITHDRAWN, RegistrationRequest::STATUS_PENDING],
        RegistrationRequest::STATUS_REFUSED => [RegistrationRequest::STATUS_PENDING],
        RegistrationRequest::STATUS_WITHDRAWN => [RegistrationRequest::STATUS_PENDING],
        RegistrationRequest::STATUS_ENCODED => [],
    ];

    /** What a refusal without a valid reason answers, wherever it came from. */
    public const MESSAGE_REFUSAL_REASON_REQUIRED =
        'Choisissez le motif du refus : « Pas de place » ou « Autre ». La demande n\'a pas été modifiée.';

    public function __construct(
        private RegistrationRequestRepository $repository,
        private JournalService $journalService
    ) {
    }

    /**
     * $automatic marks an acceptance taken by the site itself — the
     * automatic acceptance of the « Oui » requests (issue #843, IT-10) —
     * rather than by a member of the staff: the journal entry says so, and
     * nothing else differs.
     */
    public function accept(RegistrationRequest $request, bool $automatic = false): void
    {
        $this->transition($request, RegistrationRequest::STATUS_ACCEPTED, null, $automatic);
    }

    /**
     * $reason is « Pas de place » or « Autre » (RegistrationRequest::
     * REFUSAL_*, issue #843 D6), stored with the status: « Pas de place »
     * is what makes the same child's request of a later year an
     * « Ancienne demande restée sans place » (Service\RecommendationService).
     * Every refusal carries one — the decision dialog, the fiche and the
     * bulk refusal all ask for it (IT-04) — so anything else, the empty
     * string included, is refused before anything is written.
     *
     * @throws RegistrationException on a missing or unknown reason, or a
     *         status that cannot be refused
     */
    public function refuse(RegistrationRequest $request, string $reason): void
    {
        if (!self::isRefusalReason($reason)) {
            throw new RegistrationException(self::MESSAGE_REFUSAL_REASON_REQUIRED);
        }

        $this->transition($request, RegistrationRequest::STATUS_REFUSED, $reason);
    }

    /**
     * Whether $reason is one of the two a refusal may carry — what a caller
     * refusing several requests checks once, before the first one.
     */
    public static function isRefusalReason(?string $reason): bool
    {
        return $reason !== null && isset(RegistrationRequest::REFUSAL_REASON_LABELS[$reason]);
    }

    public function withdraw(RegistrationRequest $request): void
    {
        $this->transition($request, RegistrationRequest::STATUS_WITHDRAWN);
    }

    public function revertToPending(RegistrationRequest $request): void
    {
        $this->transition($request, RegistrationRequest::STATUS_PENDING);
    }

    /**
     * @throws RegistrationException when $request's current status cannot
     *         reach $newStatus manually
     */
    private function transition(
        RegistrationRequest $request,
        string $newStatus,
        ?string $refusalReason = null,
        bool $automatic = false
    ): void {
        $allowed = self::ALLOWED_TRANSITIONS[$request->status] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            throw new RegistrationException("Transition invalide : « {$request->status} » vers « {$newStatus} ».");
        }

        $finalAt = in_array($newStatus, RegistrationRequest::FINAL_STATUSES, true) ? new \DateTimeImmutable() : null;
        $cancelledEmails = $this->repository->updateStatus($request->id, $newStatus, $finalAt, $refusalReason);

        // The reason is a code ('no_room' / 'other'), never the child: it
        // says why the unit refused, which is what an audit asks.
        $context = ['request_id' => $request->id];
        if ($refusalReason !== null) {
            $context['refusal_reason'] = $refusalReason;
        }
        // « Annulé suite à un changement de décision » (D8): how many
        // scheduled decision e-mails this change stopped — a count, never
        // to whom they were addressed.
        if ($cancelledEmails > 0) {
            $context['decision_emails_cancelled'] = $cancelledEmails;
        }
        // Taken by the site, not by a member of the staff (IT-10): an audit
        // must tell the two apart without reading anything else.
        if ($automatic) {
            $context['automatic'] = true;
        }
        $this->journalService->log(
            'registration',
            'registration_request_status_changed',
            'info',
            "Statut de la demande d'inscription changé : « {$request->status} » vers « {$newStatus} »"
                . ($automatic ? ' (acceptation automatique)' : ''),
            $context
        );
    }

    /**
     * The status a parent-facing surface (tracking page) is allowed to
     * show — never the raw internal status directly for the two email-
     * gated transitions.
     *
     * An acceptance (accepted, or already encoded in Desk) or a refusal
     * shows only once an e-mail of THAT decision has gone out (R3):
     * `decisionEmailSentAt` is read from `registration_decision_emails`
     * for the request's current decision only, so an acceptance e-mail
     * sent before the request was refused — or before it went back to
     * pending and was accepted again — never makes the new decision
     * visible ahead of its own e-mail.
     */
    public function visibleStatus(RegistrationRequest $request): string
    {
        if (DecisionEmail::decisionForStatus($request->status) !== null && $request->decisionEmailSentAt === null) {
            return RegistrationRequest::STATUS_PENDING;
        }

        return $request->status;
    }
}
