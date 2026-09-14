<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Probe;

use Core\Mail\MailPurpose;
use Core\Mail\MailTransportInterface;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\TransportConfigurator;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * One relay, chosen by hand, and no fallback (roadmap IT-04).
 *
 * **The absence of a fallback is the feature.** `MailTransportChain`
 * walks a lane until something takes the message, which is exactly right
 * for real mail and exactly wrong here: an operator asking « est-ce que
 * mes messages arrivent quand ils partent par Brevo » must not be
 * answered by a message that quietly went out through OVH. The whole
 * value of the history — two lines naming two relays and two different
 * verdicts — rests on each line naming the relay the message actually
 * left by.
 *
 * So a relay that refuses this message fails the probe, loudly, and the
 * operator learns something true. The quota, the circuit breaker and the
 * reserve are not consulted either, for the same reason: they exist to
 * protect real mail from a relay in trouble, and a diagnostic is the one
 * message that wants to meet the trouble.
 *
 * **The counter is still incremented**, because a probe is a real message
 * on a real relay and the day's allowance really has one fewer left. That
 * happens after the transport returns, on the same reasoning
 * `MailTransportChain` gives: a counter moved on an attempt would charge
 * a provider for a message it refused, and a counter that throws must
 * never turn a delivered message into a failed one.
 */
final class PinnedProviderTransport implements MailTransportInterface
{
    public function __construct(
        private MailProvider $provider,
        private MailLane $lane,
        private TransportConfigurator $configurator,
        private MailTransportInterface $delivery,
        private ?SendCounterRepository $counters = null
    ) {
    }

    public function deliver(PHPMailer $mail, MailPurpose $purpose): void
    {
        $this->configurator->apply($mail, $this->provider);

        $this->delivery->deliver($mail, $purpose);

        try {
            $this->counters?->increment($this->provider->id, $this->lane);
        } catch (\Throwable) {
            // The message left. A bookkeeping failure is not the probe's
            // failure, and reporting it as one would have the operator
            // record « jamais reçu » against a relay that did its job.
        }
    }
}
