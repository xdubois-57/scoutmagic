<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Issue #1097: `partials/form_field.html.twig` reads `field_id` and
 * `field_name`, and ignores any other key in silence. The coverage screen
 * passed `name:` to it, so its two selects rendered with no `name` at all:
 * the GET form submitted nothing, and every choice fell back to the default
 * without an error anywhere. Twig has no unknown-key check for an include,
 * so this test is it.
 *
 * Every include passes `field_id` (it ties the label and the help text to
 * the control), and none passes the bare `name` or `id` it would ignore.
 */
final class FormFieldIncludesUseItsKeysTest extends TestCase
{
    private const INCLUDE = "~include\\s+'partials/form_field\\.html\\.twig'\\s+with\\s+\\{(.*?)\\}\\s*only~s";

    public function testEveryIncludeNamesItsFieldWithTheKeysThePartialReads(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $includes = 0;

        foreach (['core', 'modules'] as $top) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $top, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $path = $file->getPathname();
                if (!str_ends_with($path, '.twig') || str_contains($path, '/vendor/')) {
                    continue;
                }

                $source = (string) file_get_contents($path);
                preg_match_all(self::INCLUDE, $source, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[1] as [$arguments, $offset]) {
                    $includes++;
                    $keys = $this->keysOf($arguments);
                    $problems = [];
                    if (!in_array('field_id', $keys, true)) {
                        $problems[] = 'no field_id';
                    }
                    foreach (array_intersect(['name', 'id'], $keys) as $ignored) {
                        $problems[] = "'{$ignored}' is ignored (field_" . $ignored . ')';
                    }
                    if ($problems !== []) {
                        $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                        $offenders[] = substr($path, strlen($root) + 1) . ':' . $line . ': ' . implode(', ', $problems);
                    }
                }
            }
        }

        $this->assertGreaterThan(100, $includes, 'the pattern no longer finds the includes it guards');
        $this->assertSame([], $offenders, 'partials/form_field.html.twig reads field_id and field_name only.');
    }

    /**
     * The pattern on the case it exists for, so a change to it cannot make
     * the test above pass by finding nothing wrong.
     */
    public function testThePatternSeesTheKeysOfAnInclude(): void
    {
        $source = "{% include 'partials/form_field.html.twig' with {\n    type: 'select',\n    name: 'category',"
            . "\n    options: { a: 1 },\n} only %}";

        $this->assertSame(1, preg_match(self::INCLUDE, $source, $match));
        $keys = $this->keysOf($match[1]);
        $this->assertContains('name', $keys);
        $this->assertNotContains('field_id', $keys);
        $this->assertNotContains('a', $keys, 'a key of a nested map is not a key of the include');
    }

    /**
     * The top-level keys only: a nested map (`data: { id: 4 }`) or an
     * options list carries keys of its own that are not the include's.
     *
     * @return list<string>
     */
    private function keysOf(string $arguments): array
    {
        $keys = [];
        $depth = 0;
        $expectKey = true;
        $length = strlen($arguments);

        for ($i = 0; $i < $length; $i++) {
            $char = $arguments[$i];
            if ($char === "'" || $char === '"') {
                $end = strpos($arguments, $char, $i + 1);
                $i = $end === false ? $length : $end;
                $expectKey = false;
                continue;
            }
            if ($char === '{' || $char === '[' || $char === '(') {
                $depth++;
                continue;
            }
            if ($char === '}' || $char === ']' || $char === ')') {
                $depth--;
                continue;
            }
            if ($depth === 0 && $char === ',') {
                $expectKey = true;
                continue;
            }
            if ($depth === 0 && $expectKey && preg_match('/\G\s*([a-z_]+)\s*:/A', $arguments, $m, 0, $i) === 1) {
                $keys[] = $m[1];
                $i += strlen($m[0]) - 1;
                $expectKey = false;
            }
        }

        return $keys;
    }
}
