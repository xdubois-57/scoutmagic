<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

/**
 * Shared fixture helper for the help tests: writes real topic .md files
 * into per-test temp directories, so every test exercises the actual
 * parser/registry file path rather than a hand-built HelpTopic.
 */
trait HelpTopicFileFixtures
{
    /** @var string[] */
    private array $fixtureDirs = [];

    private function makeTopicDir(): string
    {
        $dir = sys_get_temp_dir() . '/help_topics_' . uniqid('', true);
        mkdir($dir, 0777, true);
        $this->fixtureDirs[] = $dir;

        return $dir;
    }

    /**
     * @param array<string, string|string[]|null> $frontMatter null removes
     *        a default key; an array writes the key once per value, which
     *        is how the repeatable `question` key is declared
     *        (Core\Help\HelpFrontMatterParser::REPEATABLE_KEYS).
     */
    private function writeTopic(string $dir, string $id, array $frontMatter = [], string $body = "Un corps de sujet.\n", ?string $fileName = null): string
    {
        $values = array_merge([
            'id' => $id,
            'title' => 'Titre ' . $id,
            'summary' => 'Résumé ' . $id,
            'category' => 'Test',
            'role_min' => 'public',
        ], $frontMatter);

        $lines = ["---"];
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $single) {
                $lines[] = $key . ': ' . $single;
            }
        }
        $lines[] = '---';
        $lines[] = '';
        $lines[] = $body;

        $path = $dir . '/' . ($fileName ?? $id . '.md');
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    private function cleanupTopicDirs(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->fixtureDirs = [];
    }
}
