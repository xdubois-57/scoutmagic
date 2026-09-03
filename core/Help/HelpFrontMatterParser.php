<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help;

use Core\Security\Role;

/**
 * Parses a help topic's front-matter block — the lines between the two
 * `---` delimiters at the top of a docs/help/ (or module help/) Markdown
 * file.
 *
 * Deliberately narrow, same posture as Core\View\MarkdownRenderer: one
 * `key: value` pair per line, lists as comma-separated values, one
 * repeatable key (`question`, see REPEATABLE_KEYS), nothing else — not a
 * general YAML parser, which would be a new dependency (or a hand-written
 * one) for a format this project's own files never use.
 *
 * Strict on purpose: a missing field, an unknown key, an unknown
 * role_min, an id that doesn't match the file name — every one of these
 * throws instead of guessing, because the symptom of guessing (a topic
 * that silently never appears, or appears to the wrong role) is close to
 * undebuggable. Same philosophy as `visible_when` validation
 * (Core\Module\ModuleManifest). In particular Role::fromString() is never
 * used here — it silently downgrades an unknown value to PUBLIC, which
 * for a chief-only topic would be an information leak, not a typo.
 *
 * parse() reads ONLY the front-matter block (it stops at the closing
 * `---`, then just confirms a body exists) — the body itself is read
 * lazily by HelpTopic::body() via extractBody(), so that the per-request
 * "does a topic cover this page?" scan never pays for content nobody is
 * displaying.
 */
class HelpFrontMatterParser
{
    private const REQUIRED_KEYS = ['id', 'title', 'summary', 'category', 'role_min'];
    private const OPTIONAL_KEYS = ['paths', 'related', 'question'];

    /**
     * Keys that may appear SEVERAL times, one value per line, instead of
     * once with a comma-separated value.
     *
     * `question` is the only one, and the comma form was ruled out for it
     * rather than overlooked: a real question contains commas
     * (« Comment prévenir les parents, y compris ceux d'une autre
     * section ? »), so the separator `paths`/`related` use would cut a
     * question in half and neither the author nor any test would see it.
     *
     * @var string[]
     */
    private const REPEATABLE_KEYS = ['question'];

    /**
     * Ids that no topic file may claim, because a route of the same shape
     * already answers at that URL.
     *
     * Core\Http\Router::resolve() keeps the FIRST route that matches, and
     * /aide/assistant is registered before /aide/{topic} (ARCHITECTURE.md
     * §8.64) — so a topic with id 'assistant' would exist in the index, be
     * findable by search, and 404-free but unreachable: /aide/assistant
     * would render the assistant page instead of it. Refusing the id at
     * load is the only place that failure is visible.
     *
     * @var string[]
     */
    private const RESERVED_IDS = ['assistant'];

