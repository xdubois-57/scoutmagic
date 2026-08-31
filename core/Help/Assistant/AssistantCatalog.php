<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help\Assistant;

use Core\Help\HelpService;
use Core\Help\HelpTopic;
use Core\Security\Role;

/**
 * The list of topics the selection step chooses from, as one line each:
 * `id | titre | résumé | questions`.
 *
 * **The role filter is the catalogue, never an instruction in the
 * prompt.** It is built from HelpService::listForRole(), never from
 * HelpRegistry::all(): a topic above the caller's role is not in the text
 * the model receives, so there is nothing for it to disclose, ignore or
 * be talked out of. A prompt saying "only answer about topics the user
 * may see" would be a request; a list the model never gets is a fact.
 *
 * Bodies are not here. The catalogue exists so the CHEAP tier can pick a
 * handful of ids out of ~120 lines; the bodies of those few are read
 * afterwards, for the answering step. Sending 120 bodies would be the
 * whole corpus in every request, for a choice that front matter already
 * supports — which is exactly what the `question` lines were written for.
 */
final class AssistantCatalog
{
    public function __construct(private readonly HelpService $helpService)
    {
    }

    /**
     * One line per topic the role may see, in /aide's own category order.
     *
     * Pipes separate the fields and are stripped from the values, so a
     * title containing one cannot make a topic read as two.
     */
    public function forRole(Role $role): string
    {
        $lines = [];
        foreach ($this->helpService->listForRole($role) as $topics) {
            foreach ($topics as $topic) {
                $lines[] = self::line($topic);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The ids this catalogue actually contains — what a selection is
     * checked against before anything else happens to it.
     *
     * Not a substitute for re-resolving each id through
     * HelpService::findById() (AssistantService does that, and it is the
     * role gate): this is the cheap first pass that drops an id the model
     * invented outright.
     *
     * @return string[]
     */
    public function idsForRole(Role $role): array
    {
        $ids = [];
        foreach ($this->helpService->listForRole($role) as $topics) {
            foreach ($topics as $topic) {
                $ids[] = $topic->id;
            }
        }

        return $ids;
    }

    private static function line(HelpTopic $topic): string
    {
        return implode(' | ', [
            $topic->id,
            self::clean($topic->title),
            self::clean($topic->summary),
            self::clean(implode(' ', $topic->questions)),
        ]);
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace('|', ' ', $value)));
    }
}
