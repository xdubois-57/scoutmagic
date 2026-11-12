<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Issue #1109: no page, script or service builds a `/files/…` address by
 * hand from a file's sequential id. Every address goes through the one
 * builder — `file_url()` in Twig, `Core\File\FilePublicIds::urlFor()` in
 * PHP — which produces the opaque one; a script is handed the address by
 * the server rather than building it from a `file_id`.
 *
 * A hand-built address is the regression this pins: it would put the
 * sequential id back in a page, and enumeration back with it.
 */
final class FileAddressesAreOpaqueTest extends TestCase
{
    /**
     * `/files/` immediately followed by something that joins a value onto
     * it: a PHP `.` or interpolation (`"/files/$id"`, `"/files/{$id}"`), a
     * JavaScript `+` or template literal, a Twig `~` or `{{ }}`, a `sprintf`
     * placeholder.
     */
    private const HAND_BUILT = '#/files/[\'"`]?\s*(?:\.|\+|~)|/files/\{\{|/files/\$\{|/files/\{?\$\w|/files/%[ds]#';

    /**
     * Where the string `/files/` means something else.
     */
    private const ELSEWHERE = [
        // The Google Drive API's own /files/{id} resource.
        'core/Storage/Location/Backend/Drive/GoogleDriveClient.php',
        // The one builder everything else goes through.
        'core/File/FilePublicIds.php',
    ];

    public function testNoAddressIsBuiltByHandFromAFileId(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (['core', 'modules', 'public', 'scripts', 'tests/fixtures'] as $top) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $top, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $path = $file->getPathname();
                $relative = substr($path, strlen($root) + 1);
                if (!preg_match('~\.(php|twig|js)$~', $path)
                    || str_contains($relative, '/vendor/')
                    || str_starts_with($relative, 'public/assets/vendor/')
                    || in_array($relative, self::ELSEWHERE, true)
                ) {
                    continue;
                }

                foreach (file($path) ?: [] as $number => $line) {
                    if (preg_match(self::HAND_BUILT, $line) === 1 && !$this->isComment($line)) {
                        $offenders[] = $relative . ':' . ($number + 1) . ': ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A /files/ address is built by hand from a file id. Use file_url() in Twig or "
                . "Core\\File\\FilePublicIds::urlFor() in PHP, and hand a script the address rather than the id."
        );
    }

    /**
     * The pattern itself, so a broadened exclusion cannot quietly make the
     * test above pass by matching nothing.
     */
    public function testThePatternRecognisesEachWayOfBuildingOne(): void
    {
        foreach ([
            "'/files/' . \$id",
            '"/files/" . $id',
            "'/files/' + r.file_id",
            '`/files/${id}`',
            "'/files/' ~ doc.fileId",
            'href="/files/{{ doc.fileId }}"',
            "sprintf('/files/%d', \$id)",
            '"/files/{$id}"',
            '"/files/$fileId/thumb"',
        ] as $built) {
            $this->assertSame(1, preg_match(self::HAND_BUILT, $built), $built);
        }

        foreach ([
            "{{ file_url(doc.fileId) }}",
            "FilePublicIds::urlFor(\$id)",
            "a[href^=\"/files/\"]",
        ] as $fine) {
            $this->assertSame(0, preg_match(self::HAND_BUILT, $fine), $fine);
        }
    }

    private function isComment(string $line): bool
    {
        return preg_match('~^\s*(//|\*|/\*|#|\{#)~', $line) === 1;
    }
}
