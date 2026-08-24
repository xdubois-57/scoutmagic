<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Api\StructuredCommunicationInterface;
use Modules\Finance\Repository\ExpectedReceivableRepository;

/**
 * Belgian structured communication ("communication structurée normalisée
 * OGM/VCS") generator: +++NNN/NNNN/NNNNN+++ where the last two digits are
 * a mod-97 check of the first 10. The 10-digit base is random, retried on
 * the rare collision against already-issued communications (same pattern
 * as Core\Url\ShortUrlService's code generation).
 */
class StructuredCommunicationService implements StructuredCommunicationInterface
{
    private const MAX_ATTEMPTS = 20;

    public function __construct(private ExpectedReceivableRepository $repository)
    {
    }

    public function generate(): string
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $base = str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $communication = self::format($base);

            if (!$this->repository->communicationExists($communication)) {
                return $communication;
            }
        }

        throw new \RuntimeException('Unable to generate a unique structured communication after ' . self::MAX_ATTEMPTS . ' attempts.');
    }

    /**
     * Whether $communication is a well-formed Belgian structured
     * communication — twelve digits whose last two check the first ten.
     *
     * Accepts every shape a human plausibly types or pastes: the
     * canonical `+++123/4567/89012+++`, the `***…***` variant some banks
     * print, twelve bare digits, and any spacing or punctuation in
     * between. Everything that is not a digit is stripped first, so the
     * only thing that ever decides is the twelve digits themselves.
     *
     * **The check digits run 01–97, never 00**, and that is the trap
     * here: the format maps a remainder of 0 onto 97 (see format()
     * below, which is where the rule is actually applied when issuing
     * one). A naive `$base % 97 === $check` therefore rejects every
     * perfectly valid communication whose key is 97 — roughly one in a
     * hundred, which is frequent enough to be reported as a bug and rare
     * enough to survive a hand test. Both halves of that rule are
     * covered by a named test.
     *
     * Static, like format(), because it decides nothing that needs the
     * database: it answers "is this a plausible communication", never
     * "does this correspond to something we are waiting for" — that
     * second question is Repository\ExpectedReceivableRepository::
     * findByCommunication().
     */
    public static function isValid(string $communication): bool
    {
        $digits = preg_replace('/\D/', '', $communication) ?? '';
        if (strlen($digits) !== 12) {
            return false;
        }

        $base = substr($digits, 0, 10);
        $check = (int) substr($digits, 10, 2);

        $expected = ((int) $base) % 97;
        if ($expected === 0) {
            $expected = 97;
        }

        return $check === $expected;
    }

    /**
     * Formats a 10-digit base into "+++NNN/NNNN/NNNNN+++" with its mod-97
     * check digits appended. Public/static so tests can verify the
     * checksum math directly against known-good examples.
     */
    public static function format(string $base10Digits): string
    {
        $baseInt = (int) $base10Digits;
        $checksum = $baseInt % 97;
        if ($checksum === 0) {
            $checksum = 97;
        }

        $full = $base10Digits . str_pad((string) $checksum, 2, '0', STR_PAD_LEFT);

        return '+++' . substr($full, 0, 3) . '/' . substr($full, 3, 4) . '/' . substr($full, 7, 5) . '+++';
    }
}
