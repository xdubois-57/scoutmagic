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
     * Every structured communication a transaction's free text plausibly
     * carries, as bare twelve-digit strings, in order of first appearance.
     *
     * This exists because matching a receivable to a payment used to be a
     * substring search: the text was stripped of every non-digit and the
     * communication's own digits were looked for anywhere inside the
     * result. Two ways that goes wrong, and the second one is the reason
     * this method exists at all:
     *
     * - Stripping the separators glues unrelated digit runs together. A
     *   label reading "12/03/2026 45678 9012" reduces to
     *   "12032026456789012", in which twelve-digit sequences appear that
     *   are in the text nowhere — one of them is somebody's
     *   communication.
     * - A twelve-digit needle found inside a longer run (an account
     *   number, a bank reference) is not that communication either.
     *
     * So the digits are never flattened. The text is scanned for the
     * shapes a communication actually takes — `+++NNN/NNNN/NNNNN+++`,
     * the `***…***` variant, the same groups separated by spaces or dots,
     * or the twelve digits bare — each anchored so it can neither start
     * nor end inside a longer digit run. The caller then compares by
     * equality; **never by inclusion**, which is the whole point.
     *
     * The one concession to reality is a communication printed glued to
     * other digits, which some exports do: a run of thirteen digits or
     * more is also offered up, one twelve-digit window at a time, but
     * only for windows whose own mod-97 check passes. Without that check
     * a sixteen-digit account number would volunteer five candidates,
     * which is the substring search again under another name.
     *
     * Order and de-duplication are stable so a caller can report "which
     * communication did this line carry" and not just "did it carry
     * mine".
     *
     * @return list<string> distinct twelve-digit communications
     */
    public static function extract(string $text): array
    {
        // A list, not a keyed set: PHP turns "123456789012" into an
        // integer array key, and array_keys() would hand the caller back
        // ints to compare against a string.
        $found = [];

        // The grouped and bare forms. Each separator is optional and
        // independent, so a bank printing "123/4567 89012" is read the
        // same as one printing the canonical form. The lookarounds are
        // what forbid a match that starts or ends mid-run.
        if (preg_match_all('/(?<!\d)(\d{3})[\/ .\-]?(\d{4})[\/ .\-]?(\d{5})(?!\d)/', $text, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $candidate = $match[1] . $match[2] . $match[3];
                if (!in_array($candidate, $found, true)) {
                    $found[] = $candidate;
                }
            }
        }

        // A communication glued to other digits: only windows that are
        // themselves well-formed communications are candidates.
        if (preg_match_all('/\d{13,}/', $text, $runs) > 0) {
            foreach ($runs[0] as $run) {
                $last = strlen($run) - 12;
                for ($offset = 0; $offset <= $last; $offset++) {
                    $window = substr($run, $offset, 12);
                    if (self::isValid($window) && !in_array($window, $found, true)) {
                        $found[] = $window;
                    }
                }
            }
        }

        return $found;
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
