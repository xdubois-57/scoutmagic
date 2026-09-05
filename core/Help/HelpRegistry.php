<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help;

/**
 * The single aggregation point for help topics — same shape as
 * Core\Offline\OfflineWhitelist, Core\Notification\NotificationRegistry
 * and Core\Cookie\CookieRegistry: core topics live in docs/help/ (the
 * directory this class is constructed with), module topics are registered
 * by Core\Module\ModuleManager::loadModule() from each enabled module's
 * own help/ directory. A disabled module's topics are simply never
 * registered — nothing to clean up, nothing to filter later.
 *
 * Loading is lazy and front-matter-only: the first consumer of all()
 * triggers one scan that parses each file's front-matter block and stops
 * there (HelpFrontMatterParser::parse() never reads the body). Bodies are
 * read one at a time by HelpTopic::body() when a topic is actually
 * displayed. No cache in front of this — with the current corpus a scan
 * is a few dozen sub-kilobyte reads; the serialized-index escape hatch
 * (storage/core/help/, keyed on version + active modules) is documented
 * in ARCHITECTURE.md §8.64 and deliberately not built until the corpus
 * warrants it (~100+ topics).
 *
 * An id collision — two files, core or module, declaring the same id —
 * keeps the first and reports the second through loadErrors(), rather
 * than last-one-wins: which file survives must not depend on module load
 * order. The cross-corpus uniqueness invariant is also pinned by
 * tests/Core/Help/, so a collision is caught long before a release.
 *
 * Nothing this class does can take a page down: a file it cannot parse,
 * or a second file claiming an id already taken, costs that one topic and
 * is recorded in loadErrors(). See load() for why that matters more here
 * than the usual "fail loudly" instinct.
 *
 * Role filtering deliberately does NOT happen here: HelpService is the
 * single layer that filters by role, so there is exactly one place to
 * audit for the "below role_min a topic exists nowhere" guarantee.
 */
class HelpRegistry
{
    /** @var array<string, string> moduleId => absolute topics directory */
    private array $moduleDirectories = [];

    /** @var ?array<string, HelpTopic> id => topic, lazily built */
    private ?array $topics = null;

    /** @var string[] one sentence per topic the last load() had to drop */
    private array $loadErrors = [];

    public function __construct(
        private readonly string $coreDirectory,
        private readonly HelpFrontMatterParser $parser = new HelpFrontMatterParser(),
        // The serialized-index escape hatch ARCHITECTURE.md §8.64 planned
        // for when the corpus passed ~100 topics — it has (102 files,
        // each opened on every GET before this existed). Both optional
        // and trailing, so tests and callers that predate the cache keep
        // the plain scan: $cacheDirectory is where the index lives
        // (storage/core/help), $installedVersion is half of its key. A
        // 'dev' version disables caching entirely, for the same reason
        // TwigFactory keeps auto_reload on: a checkout-deployed site
        // whose version never changes would serve a stale index forever.
        private readonly ?string $cacheDirectory = null,
        private readonly ?string $installedVersion = null,
    ) {
    }

    /**
     * Called by Core\Module\ModuleManager::loadModule() for every enabled
     * module whose help directory exists — never by application code.
     */
    public function registerModuleTopics(string $moduleId, string $directory): void
    {
        $this->moduleDirectories[$moduleId] = $directory;
        $this->topics = null;
    }

    /**
     * Every declared topic, core + modules, unfiltered by role, keyed by
     * id. Consumers other than HelpService and tests have no business
     * calling this — the role filter lives there.
     *
     * @return array<string, HelpTopic>
     */
    public function all(): array
    {
        if ($this->topics === null) {
            $cached = $this->loadFromCache();
            if ($cached !== null) {
                [$this->topics, $this->loadErrors] = $cached;
            } else {
                $this->topics = $this->load();
                $this->writeCache();
            }
        }

        return $this->topics;
    }

    /**
     * The serialized index, if one exists for exactly this installation
     * state — keyed on installed version + registered module set, per
     * ARCHITECTURE.md §8.64. Anything unexpected (missing file, stale
     * key, unserializable content) is a miss, never an error: the plain
     * scan is always available behind it.
     *
     * @return array{0: array<string, HelpTopic>, 1: string[]}|null
     */
    private function loadFromCache(): ?array
    {
        if (!$this->cacheUsable()) {
            return null;
        }

        $data = $this->cacheFile()->read(function (mixed $data): bool {
            if (!is_array($data)
                || ($data['key'] ?? null) !== $this->cacheKey()
                || !is_array($data['topics'] ?? null)
                || !is_array($data['errors'] ?? null)
            ) {
                return false;
            }
            foreach ($data['topics'] as $topic) {
                if (!$topic instanceof HelpTopic) {
                    return false;
                }
            }

            return true;
        });

        return is_array($data) ? [$data['topics'], $data['errors']] : null;
    }

