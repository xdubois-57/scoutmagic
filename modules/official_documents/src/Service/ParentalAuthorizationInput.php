<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Service;

use Core\Service\DateInput;
use Modules\OfficialDocuments\Api\OfficialDocumentsException;

/**
 * What the parent typed, once it has been read.
 *
 * The screen's own form and nothing else: the signatory, their capacity, the
 * two dates, the place, and whether the activity leaves Belgium. **No event
 * identifier** — the picker on the page only fills the two date fields in
 * the browser, so the server has nothing to re-resolve and nothing to trust
 * a client about.
 *
 * Refusals are French sentences that never quote what was refused: the form
 * carries a family's own names.
 */
final class ParentalAuthorizationInput
{
    private function __construct(
        public readonly string $signatoryName,
        public readonly SignatoryCapacity $capacity,
        public readonly \DateTimeImmutable $startDate,
        public readonly \DateTimeImmutable $endDate,
        public readonly string $place,
        /**
         * The form's note (1) says its last sentence — leaving Belgian
         * territory — is to be struck out for activities in Belgium. So
         * false, the ordinary case, is what gets the stroke.
         */
        public readonly bool $abroad
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @throws OfficialDocumentsException
     */
    public static function fromBody(array $body): self
    {
        $signatoryName = trim((string) ($body['signatory_name'] ?? ''));
        if ($signatoryName === '') {
            throw new OfficialDocumentsException('Indiquez le nom de la personne qui signe.');
        }

        $capacity = SignatoryCapacity::tryFrom((string) ($body['capacity'] ?? ''));
        if ($capacity === null) {
            throw new OfficialDocumentsException('Indiquez en quelle qualité vous signez.');
        }

        // `<input type="date">` posts ISO, and Core\Service\DateInput::iso()
        // is how this codebase reads one: it never throws and answers null
        // for anything it cannot read — the 31 February a hand-rolled parse
        // would roll forward into March, and the NUL byte that turns `new
        // DateTimeImmutable()` into a 500 (SECURITY.md §35). Naming the
        // format-parsing constructor here is not an option either, even in a
        // comment: `Tests\Security\DateParsingConvergenceTest` greps for it
        // line by line, on purpose, so that the one place allowed to call it
        // stays findable by the same grep an auditor would run.
        $startDate = DateInput::iso((string) ($body['start_date'] ?? ''));
        $endDate = DateInput::iso((string) ($body['end_date'] ?? ''));
        if ($startDate === null || $endDate === null) {
            throw new OfficialDocumentsException('Indiquez la date de début et la date de fin de l\'activité.');
        }
        if ($endDate < $startDate) {
            throw new OfficialDocumentsException('La date de fin ne peut pas précéder la date de début.');
        }

        $place = trim((string) ($body['place'] ?? ''));
        if ($place === '') {
            throw new OfficialDocumentsException('Indiquez le lieu où le document est signé.');
        }

        return new self(
            $signatoryName,
            $capacity,
            $startDate,
            $endDate,
            $place,
            (string) ($body['abroad'] ?? '') !== ''
        );
    }

    /**
     * Built directly, for a test or for a caller that has already validated
     * — the screen always goes through fromBody().
     */
    public static function of(
        string $signatoryName,
        SignatoryCapacity $capacity,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        string $place,
        bool $abroad = false
    ): self {
        return new self($signatoryName, $capacity, $startDate, $endDate, $place, $abroad);
    }
}
