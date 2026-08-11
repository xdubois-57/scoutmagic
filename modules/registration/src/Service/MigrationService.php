<?php

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Journal\JournalService;
use Core\Member\MemberEmail;
use Core\Member\MemberEmailRepository;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;

/**
 * The one migration path both automatic reconciliation (Service\
 * ReconciliationService) and manual linking by Desk tiers number
 * (Controller\RegistrationRequestController::link()) go through — module
 * spec: "un seul chemin de code, pas deux". Not a plain status change:
 * confirmed secondary emails move to the real member (Core\Member\
 * MemberEmailRepository) so a parent never loses the tracking access they
 * just configured, identity data itself never migrates (Desk stays
 * authoritative), and the whole thing runs inside one DB transaction so a
 * partial failure never leaves the status flipped without the emails
 * having moved (module spec's own pitfall). The pre-migration checks are
 * also idempotent-safe: calling migrate() again for a request already
 * encoded to the same member is a no-op, so a retried call after a prior
 * failure is always safe to just re-run.
 */
class MigrationService
{
    public function __construct(
        private \PDO $pdo,
        private RegistrationRequestRepository $requestRepository,
        private RegistrationSecondaryEmailRepository $secondaryEmailRepository,
        private MemberEmailRepository $memberEmailRepository,
        private JournalService $journalService
    ) {
    }

    /**
     * @throws RegistrationException when $memberId is already linked to a
     *         different request
     */
    public function migrate(int $requestId, int $memberId): void
    {
        $request = $this->requestRepository->findById($requestId);
        if ($request === null) {
            throw new RegistrationException('Demande introuvable.');
        }

        if ($request->status === RegistrationRequest::STATUS_ENCODED && $request->linkedMemberId === $memberId) {
            return;
        }

        $existingLink = $this->requestRepository->findByLinkedMemberId($memberId);
        if ($existingLink !== null && $existingLink->id !== $requestId) {
            throw new RegistrationException('Ce membre est déjà lié à une autre demande d\'inscription.');
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($this->secondaryEmailRepository->findByRequest($requestId) as $secondaryEmail) {
                if (!$secondaryEmail->isValid()) {
                    continue;
                }
                if ($this->memberEmailRepository->findByMemberAndEmail($memberId, $secondaryEmail->email) === null) {
                    $this->memberEmailRepository->create(
                        $memberId, $secondaryEmail->email, MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
                    );
                }
            }

            $this->requestRepository->linkToMemberAndEncode($requestId, $memberId, new \DateTimeImmutable());

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->journalService->log(
            'registration',
            'registration_request_migrated',
            'info',
            "Demande d'inscription migrée vers un membre existant",
            ['request_id' => $requestId, 'member_id' => $memberId]
        );
    }
}
