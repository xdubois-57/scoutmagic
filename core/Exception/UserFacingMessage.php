<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Exception;

/**
 * Decides what a caught exception is allowed to say to the visitor.
 *
 * The one call to make at every display site:
 *
 *     } catch (\Throwable $e) {
 *         $this->journal->log('finance', 'receipt_upload_failed', $e->getMessage());
 *         FlashMessage::set('error', UserFacingMessage::from(
 *             $e,
 *             "Le reçu n'a pas pu être enregistré."
 *         ));
 *     }
 *
 * The message survives only when the exception implements
 * {@see UserFacingException}; anything else — a PDO error naming the
 * table, a PHPMailer SMTP transcript, a manifest path — becomes the
 * fallback the caller wrote. The real message is not lost, it goes to the
 * journal, where it belongs and where AGENTS.md's "no personal data in log
 * entries" still applies.
 *
 * Deliberately a pure function on a final class rather than a service:
 * background task handlers write these strings into a database column that
 * a template renders much later (`scheduled_actions.last_error`,
 * `backup.error_message`), and those sites have no container to resolve.
 */
final class UserFacingMessage
{
    /**
     * @param \Throwable $e        the caught exception
     * @param string     $fallback the French sentence to show when the
     *                             exception's own message is not fit for a
     *                             visitor. Say what failed and, where you
     *                             can, what to do about it.
     */
    public static function from(\Throwable $e, string $fallback): string
    {
        if (!$e instanceof UserFacingException) {
            return $fallback;
        }

        $message = trim($e->getMessage());

        return $message === '' ? $fallback : $message;
    }
}
