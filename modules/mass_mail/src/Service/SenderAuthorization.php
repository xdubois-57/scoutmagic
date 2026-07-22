<?php

declare(strict_types=1);

namespace Modules\MassMail\Service;

/**
 * What the account submitting a draft (create or edit) is allowed to do —
 * computed by Controller\MassMailController via Service\
 * MassMailAccessService + Core\Security\AuthSession, then handed to
 * Service\MassMailService as a single bundle instead of three loose
 * scalar/array parameters.
 */
final class SenderAuthorization
{
    /**
     * @param int[] $allowedListSectionIds Section ids this account may target a default list for (ignored when $isChefDUniteOrAbove).
     */
    public function __construct(
        public readonly bool $isChefDUniteOrAbove,
        public readonly array $allowedListSectionIds,
        public readonly ?int $forcedSenderSectionId
    ) {
    }
}
