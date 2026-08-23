<?php

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Security\SessionStore;

/**
 * What the "demande envoyée" page shows, held between the POST that
 * created the request and the GET that confirms it.
 *
 * The confirmation used to be rendered as the POST's own response. That
 * leaves the browser sitting on a POST: pressing F5 — or « Recharger »
 * after losing the connection, or the back button then forward — re-posts
 * the whole form. What the family sees is their browser's "Confirmer le
 * renvoi du formulaire ?" dialog, and what the unit gets is a second
 * inscription for the same child, indistinguishable from a real one.
 *
 * A capability token in the URL would be the wrong shape here: there is
 * nothing to authorise. Nobody is meant to reach this page other than the
 * browser that just submitted, it says nothing that is not already in the
 * acknowledgement email, and the real tracking link — the one that IS a
 * capability — is in that email precisely so it does not sit in a URL bar
 * on a shared family computer.
 *
 * Deliberately not consumed on read: a refresh of the confirmation page
 * must confirm again, which is the entire point. It expires instead, so a
 * tab reopened from history tomorrow lands on the registration page rather
 * than on a « Merci ! » for a request from last week.
 */
class RegistrationSubmissionReceipt
{
    private const SESSION_KEY = '_registration_submission_receipt';

    /**
     * Long enough for a family to read the page, come back to it after
     * checking their inbox, and reload it a few times; short enough that
     * it is gone by the next visit.
     */
    private const LIFETIME_SECONDS = 1800;

    public static function remember(string $childFirstName, int $requestId, ?int $now = null): void
    {
        SessionStore::set(self::SESSION_KEY, [
            'child_first_name' => $childFirstName,
            'request_id' => $requestId,
            'at' => $now ?? time(),
        ]);
    }

    /**
     * @return array{child_first_name: string, request_id: int}|null
     */
    public static function read(?int $now = null): ?array
    {
        $stored = SessionStore::get(self::SESSION_KEY);
        if (!is_array($stored) || !isset($stored['child_first_name'], $stored['request_id'], $stored['at'])) {
            return null;
        }

        if (($now ?? time()) - (int) $stored['at'] > self::LIFETIME_SECONDS) {
            self::forget();

            return null;
        }

        return [
            'child_first_name' => (string) $stored['child_first_name'],
            'request_id' => (int) $stored['request_id'],
        ];
    }

    public static function forget(): void
    {
        SessionStore::remove(self::SESSION_KEY);
    }
}
