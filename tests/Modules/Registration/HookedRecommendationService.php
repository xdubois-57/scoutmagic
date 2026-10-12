<?php

declare(strict_types=1);

namespace Tests\Modules\Registration;

use Core\Config\ScoutYearService;
use Core\ScoutYear\ScoutYearResolver;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\PriorityOrderService;
use Modules\Registration\Service\RecommendationService;
use Modules\Registration\Service\SlotService;

/**
 * The real recommendation (issue #843, IT-03), which calls a hook once
 * each computation is done, with the scout year and the number of the call
 * for that year — the moment between the automatic acceptance (IT-10)
 * reading the queue and acting on it, where a test puts a chief's
 * decision, a second pass or an emptied template. For the year a pass
 * works on, call 1 is its first look, before any lock; call 2 the
 * recomputation under the slot's lock that picks the first request.
 */
class HookedRecommendationService extends RecommendationService
{
    /** @var array<int, int> calls so far, per scout year */
    private array $calls = [];

    /**
     * @param \Closure(int, int): void $hook called with (call number for the year, scout year id)
     */
    public function __construct(
        RegistrationRequestRepository $requestRepository,
        SlotService $slotService,
        AgeBracketRepository $ageBracketRepository,
        ScoutYearService $scoutYearService,
        ScoutYearResolver $scoutYearResolver,
        PriorityOrderService $priorityOrderService,
        private \Closure $hook
    ) {
        parent::__construct(
            $requestRepository,
            $slotService,
            $ageBracketRepository,
            $scoutYearService,
            $scoutYearResolver,
            $priorityOrderService
        );
    }

    public function forYear(int $scoutYearId): array
    {
        $result = parent::forYear($scoutYearId);
        $this->calls[$scoutYearId] = ($this->calls[$scoutYearId] ?? 0) + 1;
        ($this->hook)($this->calls[$scoutYearId], $scoutYearId);

        return $result;
    }
}
