<?php

declare(strict_types=1);

namespace Tests\Modules\Groups;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The documentation this module owes the project.
 *
 * A module that ships without its section in ARCHITECTURE.md and
 * specifications.md is a module the next person has to reverse-engineer,
 * and by then the reasons behind its decisions are gone. These are the
 * cheapest possible guard: they only check the sections exist and name
 * the things that would be most expensive to rediscover.
 */
class DocumentationTest extends TestCase
{
    public function testArchitectureHasASectionForTheModule(): void
    {
        $this->assertStringContainsString('### 8.40 Groups module (`modules/groups`)', $this->read('ARCHITECTURE.md'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function architectureTopics(): iterable
    {
        yield 'the two membership sources' => ['Two membership sources, resolved per request'];
        yield 'the 404-not-403 rule' => ['404, never 403'];
        yield 'the delegated album' => ['DelegatedAlbumManager'];
        yield 'the ownership checker' => ['GroupFileOwnershipChecker'];
        yield 'the lifecycle tasks' => ['four self-rescheduling daily tasks'];
        yield 'why there is no Desk import hook' => ['no hook from the Desk import'];
    }

    #[DataProvider('architectureTopics')]
    public function testArchitectureCoversTheDecisionsWorthRecording(string $needle): void
    {
        $this->assertStringContainsString($needle, $this->read('ARCHITECTURE.md'));
    }

    public function testSpecificationsHasAFunctionalSectionAndListsThePageInTheMenuTable(): void
    {
        $specs = $this->read('specifications.md');

        $this->assertStringContainsString('## 20. Groups module', $specs);
        // §4.2 is the Espace des animés menu table — the module's page has
        // to appear there, not only in its own section.
        $this->assertStringContainsString('| Groupes (module) |', $specs);
    }

    public function testTheReadmeListsTheModule(): void
    {
        $this->assertStringContainsString('groupes de discussion', $this->read('README.md'));
    }

    /**
     * SECURITY.md §12 notes that forgetting this has happened more than
     * once — and this module does write there, for a link preview's
     * cached image (Service\PostLinkService).
     */
    public function testTheModulesStorageDirectoryIsGitignored(): void
    {
        $this->assertStringContainsString('storage/groups/', $this->read('.gitignore'));
    }

    /**
     * Every module that writes under storage/ must be listed, not just
     * this one — the check is cheap enough to apply to all of them.
     */
    public function testEveryModuleWritingUnderStorageIsGitignored(): void
    {
        $gitignore = $this->read('.gitignore');
        $root = dirname(__DIR__, 3);

        foreach ((array) glob($root . '/modules/*', GLOB_ONLYDIR) as $moduleDir) {
            $moduleId = basename((string) $moduleDir);
            if (!$this->writesUnderStorage((string) $moduleDir, $moduleId)) {
                continue;
            }

            $this->assertStringContainsString(
                "storage/{$moduleId}/",
                $gitignore,
                "module '{$moduleId}' writes under storage/{$moduleId}/ but .gitignore does not cover it"
            );
        }
    }

    public function testTheDelegatedAlbumApiIsDocumentedForModuleAuthors(): void
    {
        $docs = $this->read('docs/module-development.md');

        $this->assertStringContainsString('Storing media in a gallery album you own', $docs);
        // The two things a module author gets wrong: forgetting one of
        // the two access checkers, and purging rows without the files.
        $this->assertStringContainsString('You must register **two** checkers', $docs);
        $this->assertStringContainsString('must delete files, not just rows', $docs);
        $this->assertStringContainsString('DelegatedAlbumManagerFactory::fromTaskContext', $docs);
    }

    public function testHardDependenciesAreDocumentedForModuleAuthors(): void
    {
        $docs = $this->read('docs/module-development.md');

        $this->assertStringContainsString('## Hard dependencies between modules (`requires`)', $docs);
    }

    private function writesUnderStorage(string $moduleDir, string $moduleId): bool
    {
        foreach ((array) glob($moduleDir . '/src/**/*.php') as $file) {
            $source = (string) file_get_contents((string) $file);
            if (preg_match('~[\'"]' . preg_quote($moduleId, '~') . '/~', $source) === 1) {
                return true;
            }
        }

        return false;
    }

    private function read(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
    }
}
