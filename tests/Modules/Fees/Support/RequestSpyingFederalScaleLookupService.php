<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Fees\Support;

use Core\Journal\JournalService;
use Core\Config\SettingService;
use Modules\Fees\Service\FederalScaleLookupService;
use Modules\LlmConnector\Api\LlmConnectorInterface;

/**
 * A lookup service that replaces **only the request**, never the guard in
 * front of it (#1281).
 *
 * {@see StubbedFederalScaleLookupService} overrides `fetchPage()`, which is
 * where the guard against internal addresses lives, so it cannot show that a
 * refused address never reaches the network. This one overrides
 * `requestPage()` — the step that opens the connection, after the check — and
 * records every URL it is asked for: a refused address must leave this list
 * empty, a public one must appear in it.
 */
final class RequestSpyingFederalScaleLookupService extends FederalScaleLookupService
{
    /** @var list<string> */
    public array $requested = [];

    public function __construct(
        ?LlmConnectorInterface $connector,
        SettingService $settings,
        JournalService $journal,
        private ?string $page = null
    ) {
        parent::__construct($connector, $settings, $journal);
    }

    protected function requestPage(string $url): ?string
    {
        $this->requested[] = $url;

        return $this->page;
    }
}
