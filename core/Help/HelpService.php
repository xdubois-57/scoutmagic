<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help;

use Core\Security\Role;

/**
 * The one and only layer that filters help topics by role. Below its
 * role_min a topic exists nowhere: not in the contextual panel
 * (findForPath), not in the /aide index or search (listForRole/search),
 * not by direct URL (findById returns null and HelpController answers
 * 404 — never 403, which would confirm the topic exists; same posture as
 * the groups module's 404s, SECURITY.md §19).
 *
 * Everything here reads front matter only (HelpRegistry's lazy scan) —
 * a body is read exactly when a topic is displayed, never to decide
 * whether it could be.
 */
class HelpService
{
    /**
     * Display order for the /aide index's category headings. Purely a
     * presentation preference: a category absent from this list still
     * renders, alphabetically after the known ones — a topic author never
     * has to touch code to introduce one (locked decision 3). The names
     * follow design.md §7.1's lexicon and MenuBuilder's menu labels.
     */
    private const CATEGORY_ORDER = [
        'Premiers pas',
        'Espace membres',
        'Espace animateurs',
        "Espace chefs d'U",
        'Configuration',
    ];

    public function __construct(private readonly HelpRegistry $registry)
    {
    }

    /**
     * Topics the registry had to drop this request, one sentence each.
     *
     * Not role-filtered here, unlike everything else in this class: there
     * is no topic to have a role_min for — the file did not parse. The
     * caller decides who is allowed to see it, and only Core\Http\
     * Controller\HelpController does, for a superadmin, on /aide. A
     * healthy installation returns an empty list on every request.
     *
     * @return string[]
     */
    public function loadErrors(): array
    {
        return $this->registry->loadErrors();
    }

    /**
     * The topics covering one page, for the help button and panel.
     * Order: exact matches before child matches, then alphabetical by id
     * — so on a page several topics cover, the most specific one leads.
     *
     * @return HelpTopic[]
     */
    public function findForPath(string $path, Role $role): array
    {
        $exact = [];
        $child = [];
        foreach ($this->visibleTopics($role) as $topic) {
            $match = self::bestMatch($topic, $path);
            if ($match === 'exact') {
                $exact[] = $topic;
            } elseif ($match === 'child') {
                $child[] = $topic;
            }
        }

        usort($exact, self::byId(...));
        usort($child, self::byId(...));

        return array_merge($exact, $child);
    }

    /**
     * Null for an unknown id AND for a topic below the caller's role —
     * deliberately indistinguishable.
     */
    public function findById(string $id, Role $role): ?HelpTopic
    {
        $topic = $this->registry->all()[$id] ?? null;
        if ($topic === null || !$role->hasAccess($topic->roleMin)) {
            return null;
        }

        return $topic;
    }

    /**
     * Every topic the role may see, grouped by category for the /aide
     * index. Categories follow CATEGORY_ORDER, unknown ones alphabetical
     * after; topics sort by title within a category.
     *
     * @return array<string, HelpTopic[]>
     */
    public function listForRole(Role $role): array
    {
        return $this->group($this->visibleTopics($role));
    }

    /**
     * listForRole() narrowed by a search over title, summary, category and
     * the topic's declared `question`s — case- and accent-insensitive, so
     * "medaille" finds "Médaille".
     *
     * This is the `?q=` form on /aide — the search that must keep working
     * with scripting off. It stays a plain substring filter on purpose:
     * ranking belongs to one implementation, and a second one here would
     * drift from it silently.
     *
     * @return array<string, HelpTopic[]>
     */
    public function search(string $query, Role $role): array
    {
        $needle = self::normalize(trim($query));
        if ($needle === '') {
            return $this->listForRole($role);
        }

        $matching = array_filter(
            $this->visibleTopics($role),
            static fn (HelpTopic $t): bool =>
                str_contains(self::normalize($t->title), $needle)
                || str_contains(self::normalize($t->summary), $needle)
                || str_contains(self::normalize($t->category), $needle)
                || self::anyQuestionContains($t, $needle)
        );

        return $this->group(array_values($matching));
    }

