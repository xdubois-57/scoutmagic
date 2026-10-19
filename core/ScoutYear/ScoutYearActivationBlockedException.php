<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

/**
 * ScoutYearAdminService refused to activate a year. Carries every reason
 * at once (not the first one found), so whoever catches it can say all of
 * what stands in the way and journal it; the message itself is English
 * and technical — the catcher composes the French from blockers().
 */
abstract class ScoutYearActivationBlockedException extends \RuntimeException
{
    /** Names the refused activation in the (English, technical) message. */
    public const ACTIVATION = 'Activation';

    /**
     * @param non-empty-list<ActivationBlocker> $blockers
     */
    public function __construct(
        private readonly int $yearId,
        private readonly array $blockers
    ) {
        parent::__construct(sprintf(
            '%s of scout year %d refused: %s.',
            static::ACTIVATION,
            $yearId,
            implode(', ', array_map(static fn(ActivationBlocker $b): string => $b->code, $blockers))
        ));
    }

    public function getYearId(): int
    {
        return $this->yearId;
    }

    /**
     * @return non-empty-list<ActivationBlocker>
     */
    public function getBlockers(): array
    {
        return $this->blockers;
    }

    /** @return list<string> */
    public function getBlockerCodes(): array
    {
        return array_map(static fn(ActivationBlocker $b): string => $b->code, $this->blockers);
    }
}
