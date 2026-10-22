<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Signature;

/**
 * Where the signature put on a document came from (issue #926, lot C1):
 * the one the account keeps, or one given for this document alone — traced
 * in the dialog, or imported there.
 *
 * Recorded in the journal line of the act (`PersonalSignatureService::
 * recordSigning()`), and the reason `rememberIfAsked()` can tell a
 * signature it would only be copying onto itself.
 */
enum SignatureOrigin: string
{
    case Stored = 'stored';
    case DrawnNow = 'drawn_now';
    case ImportedNow = 'imported_now';
}
