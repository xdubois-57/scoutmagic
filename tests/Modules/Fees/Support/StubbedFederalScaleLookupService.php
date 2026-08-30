<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Support;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Modules\Fees\Service\FederalScaleLookupService;
use Modules\LlmConnector\Api\LlmConnectorInterface;

/**
 * The real service with its one network seam replaced by a fixed page —
 * the same shape `Modules\Gallery\Service\OgScraperService` uses so its own
 * validation can be exercised without a network. Everything below
 * `fetchPage()` (the prompt, the parser, the year check, the ranges) is the
 * production code, unmodified.
 */
final class StubbedFederalScaleLookupService extends FederalScaleLookupService
{
    public function __construct(
        ?LlmConnectorInterface $connector,
        SettingService $settings,
        JournalService $journal,
        private ?string $page
    ) {
        parent::__construct($connector, $settings, $journal);
    }

    protected function fetchPage(string $url): ?string
    {
        return $this->page;
    }
}
