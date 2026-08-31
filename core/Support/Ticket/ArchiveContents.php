<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Ticket;

/**
 * What the diagnostic archive holds, in French, for the screen that asks
 * an administrator to agree to transmit it (roadmap IT-26).
 *
 * **A consent screen that cannot say what it is about is not a consent
 * screen.** The collectors' own `name()` is a technical identifier that
 * appears in `collection-status.json` and nowhere a person reads, so the
 * sentence a human ticks a box beside lives here.
 *
 * Kept as a map rather than as a `label()` on
 * `Core\Support\SupportCollectorInterface`, deliberately: fifteen
 * implementations would each gain a line that only one screen ever reads,
 * and the contract stays about collecting. What keeps the two in step is
 * `Tests\Core\Support\Ticket\ArchiveContentsTest`, which fails the build
 * when a shipped collector has no sentence here — so a new collector
 * arrives with its French description or not at all.
 */
final class ArchiveContents
{
    /**
     * @var array<string, string> collector name => what it contains
     */
    private const DESCRIPTIONS = [
        'statistics' => "Les compteurs agrégés du site : version, modules actifs, effectifs. Aucune donnée de membre.",
        'database_structure' => 'La structure des tables — jamais leur contenu.',
        'configuration_parameters' => "Les paramètres de configuration du site, secrets retirés.",
        'event_journal' => "Un résumé du journal des évènements des dernières 48 h, qui contient des identifiants internes.",
        'scheduled_tasks' => "L'état des tâches planifiées du site.",
        'update_history' => 'Quelle version tournait à quel moment, et le résultat de chaque mise à jour.',
        'phpinfo' => "La configuration détaillée de PHP sur votre hébergement (sans les variables d'environnement).",
        'extensions' => 'La liste des extensions PHP disponibles et leurs versions.',
        'opcache' => "L'état du cache de code compilé.",
        'filesystem' => "L'arborescence de storage/ : des noms de fichiers déposés, jamais les fichiers eux-mêmes.",
        'commands' => 'Les outils système disponibles sur le serveur.',
        'background_execution' => "Comment les tâches de fond s'exécutent sur cet hébergement.",
        'cron_cadence' => "À quelle cadence le déclencheur horaire s'exécute réellement.",
        'webserver' => 'Le serveur web utilisé et ses réglages visibles.',
        'logs' => "Les journaux du serveur web, qui contiennent des adresses IP de visiteurs.",
    ];

    /**
     * The list to show, in the order the archive is built.
     *
     * @param list<string> $collectorNames
     * @return list<array{name: string, description: string}>
     */
    public static function describe(array $collectorNames): array
    {
        $described = [];
        foreach ($collectorNames as $name) {
            $described[] = [
                'name' => $name,
                // An unknown collector is named rather than hidden: a line
                // saying « nous ne savons pas décrire ceci » is honest, and
                // silently omitting one would make the consent screen a
                // partial list of what leaves.
                'description' => self::DESCRIPTIONS[$name]
                    ?? 'Rubrique technique non décrite dans cette version du site.',
            ];
        }

        return $described;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::DESCRIPTIONS;
    }
}
