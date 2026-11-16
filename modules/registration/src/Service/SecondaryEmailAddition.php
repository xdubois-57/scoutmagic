<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Modules\Registration\Repository\RegistrationSecondaryEmail;

/**
 * What adding a secondary address did: the row, and what became of the
 * confirmation e-mail that goes with it (#1236).
 *
 * A caller that tells the family « un email de confirmation vient d'être
 * envoyé » has to know whether one did.
 */
final class SecondaryEmailAddition
{
    /**
     * @param bool|null $confirmationSent true: the e-mail left. false: one
     *        was due and the transport refused it — the address stays,
     *        pending, and the failure is journaled. null: none was due (an
     *        address already on the request, inside its resend cooldown or
     *        past the hourly limit).
     */
    public function __construct(
        public readonly RegistrationSecondaryEmail $email,
        public readonly ?bool $confirmationSent
    ) {
    }
}
