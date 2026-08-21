<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Statistics\StatisticsPayloadBuilder;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `statistics.json` — exactly the same document the daily report would
 * transmit (Core\Statistics\StatisticsPayloadBuilder), with the values it
 * has at the moment of generation.
 *
 * Produced **even when reporting is disabled**: the unit chose not to send
 * this automatically, which says nothing about whether they want it in an
 * archive they are attaching to their own support request by hand. It
 * carries no secret, by construction — the secret is never a payload field.
 */
class StatisticsCollector implements SupportCollectorInterface
{
    public function __construct(private StatisticsPayloadBuilder $payloadBuilder)
    {
    }

    public function name(): string
    {
        return 'statistics';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $context->addFileFromContent('statistics.json', $this->payloadBuilder->buildJson());
    }
}
