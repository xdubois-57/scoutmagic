<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Client\MailboxConnectionException;

/**
 * The one place a mailbox failure becomes a sentence somebody reads.
 *
 * **A library's own exception text is never shown.** It routinely contains
 * the account name and, on several common servers, the verbatim rejection
 * of the credential that was just tried — neither of which belongs in a
 * database column that a page renders and a backup keeps (§8.6). It is
 * READ here, to tell a certificate refusal from a dropped socket, and
 * never copied into what this returns.
 *
 * What is shown instead is what an operator can act on: which step failed,
 * which kind of failure it was, and what to check. A TLS failure in
 * particular is stated plainly rather than folded into a generic
 * "connection failed", because the two have opposite remedies and only
 * one of them is ever safe to work around — which is to say, neither.
 *
 * **The step decides first** (#1325). « Le serveur n'a pas répondu » is
 * only ever said of the connection itself: a server that accepted the
 * login and then refused to open a folder did answer, and sending the
 * operator to check the host and the port sent them the wrong way.
 */
class MailboxErrorFormatter
{
    private const SERVER_REFUSALS = ['ImapServerErrorException', 'ImapBadRequestException', 'ResponseException'];

    private const STEP_LABELS = [
        MailboxConnectionException::STEP_CONNECT => 'la connexion',
        MailboxConnectionException::STEP_LIST => 'la lecture de la liste des dossiers',
        MailboxConnectionException::STEP_OPEN_FOLDER => 'l\'ouverture d\'un dossier surveillé',
        MailboxConnectionException::STEP_FETCH => 'la lecture des messages',
    ];

    private const UNREACHABLE = 'Le serveur n\'a pas répondu. Vérifiez l\'hôte, le port et que l\'hébergement '
        . 'autorise les connexions sortantes vers ce port.';

    private const CERTIFICATE = 'Le certificat du serveur n\'a pas pu être vérifié. La connexion a été refusée : '
        . 'ScoutMagic n\'accepte jamais un certificat invalide. Vérifiez le nom d\'hôte et le '
        . 'mode de chiffrement.';

    private const AUTHENTICATION = 'Le serveur a refusé l\'authentification. Vérifiez le nom d\'utilisateur et, pour '
        . 'une boîte Gmail, utilisez un mot de passe d\'application plutôt que le mot de passe du compte. '
        . 'Outlook et Microsoft 365 refusent en général toute connexion IMAP par mot de passe.';

    private const FOLDER_NOT_FOUND = 'Un des dossiers surveillés est introuvable sur le serveur. '
        . 'Vérifiez son nom exact '
        . '— il est sensible à la casse et à la hiérarchie.';

    public function format(\Throwable $error): string
    {
        if ($error instanceof MailboxConnectionException && $error->step !== null) {
            return $this->formatStep($error, $error->step);
        }

        $needle = self::needle($error);

        if (self::mentions($needle, ['certificate', 'ssl', 'tls', 'verify'])) {
            return self::CERTIFICATE;
        }

        if (self::mentions($needle, ['auth', 'login', 'credential', 'password'])) {
            return self::AUTHENTICATION;
        }

        if (self::mentions($needle, ['timeout', 'timed out', 'refused', 'unreachable', 'resolve', 'connection'])) {
            return self::UNREACHABLE;
        }

        if (self::mentions($needle, ['folder', 'mailbox', 'not found'])) {
            return self::FOLDER_NOT_FOUND;
        }

        if ($error instanceof MailboxConnectionException) {
            return 'La boîte n\'a pas pu être lue (' . self::technical($error) . ').';
        }

        // Every IMAP failure reaches here as a MailboxConnectionException;
        // what does not is what happened to the messages once read —
        // storage, a consumer, the database.
        return 'Les messages ont été lus, mais leur traitement a échoué ('
            . MailboxConnectionException::shortClass($error) . ').';
    }

    /**
     * One line for the server's error log, where the support looks when
     * the sentence above is not enough: the step and the classes of the
     * causes, never a message (#1325).
     */
    public function diagnostic(\Throwable $error): string
    {
        if ($error instanceof MailboxConnectionException) {
            return 'step=' . ($error->step ?? 'unknown') . '; causes=' . self::technical($error);
        }

        return 'step=processing; causes=' . MailboxConnectionException::shortClass($error);
    }

    private function formatStep(MailboxConnectionException $error, string $step): string
    {
        $classes = $error->causeClasses();
        $messages = self::needle($error);
        $suffix = ' (' . self::STEP_LABELS[$step] . ', ' . self::technical($error) . ').';

        if (in_array('AuthFailedException', $classes, true)) {
            return self::AUTHENTICATION;
        }

        if ($step === MailboxConnectionException::STEP_CONNECT) {
            if (self::mentions($messages, ['certificate', 'ssl', 'tls', 'crypto'])) {
                return self::CERTIFICATE;
            }

            if (array_intersect($classes, self::SERVER_REFUSALS) !== []) {
                return 'Le serveur a répondu, mais a refusé d\'ouvrir la session' . $suffix;
            }

            return self::UNREACHABLE;
        }

        if (
            $step === MailboxConnectionException::STEP_OPEN_FOLDER
            && ($classes === [] || self::mentions($messages, ["doesn't exist", 'nonexistent', 'not found']))
        ) {
            // The folder by its name when the client said which (#1343):
            // the operator chose it, and is told which one to fix.
            return $error->folder !== null && $error->folder !== ''
                ? 'Le dossier « ' . $error->folder . ' » est introuvable sur le serveur. Vérifiez son nom '
                    . 'exact — il est sensible à la casse et à la hiérarchie — ou retirez-le des dossiers lus '
                    . 'de la boîte.'
                : self::FOLDER_NOT_FOUND;
        }

        // After a successful login: the session was cut, which is not the
        // same thing as a server that never answered.
        if (self::mentions($messages, ['empty response', 'connection closed', 'timed out', 'timeout', 'broken pipe'])) {
            return 'La connexion a été coupée par le serveur pendant ' . self::STEP_LABELS[$step]
                . ', après une connexion réussie. Réessayez ; si cela se répète, la relève dépasse '
                . 'peut-être le délai accordé par l\'hébergement' . $suffix;
        }

        if (array_intersect($classes, self::SERVER_REFUSALS) !== []) {
            return 'Le serveur a refusé une commande pendant ' . self::STEP_LABELS[$step]
                . ', après une connexion réussie. Les identifiants sont bons : vérifiez les dossiers '
                . 'surveillés et l\'accès IMAP de la boîte' . $suffix;
        }

        return 'La relève a échoué pendant ' . self::STEP_LABELS[$step]
            . ', après une connexion réussie' . $suffix;
    }

    /**
     * The class and every message down the chain, lowercased — read to
     * classify, never returned.
     */
    private static function needle(\Throwable $error): string
    {
        $parts = [];
        for ($e = $error; $e !== null && count($parts) < 6; $e = $e->getPrevious()) {
            $parts[] = $e === $error && $e instanceof MailboxConnectionException
                ? $e->getMessage()
                : $e::class . ' ' . $e->getMessage();
        }

        return strtolower(implode(' ', $parts));
    }

    private static function technical(MailboxConnectionException $error): string
    {
        $classes = $error->causeClasses();

        return $classes === [] ? 'MailboxConnectionException' : implode(' > ', $classes);
    }

    /**
     * @param string[] $needles
     */
    private static function mentions(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
