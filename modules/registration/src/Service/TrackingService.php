<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;

/**
 * Both tracking-page entry points (Controller\TrackingController): a
 * public token URL, and an identified session linked by email — see the
 * module spec's "rattachement se fait par l'email... et par les adresses
 * secondaires" and registration_requests' own docblock for why the link
 * is always re-derived from a blind index, never stored.
 */
class TrackingService
{
    public function __construct(
        private RegistrationRequestRepository $requestRepository,
        private RegistrationSecondaryEmailRepository $secondaryEmailRepository,
        private EncryptionService $encryption
    ) {
    }

    /**
     * The minimal "by token" path — verifies possession of the token, then
     * returns the request. The Controller decides how much of it to show
     * (Service\TrackingService itself has no notion of "reduced" vs "full"
     * display — that's presentation, not linkage).
     */
    public function findByToken(int $requestId, string $token): ?RegistrationRequest
    {
        if (!$this->requestRepository->verifyTrackingToken($requestId, $token)) {
            return null;
        }

        return $this->requestRepository->findById($requestId);
    }

    /**
     * Whether $email (an identified session's own address) is linked to
     * request $requestId — its primary address, or a CONFIRMED secondary
     * one. Never trusts a bare request id from a URL without this check.
     */
    public function isLinkedByEmail(int $requestId, string $email): bool
    {
        $blindIndex = $this->encryption->blindIndex(RegistrationRequestRepository::normalizeEmail($email), 'registration_email');

        foreach ($this->requestRepository->findAllByEmailBlindIndex($blindIndex) as $request) {
            if ($request->id === $requestId) {
                return true;
            }
        }

        return in_array($requestId, $this->secondaryEmailRepository->findRequestIdsByValidBlindIndex($blindIndex), true);
    }

    /**
     * Every request an identified session's email is linked to (primary or
     * confirmed secondary) — the Espace membres menu hook and the "my
     * requests" list on an identified visit to the tracking page.
     *
     * @return array<RegistrationRequest>
     */
    public function findAllLinkedByEmail(string $email): array
    {
        $blindIndex = $this->encryption->blindIndex(RegistrationRequestRepository::normalizeEmail($email), 'registration_email');

        $all = $this->requestRepository->findAllByEmailBlindIndex($blindIndex);
        $seenIds = array_map(static fn(RegistrationRequest $r) => $r->id, $all);

        foreach ($this->secondaryEmailRepository->findRequestIdsByValidBlindIndex($blindIndex) as $requestId) {
            if (in_array($requestId, $seenIds, true)) {
                continue;
            }
            $request = $this->requestRepository->findById($requestId);
            if ($request !== null) {
                $all[] = $request;
                $seenIds[] = $requestId;
            }
        }

        return $all;
    }
}
