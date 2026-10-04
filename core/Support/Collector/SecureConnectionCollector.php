<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Config\AppConfig;
use Core\Http\InsecureBrowserAccess;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `connexion-securisee.txt` — the « connexion non sécurisée » state, as
 * the attention point, the notification and Santé de l'hébergement show it
 * (#751): the same {@see InsecureBrowserAccess} reading, never a scheme of
 * its own.
 *
 * Only in the package: the Diagnostic page itself does not display it.
 * Beside the state, the one deployment fact support needs to read it —
 * whether `https_required` is on in config/app.php — because an
 * installation that tolerates HTTP never records anything.
 */
class SecureConnectionCollector implements SupportCollectorInterface
{
    public function __construct(private readonly ?int $now = null)
    {
    }

    public function name(): string
    {
        return 'secure_connection';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $now = $this->now ?? time();
        $access = new InsecureBrowserAccess($context->settings());
        $last = $access->lastObservedAt();
        $active = $access->isActive($now);

        $lines = [];
        $lines[] = '# Connexion sécurisée';
        $lines[] = '#';
        $lines[] = '# Observé par les navigateurs des utilisateurs connectés, jamais déduit du schéma';
        $lines[] = '# vu par PHP (qui, derrière un terminateur TLS, est HTTP sur un site en HTTPS).';
        $lines[] = '';
        $lines[] = 'https_required (config/app.php) : ' . $this->httpsRequired($context);
        $lines[] = InsecureBrowserAccess::SETTING . ' : ' . ($last === null
            ? '0 (jamais)'
            : $last . ' — ' . date('Y-m-d H:i:s', $last) . ' (' . InsecureBrowserAccess::ago($last, $now) . ')');
        $lines[] = 'État : ' . ($active
            ? 'ACTIF jusqu\'au ' . date('Y-m-d H:i:s', (int) $access->activeUntil($now))
            : 'sain');
        $lines[] = 'Règle : actif pendant ' . InsecureBrowserAccess::ACTIVE_HOURS
            . ' h après le dernier accès non sécurisé observé.';

        if ($active && $last !== null) {
            $context->addNote(
                'ScoutMagic a récemment été consulté depuis une connexion non sécurisée ('
                . InsecureBrowserAccess::ago($last, $now) . ').'
            );
        }

        $context->addFileFromContent('connexion-securisee.txt', implode("\n", $lines) . "\n");
    }

    private function httpsRequired(SupportCollectorContext $context): string
    {
        try {
            $config = new AppConfig($context->projectRoot() . '/config/app.php');
        } catch (\RuntimeException) {
            return 'inconnu (config/app.php introuvable)';
        }

        // A file that predates the key gets the production policy, as in
        // public/index.php.
        return $config->get('https_required', true) !== false
            ? 'oui'
            : 'non — HTTP toléré (développement ou test)';
    }
}
