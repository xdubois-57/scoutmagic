<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * What the staff prepared on the site against what the target year's Desk
 * import holds — Api\ScoutYearRegistrationReviewProvider::preparedImportGaps().
 * Three lists, given as help: none of them is a verdict.
 */
final class PreparedImportGaps
{
    /**
     * @param list<ScoutYearMember> $leaversStillActive animés announced as
     *        leaving in the year before the target year, yet active in the
     *        target year's import — `memberYearId` and `sectionId` are those
     *        of the TARGET year's row
     * @param list<PassageMismatch> $passagesElsewhere passages imported into
     *        another section than the destination chosen
     * @param list<MissingAcceptedRequest> $acceptedRequestsMissing accepted
     *        requests whose child the import does not hold. A request with
     *        an imported member of the same name and birth date is never
     *        listed, even when the match was too ambiguous for the module
     *        to link it (homonyms): left out rather than shown as a false
     *        gap
     */
    public function __construct(
        public readonly array $leaversStillActive,
        public readonly array $passagesElsewhere,
        public readonly array $acceptedRequestsMissing
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->leaversStillActive === [] && $this->passagesElsewhere === [] && $this->acceptedRequestsMissing === [];
    }
}
