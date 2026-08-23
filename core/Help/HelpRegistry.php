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
 * throws instead of last-one-wins: a silent overwrite would make one
 * module's help vanish depending on load order. The cross-corpus
 * uniqueness invariant is also pinned by tests/Core/Help/, so a collision
 * is caught long before a release.
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

    public function __construct(
        private readonly string $coreDirectory,
        private readonly HelpFrontMatterParser $parser = new HelpFrontMatterParser(),
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
            $this->topics = $this->load();
        }

        return $this->topics;
    }

    /**
     * @return array<string, HelpTopic>
     */
    private function load(): array
    {
        $topics = [];

        foreach ($this->listTopicFiles($this->coreDirectory) as $file) {
            $this->add($topics, $this->parser->parse($file, null));
        }

        foreach ($this->moduleDirectories as $moduleId => $directory) {
            foreach ($this->listTopicFiles($directory) as $file) {
                $this->add($topics, $this->parser->parse($file, $moduleId));
            }
        }

        return $topics;
    }

    /**
     * @param array<string, HelpTopic> $topics
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
