<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\PropositionListener;

/**
 * A consumer that also wants to hear about its propositions
 * (`Api\PropositionListener`).
 */
class FakeListeningConsumer extends FakeMessageConsumer implements PropositionListener
{
    /** @var array<int, array{InboundMessage, \Modules\InboundMail\Api\MessageCandidate[]}> */
    public array $proposed = [];

    public bool $throwsOnProposed = false;

    public function onProposed(InboundMessage $message, array $candidates): void
    {
        $this->proposed[] = [$message, $candidates];

        if ($this->throwsOnProposed) {
            throw new \RuntimeException('push is down');
        }
    }
}