    /**
     * The `question` lines are searched like any other field: they exist
     * precisely to carry the words someone types when the title uses
     * different ones (« publipostage » vs « mail personnalisé »).
     */
    private static function anyQuestionContains(HelpTopic $topic, string $needle): bool
    {
        foreach ($topic->questions as $question) {
            if (str_contains(self::normalize($question), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The resolved, displayable `related` topics of one topic: unknown
     * ids are ignored, out-of-role topics filtered — exactly the "un id
     * inconnu est ignoré, un sujet hors-rôle est filtré à l'affichage"
     * contract of the front-matter format.
     *
     * @return HelpTopic[]
     */
    public function relatedTopics(HelpTopic $topic, Role $role): array
    {
        $related = [];
        foreach ($topic->related as $id) {
            $candidate = $this->findById($id, $role);
            if ($candidate !== null) {
                $related[] = $candidate;
            }
        }

        return $related;
    }

    /**
     * @return HelpTopic[]
     */
    private function visibleTopics(Role $role): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            static fn (HelpTopic $t): bool => $role->hasAccess($t->roleMin)
        ));
    }

    /**
     * @param HelpTopic[] $topics
     * @return array<string, HelpTopic[]>
     */
    private function group(array $topics): array
    {
        $grouped = [];
        foreach ($topics as $topic) {
            $grouped[$topic->category][] = $topic;
        }

        foreach ($grouped as &$categoryTopics) {
            usort($categoryTopics, static fn (HelpTopic $a, HelpTopic $b): int => strcasecmp($a->title, $b->title));
        }
        unset($categoryTopics);

        uksort($grouped, static function (string $a, string $b): int {
            $rankA = array_search($a, self::CATEGORY_ORDER, true);
            $rankB = array_search($b, self::CATEGORY_ORDER, true);
            $rankA = $rankA === false ? PHP_INT_MAX : $rankA;
            $rankB = $rankB === false ? PHP_INT_MAX : $rankB;

            return $rankA <=> $rankB ?: strcasecmp($a, $b);
        });

        return $grouped;
    }

    /**
     * 'exact' when any declared path matches the request path literally,
     * 'child' when a child rule covers it (parent path plus exactly one
     * extra segment — Core\Offline\OfflineWhitelist's semantics) or a
     * segment pattern matches it, null otherwise.
     */
    private static function bestMatch(HelpTopic $topic, string $path): ?string
    {
        $best = null;
        foreach ($topic->paths as $rule) {
            if ($rule['match'] === 'exact') {
                if ($path === $rule['path']) {
                    return 'exact';
                }
                continue;
            }

            if ($rule['match'] === 'pattern') {
                if (self::segmentsMatch($rule['path'], $path)) {
                    $best = 'child';
                }
                continue;
            }

            if (!str_starts_with($path, $rule['path'])) {
                continue;
            }
            $remainder = trim(substr($path, strlen($rule['path'])), '/');
            if ($remainder !== '' && !str_contains($remainder, '/')) {
                $best = 'child';
            }
        }

        return $best;
    }

    /**
     * A declared path whose `*` segments each stand for exactly one
     * segment of the request path — `/mes-locations/*` /reglages' against
     * `/mes-locations/le-chalet/reglages`.
     *
     * Same number of segments on both sides, deliberately: a rule for a
     * page must not also claim the pages under it, which is the whole
     * reason the `/x/*` form counts segments rather than prefixing.
     */
    private static function segmentsMatch(string $rule, string $path): bool
    {
        $ruleSegments = explode('/', trim($rule, '/'));
        $pathSegments = explode('/', trim($path, '/'));

        if (count($ruleSegments) !== count($pathSegments)) {
            return false;
        }

        foreach ($ruleSegments as $index => $segment) {
            if ($segment !== '*' && $segment !== $pathSegments[$index]) {
                return false;
            }
        }

        return true;
    }

    private static function byId(HelpTopic $a, HelpTopic $b): int
    {
        return strcmp($a->id, $b->id);
    }

    /**
     * Lowercased, French accents folded — a fixed table rather than a
     * locale-dependent iconv//TRANSLIT, whose output varies by platform.
     */
    private static function normalize(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae',
        ]);
    }
}
