<?php

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Journal\JournalService;
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
 */
class RequestStatusService
{
    /**
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        RegistrationRequest::STATUS_PENDING => [RegistrationRequest::STATUS_ACCEPTED, RegistrationRequest::STATUS_REFUSED, RegistrationRequest::STATUS_WITHDRAWN],
        RegistrationRequest::STATUS_ACCEPTED => [RegistrationRequest::STATUS_REFUSED, RegistrationRequest::STATUS_WITHDRAWN, RegistrationRequest::STATUS_PENDING],
        RegistrationRequest::STATUS_REFUSED => [RegistrationRequest::STATUS_PENDING],
        RegistrationRequest::STATUS_WITHDRAWN => [RegistrationRequest::STATUS_PENDING],
        RegistrationRequest::STATUS_ENCODED => [],
    ];

    public function __construct(
        private RegistrationRequestRepository $repository,
        private JournalService $journalService
    ) {
    }

    public function accept(RegistrationRequest $request): void
    {
        $this->transition($request, RegistrationRequest::STATUS_ACCEPTED);
    }

    public function refuse(RegistrationRequest $request): void
    {
        $this->transition($request, RegistrationRequest::STATUS_REFUSED);
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
    private function transition(RegistrationRequest $request, string $newStatus): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$request->status] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            throw new RegistrationException("Transition invalide : « {$request->status} » vers « {$newStatus} ».");
        }

        $finalAt = in_array($newStatus, RegistrationRequest::FINAL_STATUSES, true) ? new \DateTimeImmutable() : null;
        $this->repository->updateStatus($request->id, $newStatus, $finalAt);

        $this->journalService->log(
            'registration',
            'registration_request_status_changed',
            'info',
            "Statut de la demande d'inscription changé : « {$request->status} » vers « {$newStatus} »",
            ['request_id' => $request->id]
        );
    }

    /**
     * The status a parent-facing surface (tracking page) is allowed to
     * show — never the raw internal status directly for the two email-
     * gated transitions.
     */
    public function visibleStatus(RegistrationRequest $request): string
    {
        if (in_array($request->status, [RegistrationRequest::STATUS_ACCEPTED, RegistrationRequest::STATUS_ENCODED], true)
            && $request->acceptedEmailSentAt === null
        ) {
            return RegistrationRequest::STATUS_PENDING;
        }

        if ($request->status === RegistrationRequest::STATUS_REFUSED && $request->refusedEmailSentAt === null) {
            return RegistrationRequest::STATUS_PENDING;
        }

        return $request->status;
    }
}
