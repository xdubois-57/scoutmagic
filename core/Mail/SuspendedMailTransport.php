<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * The transport an installation gets while its mail capture is armed and
 * no longer acting (Core\Security\SandboxLossState, ARCHITECTURE.md §8.63):
 * nothing leaves, every send fails with {@see MailSendingSuspendedException}.
 *
 * It stands where the capture would have stood — under the provider chain,
 * handed to `Transport\MailTransportFactory::build()` by the composition
 * roots — so every path that reaches a relay, the manual probe included,
 * meets it.
 *
 * **One category may still pass, and only if it was already passing.** The
 * capture lets the sign-in link through when its superadmin ticked that
 * exemption (`MailPurpose::MagicLink`, §8.63), because without it nobody
 * could sign in to the installation being tested. Blocking it here would
 * turn that deadlock back on at the worst moment — when a superadmin has
 * to sign in to decide. So the composition root hands the ordinary
 * transport over when, and only when, the exemption is on; without it,
 * nothing at all goes out.
 */
final class SuspendedMailTransport implements MailTransportInterface
{
    public function __construct(private readonly ?MailTransportInterface $magicLinkDelivery = null)
    {
    }

    public function deliver(PHPMailer $mail, MailPurpose $purpose): void
    {
        if ($purpose === MailPurpose::MagicLink && $this->magicLinkDelivery !== null) {
            $this->magicLinkDelivery->deliver($mail, $purpose);

            return;
        }

        throw MailSendingSuspendedException::captureLost();
    }
}
