<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Exception;

/**
 * Marks an exception whose message is written FOR the visitor and may be
 * shown to them verbatim.
 *
 * A survey of this codebase found 146 places where a caught exception's
 * message reached a flash message, a JSON body or a Twig variable. Most
 * were fine — « Ce badge est déjà attribué à un membre — désactivez-le au
 * lieu de le supprimer. » is exactly what the visitor needs. But the same
 * line of code also displayed `"Module manifest not found: /var/www/…"`,
 * `"Setting 'x' is not editable."` and raw PHPMailer SMTP text, because
 * nothing in the type system distinguished the two kinds of message.
 *
 * This interface is that distinction, and it is deliberately a marker with
 * no methods: implementing it is a claim about every message the class is
 * ever constructed with, checked by
 * `tests/Core/Exception/UserFacingExceptionTest.php`. Before adding it to
 * a class, read every `throw new` of that class and ask of each message:
 *
 * - Is it French, and a full sentence? (SECURITY.md §5)
 * - Does it name only things the visitor can see — a field, an album, a
 *   booking — and never a file path, a SQL fragment, a class name, a
 *   library's own words?
 * - Does it say what to do next, or at least why the thing was refused?
 *
 * The trap to watch for is laundering: a class that satisfies all three at
 * every `throw` site can still be constructed from `$e->getMessage()` of
 * something that satisfies none of them. `throw new XException(
 * $e->getMessage(), 0, $e)` re-labels a technical message as user-facing
 * and defeats this interface entirely — write a French sentence at the
 * wrap site instead, and let the cause carry the detail to the journal.
 *
 * Anything NOT marked is displayed through
 * {@see UserFacingMessage::from()}, which substitutes a fallback the
 * caller wrote.
 */
interface UserFacingException extends \Throwable
{
}