    private function writeCache(): void
    {
        if (!$this->cacheUsable()) {
            return;
        }

        $this->cacheFile()->write([
            'key' => $this->cacheKey(),
            'topics' => $this->topics,
            'errors' => $this->loadErrors,
        ]);
    }

    private function cacheFile(): \Core\Cache\SerializedFileCache
    {
        return new \Core\Cache\SerializedFileCache(
            $this->cacheFilePath(),
            [HelpTopic::class, \Core\Security\Role::class]
        );
    }

    /**
     * A version of 'dev' never changes on a checkout deployment, so it
     * would pin a stale index forever — same rationale as TwigFactory's
     * always-on auto_reload.
     */
    /**
     * The directory derived data about this corpus may be memoized in, or
     * null when nothing may be cached (a checkout, no numbered VERSION —
     * the same rule as the registry's own index cache).
     */
    public function cacheDirectory(): ?string
    {
        return $this->cacheUsable() ? $this->cacheDirectory : null;
    }

    private function cacheUsable(): bool
    {
        return $this->cacheDirectory !== null
            && $this->installedVersion !== null
            && $this->installedVersion !== ''
            && $this->installedVersion !== 'dev'
            && !str_starts_with($this->installedVersion, 'dev-');
    }

    private function cacheKey(): string
    {
        $moduleIds = array_keys($this->moduleDirectories);
        sort($moduleIds);

        return $this->installedVersion . '|' . implode(',', $moduleIds);
    }

    private function cacheFilePath(): string
    {
        return $this->cacheDirectory . '/help-index.cache';
    }

    /**
     * Why a broken topic is dropped rather than thrown out of:
     *
     * Help is a decorative feature — a button, a panel, an index at
     * /aide. Loading it, however, happens on the hot path: Core\Http\
     * FrontController builds the panel on EVERY GET, including API
     * endpoints, so an exception escaping here is not "the help is
     * broken", it is "the site is down", for every visitor and every
     * route at once. That is not a hypothetical: one topic gaining a
     * `paths` form its parser did not yet understand returned 500 site-
     * wide until the next request compiled the new parser.
     *
     * A malformed file therefore costs exactly its own topic. The rest of
     * the corpus loads, every page still answers, and the failure is kept
     * — named, with its reason — in loadErrors() for /aide to show an
     * administrator and for the support archive to carry to whoever is
     * asked about it. The shipped corpus is validated in CI
     * (tests/Core/Help/HelpInvariantsTest), so a topic that lands here in
     * production means something the invariants cannot see: a half-copied
     * update, a truncated file, a hosting quirk. None of those are worth a
     * white page.
     *
     * @return array<string, HelpTopic>
     */
    private function load(): array
    {
        $topics = [];
        $this->loadErrors = [];

        foreach ($this->listTopicFiles($this->coreDirectory) as $file) {
            $this->addParsed($topics, $file, null);
        }

        foreach ($this->moduleDirectories as $moduleId => $directory) {
            foreach ($this->listTopicFiles($directory) as $file) {
                $this->addParsed($topics, $file, $moduleId);
            }
        }

        return $topics;
    }

    /**
     * One file's worth of the scan, with the failure of that one file
     * contained. error_log() rather than the journal: this runs on every
     * request, and a corpus broken for an hour would write a journal row
     * per page load — the administrative log is not a place to shout from
     * a loop. The server error log is where Core\Http\ErrorHandler already
     * writes, and where Core\Support's archive already looks.
     *
     * @param array<string, HelpTopic> $topics
     */
    private function addParsed(array &$topics, string $file, ?string $moduleId): void
    {
        try {
            $this->add($topics, $this->parser->parse($file, $moduleId));
        } catch (HelpException $e) {
            $this->loadErrors[] = $e->getMessage();
            error_log('ScoutMagic help topic ignored: ' . $e->getMessage());
        }
    }

    /**
     * Whatever the last load() could not use, one sentence each, in scan
     * order. Empty on a healthy installation — which is the assertion the
     * invariant test makes about the shipped corpus.
     *
     * @return string[]
     */
    public function loadErrors(): array
    {
        $this->all();

        return $this->loadErrors;
    }

    /**
     * @param array<string, HelpTopic> $topics
     * @throws HelpException on a duplicate id — caught by addParsed(), so
     *         a collision costs the SECOND file rather than the site. Not
     *         last-one-wins: keeping the first is what makes the outcome
     *         independent of module load order.
     */
    private function add(array &$topics, HelpTopic $topic): void
    {
        if (isset($topics[$topic->id])) {
            throw new HelpException(
                "Duplicate help topic id '{$topic->id}': {$topics[$topic->id]->filePath} and {$topic->filePath}"
            );
        }

        $topics[$topic->id] = $topic;
    }

    /**
     * Sorted for a deterministic load order — collisions must name the
     * same "first" file on every run, and tests must never depend on
     * filesystem enumeration order.
     *
     * @return string[]
     */
    private function listTopicFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob(rtrim($directory, '/') . '/*.md');
        if ($files === false) {
            return [];
        }
        sort($files);

        return $files;
    }
}
