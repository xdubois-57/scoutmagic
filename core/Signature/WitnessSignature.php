<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Signature;

/**
 * The signature of somebody who has no account, traced on the spot — the
 * renter signing on the manager's phone the day of the inventory (ticket
 * §6a, #989): the PNG and the name they typed, and nothing else.
 *
 * **No account and no storage.** It exists only in the document the
 * module puts it on: nothing here can keep it, link it to an account or
 * offer it again. Built by `PersonalSignatureService::witness()`, from a
 * drawing only — never an imported image.
 */
final readonly class WitnessSignature
{
    public function __construct(
        public SignaturePng $png,
        public string $typedName
    ) {
    }
}
