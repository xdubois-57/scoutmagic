<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

/**
 * The one place a mailbox failure becomes a sentence somebody reads.
 *
 * **A library's own exception text is never shown.** It routinely contains
 * the account name and, on several common servers, the verbatim rejection
 * of the credential that was just tried — neither of which belongs in a
 * database column that a page renders and a backup keeps (§7.9).
 *
 * What is shown instead is what an operator can act on: which kind of
 * failure it was, and what to check. A TLS failure in particular is stated
 * plainly rather than folded into a generic "connection failed", because
 * the two have opposite remedies and only one of them is ever safe to work
 * around — which is to say, neither.
 */
class MailboxErrorFormatter
{
    public function format(\Throwable $error): string
    {
        $needle = strtolower($error::class . ' ' . $error->getMessage());

        if (self::mentions($needle, ['certificate', 'ssl', 'tls', 'verify'])) {
            return 'Le certificat du serveur n\'a pas pu être vérifié. La connexion a été refusée : '
                . 'ScoutMagic n\'accepte jamais un certificat invalide. Vérifiez le nom d\'hôte et le '
                . 'mode de chiffrement.';
        }

        if (self::mentions($needle, ['auth', 'login', 'credential', 'password'])) {
            return 'Le serveur a refusé l\'authentification. Vérifiez le nom d\'utilisateur et, pour '
                . 'une boîte Gmail ou Outlook, utilisez un mot de passe d\'application plutôt que le '
                . 'mot de passe du compte.';
        }

        if (self::mentions($needle, ['timeout', 'timed out', 'refused', 'unreachable', 'resolve', 'connection'])) {
            return 'Le serveur n\'a pas répondu. Vérifiez l\'hôte, le port et que l\'hébergement '
                . 'autorise les connexions sortantes vers ce port.';
        }

        if (self::mentions($needle, ['folder', 'mailbox', 'not found'])) {
            return 'Un des dossiers surveillés est introuvable sur le serveur. Vérifiez son nom exact '
                . '— il est sensible à la casse et à la hiérarchie.';
        }

        return 'La synchronisation a échoué pour une raison technique (' . self::shortClass($error) . ').';
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

    private static function shortClass(\Throwable $error): string
    {
        $class = $error::class;
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }
}
