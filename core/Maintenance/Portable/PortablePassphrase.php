<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Portable;

/**
 * How short a portable backup's passphrase is allowed to be.
 *
 * **A length rule, and nothing else.** No required digit, no required
 * symbol, no character-class arithmetic: those rules are what produce
 * `Scout2026!` — the shape everybody reaches for when told to satisfy
 * them, and the shape every cracking dictionary starts with. Length is the
 * one property that reliably costs an attacker something, and it is also
 * the only one an operator can satisfy with four ordinary words they will
 * still remember in a year.
 *
 * **Sixteen**, which is what the screen promises, and this class is why
 * the screen can promise it: a rule enforced only in the browser is not a
 * rule. {@see \Core\Http\Controller\MaintenanceController::createPortableBackup()}
 * asks here, and the field's own `minlength` says the same number.
 *
 * The number is not arbitrary. This passphrase is the ONLY thing
 * protecting the site's master key inside an archive designed to be
 * carried away — see {@see SecretEnvelope} for what that means — and the
 * derivation guarding it, however slow, only multiplies the cost of each
 * guess. Sixteen characters is where a human-chosen phrase stops being
 * worth enumerating.
 */
final class PortablePassphrase
{
    public const MIN_LENGTH = 16;

    /**
     * Why this passphrase is refused, or null when it is fine.
     *
     * Measured in CHARACTERS, not bytes: `mb_strlen()`, because a
     * passphrase written with accents — the ordinary case in French — has
     * more bytes than characters, and a rule that counted bytes would
     * accept a shorter phrase from an operator who happened to use them.
     * The screen counts characters too, and the two must not disagree
     * about whether a phrase is long enough.
     */
    public static function refuse(string $passphrase): ?string
    {
        if (mb_strlen($passphrase) < self::MIN_LENGTH) {
            return sprintf(
                'La phrase de passe doit faire au moins %d caractères — c\'est la seule chose qui protège les '
                . 'clés de chiffrement du site dans cette archive.',
                self::MIN_LENGTH
            );
        }

        return null;
    }
}
