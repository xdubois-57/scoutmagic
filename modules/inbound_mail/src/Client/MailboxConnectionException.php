<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

use Core\Exception\UserFacingException;

/**
 * A mailbox could not be reached or read.
 *
 * The message is written for a superadmin looking at the configuration
 * page, and is built by `Service\MailboxErrorFormatter` — never by
 * interpolating a library's own exception text, which routinely contains
 * the account name and, on some servers, the credential that was rejected.
 *
 * **It says which step failed** (#1325). Every IMAP failure used to arrive
 * here under the same class whatever it was, and the formatter, reading
 * « connection » in that class name, called all of them a server that did
 * not answer — an EXAMINE refused after a successful login included. The
 * step is set where the failure is caught, by the one class that knows
 * which command it was running; null means a caller that did not say.
 */
class MailboxConnectionException extends \RuntimeException implements UserFacingException
{
    /** Opening the session: socket, TLS and LOGIN. */
    public const STEP_CONNECT = 'connect';

    /** The LIST of folders, after a successful login. */
    public const STEP_LIST = 'list';

    /** Finding and opening (EXAMINE) one watched folder. */
    public const STEP_OPEN_FOLDER = 'open_folder';

    /** The UID search and FETCH of the messages above the cursor. */
    public const STEP_FETCH = 'fetch';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $step = null,
        /**
         * The watched folder the step was about, as the box's
         * configuration names it (#1343): the operator typed or chose it,
         * so saying it back tells them which one to fix. Null for a step
         * that concerns no single folder.
         */
        public readonly ?string $folder = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The failure of $step, caused by $cause. The message is the cause's
     * short class name and nothing it wrote, as before: it reaches no
     * page, only the formatter that turns it into a sentence.
     */
    public static function at(string $step, \Throwable $cause, ?string $folder = null): self
    {
        return new self(self::shortClass($cause), 0, $cause, $step, $folder);
    }

    /**
     * The short class names of the exceptions under this one, outermost
     * first — what the support needs to tell an authentication refusal
     * from a dropped socket, and never a message, which can quote the
     * account or the server's answer.
     *
     * @return string[]
     */
    public function causeClasses(): array
    {
        $classes = [];
        for ($cause = $this->getPrevious(); $cause !== null && count($classes) < 5; $cause = $cause->getPrevious()) {
            $classes[] = self::shortClass($cause);
        }

        return $classes;
    }

    public static function shortClass(\Throwable $error): string
    {
        $class = $error::class;
        // A class in the global namespace has no backslash at all, and
        // `strrpos()` answers false there: cast to 0, plus one, the name
        // would lose its first letter (« untimeException »).
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }
}
