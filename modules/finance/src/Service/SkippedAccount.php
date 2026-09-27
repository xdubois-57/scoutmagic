<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

/**
 * An account IBAN found in an imported file whose lines were set aside —
 * never written anywhere, and never the occasion to create an account.
 *
 * The IBAN is the one the uploader's own file carries, shown back to them
 * on the result page so they can add the account; it is never logged.
 */
final class SkippedAccount
{
    /** No site account carries this IBAN. */
    public const REASON_UNKNOWN = 'unknown';

    /** A site account carries it, but it is not active (draft or archived). */
    public const REASON_INACTIVE = 'inactive';

    /** A site account carries it, but the uploader may not use that account. */
    public const REASON_FORBIDDEN = 'forbidden';

    /** Several ACTIVE site accounts carry it: none is picked arbitrarily. */
    public const REASON_AMBIGUOUS = 'ambiguous';

    public function __construct(
        public readonly string $iban,
        public readonly string $reason,
        public readonly int $lineCount
    ) {
    }

    /** Grouped by four, the way the uploader reads it on their statement. */
    public function displayIban(): string
    {
        return IbanNormalizer::format($this->iban);
    }
}
