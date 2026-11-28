<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

/**
 * What a caller of `MailService::send()` learns about ONE send beyond « it
 * did not throw » — whether the message left, or was only kept in the
 * deferred queue (D9, issue #1454).
 *
 * **Why an object handed in, and not a return value.** `send()` returns
 * nothing to ninety-odd call sites and to some two hundred test doubles,
 * most of which answer it with a closure typed `: void`. A return type
 * would have broken every one of them for a question two callers ask; an
 * optional, named, last argument breaks none — a caller that does not pass
 * it is told exactly what it was told before, and the deferral D9 decided
 * on stays as it is.
 *
 * **It also carries the way back.** A message that is deferred leaves the
 * caller's hands for a day; `$origin` is the caller's own opaque name for
 * it (`mass_mail.delivery:42`), stored beside the queued message so that
 * whatever later becomes of it — sent by the drain, or abandoned — can be
 * reported to whoever registered to hear it
 * (`Core\Module\DeferredMailOutcomeListener`). Core never parses it.
 *
 * One object per send: it records one outcome, and reusing it across
 * messages would make the second read the first one's answer.
 */
final class SendTracking
{
    /** The width of `mail_deferred_messages.origin`. */
    public const ORIGIN_MAX = 64;

    private ?Transport\MailLane $deferredOn = null;

    /**
     * @param string|null $origin The caller's reference for this message,
     *        or null when nobody needs to hear what becomes of it after a
     *        deferral (a test send). Lowercase letters, digits and
     *        `._:-` only: it is stored in plain text, so it must never be
     *        able to carry an address or a name.
     */
    public function __construct(public readonly ?string $origin = null)
    {
        if ($origin !== null && preg_match('/^[a-z0-9_.:-]{1,' . self::ORIGIN_MAX . '}$/', $origin) !== 1) {
            throw new \InvalidArgumentException('A send origin is a short reference made of [a-z0-9_.:-].');
        }
    }

    /**
     * Whether the message was put in the deferred queue rather than handed
     * to a relay. Nothing has left when this is true.
     */
    public function wasDeferred(): bool
    {
        return $this->deferredOn !== null;
    }

    /** The lane that had nothing left, when the message was deferred. */
    public function deferredLane(): ?Transport\MailLane
    {
        return $this->deferredOn;
    }

    /**
     * Written by `MailService::send()` once the queue has accepted the
     * message — never before, so a deferral the queue refused (and which
     * therefore throws) is not reported as one.
     */
    public function markDeferred(Transport\MailLane $lane): void
    {
        $this->deferredOn = $lane;
    }
}