    /**
     * Ids are URL path segments (/aide/{id}) and file names at once:
     * lowercase, digits and dashes, nothing to escape anywhere.
     */
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /**
     * @throws HelpException on any malformed or invalid topic file
     */
    public function parse(string $filePath, ?string $moduleId = null): HelpTopic
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw new HelpException("Help topic file cannot be read: {$filePath}");
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false || trim($firstLine) !== '---') {
                throw new HelpException("Help topic {$filePath} must start with a '---' front-matter delimiter");
            }

            $values = [];
            $questions = [];
            $closed = false;
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '---') {
                    $closed = true;
                    break;
                }
                if ($trimmed === '') {
                    throw new HelpException("Help topic {$filePath} has an empty line inside its front matter");
                }

                $colonPos = strpos($trimmed, ':');
                if ($colonPos === false) {
                    throw new HelpException("Help topic {$filePath} front-matter line is not 'key: value': {$trimmed}");
                }

                $key = trim(substr($trimmed, 0, $colonPos));
                $value = trim(substr($trimmed, $colonPos + 1));

                if (!in_array($key, self::REQUIRED_KEYS, true) && !in_array($key, self::OPTIONAL_KEYS, true)) {
                    throw new HelpException("Help topic {$filePath} declares an unknown front-matter key '{$key}'");
                }

                if (in_array($key, self::REPEATABLE_KEYS, true)) {
                    if ($value === '') {
                        throw new HelpException("Help topic {$filePath} declares an empty '{$key}'");
                    }
                    $questions[] = $value;
                    continue;
                }

                if (isset($values[$key])) {
                    throw new HelpException("Help topic {$filePath} declares '{$key}' twice");
                }

                $values[$key] = $value;
            }

            if (!$closed) {
                throw new HelpException("Help topic {$filePath} never closes its front matter with '---'");
            }

            foreach (self::REQUIRED_KEYS as $required) {
                if (!isset($values[$required]) || $values[$required] === '') {
                    throw new HelpException("Help topic {$filePath} is missing the required field '{$required}'");
                }
            }

            // A topic with no body is a title pretending to be
            // documentation. Checked here — bounded to reading a handful
            // of lines, not the whole body — so an empty file fails at
            // load like every other defect, not silently at display.
            $hasBody = false;
            while (($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    $hasBody = true;
                    break;
                }
            }
            if (!$hasBody) {
                throw new HelpException("Help topic {$filePath} has an empty body");
            }
        } finally {
            fclose($handle);
        }

        $id = $values['id'];
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new HelpException("Help topic {$filePath} has an invalid id '{$id}' (lowercase letters, digits "
                . "and dashes only)");
        }
        if (basename($filePath) !== $id . '.md') {
            throw new HelpException("Help topic {$filePath} declares id '{$id}' but the file is not named '{$id}.md'");
        }
        if (in_array($id, self::RESERVED_IDS, true)) {
            throw new HelpException("Help topic {$filePath} claims the reserved id '{$id}' — a route of that name "
                . "answers at /aide/{$id}, so the topic would be unreachable");
        }

        $roleMin = Role::tryFrom($values['role_min']);
        if ($roleMin === null) {
            throw new HelpException("Help topic {$filePath} declares an unknown role_min '{$values['role_min']}'");
        }

        return new HelpTopic(
            id: $id,
            title: $values['title'],
            summary: $values['summary'],
            category: $values['category'],
            roleMin: $roleMin,
            paths: $this->parsePaths($filePath, $values['paths'] ?? ''),
            related: $this->parseRelated($filePath, $values['related'] ?? ''),
            questions: $questions,
            filePath: $filePath,
            moduleId: $moduleId,
        );
    }

    /**
     * The raw Markdown body: everything after the closing `---`. Called
     * lazily by HelpTopic::body(), never during the per-request scan.
     */
    public static function extractBody(string $filePath): string
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new HelpException("Help topic file cannot be read: {$filePath}");
        }

        // parse() has already validated the structure for every topic a
        // registry hands out, so a missing delimiter here means the file
        // changed on disk mid-request — treat it as the load error it is.
        if (preg_match('/^---\R(?:.*?\R)?---\R/s', $content, $m) !== 1) {
            throw new HelpException("Help topic {$filePath} has no front-matter block to strip");
        }

        return trim(substr($content, strlen($m[0])));
    }

    /**
     * Three forms.
     *
     * `/admin/import` matches that path and nothing else. `/members/*`
     * is the exact/child semantics of Core\Offline\OfflineWhitelist —
     * the path plus exactly one extra segment, so /members/12 and not
     * /members/12/emails/5. And a `*` standing for a whole segment
     * anywhere in the path — `/chefs/camps/sejours/*` /documents' — where
     * each `*` matches exactly one segment.
     *
     * The third form is what a module whose pages hang off an id needs.
     * Half of `rental`'s screens are `/mes-locations/{slug}/reglages` and
     * half of `camps`'s are `/chefs/camps/sejours/{id}/documents`; with
     * only the first two forms no rule can name them at all, so those
     * pages could never carry a contextual help button however many
     * topics were written for them.
     *
     * An empty value is valid: a purely documentary topic is only
     * reachable from /aide.
     *
     * @return array<int, array{path: string, match: string}>
     */
    private function parsePaths(string $filePath, string $raw): array
    {
        $paths = [];
        foreach ($this->splitList($raw) as $declared) {
            // A `*` anywhere but at the end: kept whole, matched segment
            // by segment by Core\Help\HelpService.
            if (str_contains($declared, '*') && !$this->isTrailingStarOnly($declared)) {
                if (preg_match('#^(?:/(?:\*|[^\s*/]+))+$#', $declared) !== 1) {
                    throw new HelpException("Help topic {$filePath} declares an invalid path '{$declared}' (a '*' "
                        . "stands for one whole segment)");
                }
                $paths[] = ['path' => $declared, 'match' => 'pattern'];
                continue;
            }

            if (str_ends_with($declared, '/*')) {
                $base = substr($declared, 0, -1); // keep the trailing slash: '/members/*' → '/members/'
                if (preg_match('#^/[^\s*]*/$#', $base) !== 1) {
                    throw new HelpException("Help topic {$filePath} declares an invalid child path '{$declared}'");
                }
                $paths[] = ['path' => $base, 'match' => 'child'];
                continue;
            }

            if (preg_match('#^/[^\s*]*$#', $declared) !== 1) {
                throw new HelpException("Help topic {$filePath} declares an invalid path '{$declared}' (must start "
                    . "with '/', '*' only as a whole segment)");
            }
            $paths[] = ['path' => $declared, 'match' => 'exact'];
        }

        return $paths;
    }

    /**
     * Whether the only `*` in a declared path is the trailing one — the
     * `/members/*` form, which keeps its own storage shape so nothing
     * that already reads it has to change.
     */
    private function isTrailingStarOnly(string $declared): bool
    {
        return str_ends_with($declared, '/*') && !str_contains(substr($declared, 0, -2), '*');
    }

    /**
     * @return string[]
     */
    private function parseRelated(string $filePath, string $raw): array
    {
        $related = [];
        foreach ($this->splitList($raw) as $id) {
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                throw new HelpException("Help topic {$filePath} declares an invalid related id '{$id}'");
            }
            $related[] = $id;
        }

        return $related;
    }

    /**
     * @return string[]
     */
    private function splitList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $v): bool => $v !== ''
        ));
    }
}
