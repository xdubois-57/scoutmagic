<?php

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
