<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\OperationalCheck;
use Core\Http\InsecureBrowserAccess;

/**
 * Has ScoutMagic recently been loaded over a connection that is not
 * secure?
 *
 * **What is observed changed, and that is the whole fix (#751).** This
 * check used to read the scheme PHP saw on the request evaluating it.
 * On a host that terminates TLS in front of PHP — most shared hosting —
 * that is plain HTTP on a site every visitor reaches over HTTPS, so the
 * alert was raised on exactly the installations that were fine (#352),
 * and the advice it gave was a server setting their administrator could
 * not reach. The browser is the one witness that knows how a page was
 * loaded; it reports it, and {@see InsecureBrowserAccess} keeps the date
 * of the last insecure observation. This class only reads that date.
 *
 * **The gap is time, as before.** Triggered while an insecure observation
 * is less than {@see InsecureBrowserAccess::ACTIVE_HOURS} old, re-armed
 * once it is older. A site reached both ways keeps re-stamping and stays
 * triggered, which is the correct answer; a site whose certificate is
 * repaired clears by itself a day later, with nothing to edit.
 *
 * Still evaluated on web requests ({@see \Core\Alert\RequestBoundChecks})
 * rather than in the daily task: a reading that changes state at a
 * precise hour would otherwise wait up to a day to be noticed.
 */
final class HttpsCheck implements OperationalCheck
{
    public const KEY = 'https_absent';

    /**
     * Where this alert sends the administrator, on **both** surfaces —
     * constants because the notification ({@see read()}) and the attention
     * page ({@see \Core\Alert\AlertSurfaces::destinations()}) are built
     * from different sides and would otherwise drift apart.
     */
    public const HELP_PATH = '/aide/connexion-securisee';

    public const HELP_LABEL = 'Comprendre cette alerte';

    public const TITLE = 'ScoutMagic a récemment été consulté depuis une connexion non sécurisée.';

    /** The cause, said in one line — what the attention page has room for. */
    public const ATTENTION_WHY = 'Un navigateur a chargé le site hors HTTPS au cours des dernières 24 heures. '
        . 'Vérifiez que le certificat est actif et que l\'adresse en http:// renvoie vers https://. '
        . 'L\'alerte s\'éteint seule après une journée sans nouvel accès non sécurisé.';

    public function __construct(
        private readonly InsecureBrowserAccess $access,
        private readonly ?\DateTimeImmutable $now = null
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Connexion sécurisée';
    }

    public function read(): AlertReading
    {
        $now = ($this->now ?? new \DateTimeImmutable())->getTimestamp();
        $last = $this->access->lastObservedAt();

        if ($last === null) {
            // Never observed. An empty value is also what
            // OperationalAlertService refuses to write over a figure a
            // still-triggered alert is showing.
            return $this->reading(false, true, '');
        }

        $active = $this->access->isActive($now);

        return $this->reading($active, !$active, self::value($last, $now));
    }

    /** « dernier accès non sécurisé il y a 3 h » — shared with the other surfaces. */
    public static function value(int $last, int $now): string
    {
        return 'dernier accès non sécurisé ' . InsecureBrowserAccess::ago($last, $now);
    }

    private function reading(bool $overTrigger, bool $underRearm, string $value): AlertReading
    {
        return new AlertReading(
            overTrigger: $overTrigger,
            underRearm: $underRearm,
            value: $value,
            title: self::TITLE,
            why: 'Un navigateur a chargé ScoutMagic hors connexion sécurisée au cours des dernières '
                . '24 heures : les mots de passe et les données des membres ont pu circuler en clair. '
                . 'Vérifiez que le certificat HTTPS est actif chez votre hébergeur et que l\'adresse '
                . 'en http:// renvoie vers https://. L\'alerte s\'éteint seule après une journée '
                . 'sans nouvel accès non sécurisé.',
            actionUrl: self::HELP_PATH,
            actionLabel: self::HELP_LABEL
        );
    }
}
