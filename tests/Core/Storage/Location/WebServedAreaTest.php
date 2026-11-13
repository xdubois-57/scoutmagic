<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location;

use Core\Storage\Location\WebServedArea;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1129: what the web server serves depends on the hosting layout,
 * and in layout B it is nearly the whole installation.
 */
final class WebServedAreaTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/scoutmagic-webarea-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/public', 0777, true);
        mkdir($this->root . '/storage/config', 0777, true);
        file_put_contents($this->root . '/public/index.php', '<?php');
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isLink() || !$item->isDir() ? @unlink((string) $item) : @rmdir((string) $item);
        }
        @rmdir($this->root);
    }

    /** Layout B's tell: the `index.php` stub next to `public/`. */
    private function makeLayoutB(): void
    {
        file_put_contents($this->root . '/index.php', '<?php');
    }

    // ————— Layout A —————

    public function testInLayoutAOnlyThePublicDirectoryIsServed(): void
    {
        $area = WebServedArea::of($this->root, $this->root . '/public');

        $this->assertTrue($area->serves($this->root . '/public'));
        $this->assertTrue($area->serves($this->root . '/public/photos'));
        $this->assertFalse($area->serves($this->root . '/photos'));
        $this->assertFalse($area->serves($this->root . '/storage/photos'));
        $this->assertFalse($area->serves('/mnt/nas/photos'));
    }

    /**
     * Layout A merges `public/` into a document root of any name. A caller
     * that does not know it — the scheduler — finds it by what it holds.
     */
    public function testInLayoutAADocumentRootOfAnyNameIsFoundByItsFrontController(): void
    {
        mkdir($this->root . '/www');
        file_put_contents($this->root . '/www/index.php', '<?php');
        file_put_contents($this->root . '/www/cron.php', '<?php');

        $area = WebServedArea::forStoragePath($this->root . '/storage');

        $this->assertTrue($area->serves($this->root . '/www/photos'));
        $this->assertFalse($area->serves($this->root . '/photos'));
    }

    // ————— Layout B —————

    /**
     * The bug: `<root>/photos` passed a `public/`-only check, and layout B's
     * root `.htaccess` serves every existing file there.
     */
    public function testInLayoutBTheInstallationRootIsServed(): void
    {
        $this->makeLayoutB();
        $area = WebServedArea::of($this->root, $this->root . '/public');

        $this->assertTrue($area->serves($this->root . '/photos'));
        $this->assertTrue($area->serves($this->root . '/galerie/2026'));
        $this->assertTrue($area->serves($this->root));
        $this->assertTrue($area->serves($this->root . '/public/photos'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function protectedInLayoutB(): array
    {
        return [
            'storage' => ['storage/photos'],
            'vendor' => ['vendor/x'],
            'config' => ['config'],
            'modules' => ['modules/gallery/photos'],
            'a dot-directory' => ['.photos'],
            'a dot-directory deeper down' => ['galerie/.cache'],
            'a .well-known that is not at the top' => ['photos/.well-known'],
        ];
    }

    #[DataProvider('protectedInLayoutB')]
    public function testInLayoutBTheDirectoriesTheHtaccessRefusesAreNotServed(string $relative): void
    {
        $this->makeLayoutB();
        $area = WebServedArea::of($this->root, $this->root . '/public');

        $this->assertFalse($area->serves($this->root . '/' . $relative));
    }

    public function testInLayoutBAFolderOutsideTheInstallationIsNotServed(): void
    {
        $this->makeLayoutB();

        $this->assertFalse(WebServedArea::of($this->root)->serves('/mnt/nas/photos'));
    }

    public function testTheSchedulerSeesLayoutBFromTheStoragePathAlone(): void
    {
        $this->makeLayoutB();

        $this->assertTrue(WebServedArea::forStoragePath($this->root . '/storage')->serves($this->root . '/photos'));
    }

    /** The install report is the canonical answer when it exists. */
    public function testTheInstallReportDecidesTheLayout(): void
    {
        file_put_contents($this->root . '/storage/config/install-report.json', json_encode(['layout' => 'B']));

        $this->assertTrue(WebServedArea::of($this->root)->serves($this->root . '/photos'));
    }

    /**
     * Canonical comparison: a symbolic link from outside into the served
     * root, and a folder that does not exist yet under it, are both seen.
     */
    public function testASymbolicLinkIntoTheServedRootIsSeen(): void
    {
        $this->makeLayoutB();
        mkdir($this->root . '/photos');
        $link = sys_get_temp_dir() . '/scoutmagic-webarea-link-' . bin2hex(random_bytes(6));
        if (!@symlink($this->root . '/photos', $link)) {
            $this->markTestSkipped('This filesystem does not allow creating a symbolic link.');
        }

        try {
            $this->assertTrue(WebServedArea::of($this->root)->serves($link . '/pas-encore-la'));
        } finally {
            @unlink($link);
        }
    }

    // ————— Both layouts —————

    /**
     * The reported document root is a second reason to refuse, whatever
     * the layout says.
     */
    public function testAFolderUnderTheReportedDocumentRootIsServed(): void
    {
        $docRoot = $this->root . '/htdocs';
        mkdir($docRoot);

        $area = WebServedArea::of($this->root, $this->root . '/public', $docRoot);

        $this->assertTrue($area->serves($docRoot . '/photos'));
    }

    /**
     * A `..` climbs, even in the part of the path that does not exist yet:
     * it is never a way around the comparison.
     */
    public function testAParentSegmentClimbsLikeTheKernelWould(): void
    {
        $this->makeLayoutB();
        $area = WebServedArea::of($this->root);

        $this->assertTrue($area->serves($this->root . '/storage/../photos'));
        $this->assertTrue($area->serves($this->root . '/storage/pas-encore/../../photos'));
        $this->assertFalse($area->serves($this->root . '/photos/../storage/archives'));
    }

    /**
     * The protected list is a copy of the rule in layout B's root
     * `.htaccess`; the two must not drift apart.
     */
    public function testTheProtectedDirectoriesAreTheOnesTheHtaccessRefuses(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 4) . '/bootstrap/bootstrap.php');
        $this->assertSame(
            1,
            preg_match('#RewriteRule \^\(([a-z|]+)\)\(/\|\$\) - \[F,L\]#', $bootstrap, $match)
        );

        $this->assertEqualsCanonicalizing(WebServedArea::PROTECTED_TOP_LEVEL, explode('|', $match[1]));
    }
    /**
     * The `.htaccess` lets a top-level `/.well-known/` through its dot
     * rule (RFC 8615), then serves what exists there (review of #1197).
     */
    public function testInLayoutBATopLevelWellKnownIsServed(): void
    {
        $this->makeLayoutB();

        $this->assertTrue(WebServedArea::of($this->root)->serves($this->root . '/.well-known/photos'));
    }

    /**
     * A symbolic link INSIDE the served area pointing OUT: the web server
     * serves `/elsewhere` under `<root>/photos`, so the path as written
     * counts, not only where it leads (review of #1197).
     */
    public function testALinkInsideTheServedAreaPointingOutIsServed(): void
    {
        $this->makeLayoutB();
        $outside = sys_get_temp_dir() . '/scoutmagic-webarea-out-' . bin2hex(random_bytes(6));
        mkdir($outside);
        if (!@symlink($outside, $this->root . '/photos')) {
            @rmdir($outside);
            $this->markTestSkipped('This filesystem does not allow creating a symbolic link.');
        }

        try {
            $this->assertTrue(WebServedArea::of($this->root)->serves($this->root . '/photos/2026'));
            // The same directory, reached by its own name, is not served.
            $this->assertFalse(WebServedArea::of($this->root)->serves($outside . '/2026'));
        } finally {
            @unlink($this->root . '/photos');
            @rmdir($outside);
        }
    }

    /**
     * A caller that knows only `storage/` still gets the document root the
     * web server reports, during a request (review of #1197).
     */
    public function testTheStoragePathOnlyCallerSeesTheReportedDocumentRoot(): void
    {
        $docRoot = $this->root . '/htdocs';
        mkdir($docRoot);
        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = $docRoot;

        try {
            $this->assertTrue(WebServedArea::forStoragePath($this->root . '/storage')->serves($docRoot . '/photos'));
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }
}
