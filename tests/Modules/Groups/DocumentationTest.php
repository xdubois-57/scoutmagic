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
        yield 'the self-restore refusal' => ['never restore an item they authored'];
        yield 'the escalation to site admins' => ['escalates to every site admin'];
        yield 'the reopen activity reset' => ['resets `last_activity_at` to now'];
        yield 'the last-moderator rule' => ['last explicit moderator'];
        yield 'the creation quota' => ['groups_max_created_per_member'];
        // Who a "une réponse par membre" poll may be answered for is a
        // decision, not a detail: it is deliberately wider than who may
        // publish, and the two methods are one character apart to read.
        yield 'who a member-scoped poll may be answered for' => ['memberIdsAllowedToVoteAs'];
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

    /**
     * The four behaviours prompt 12 added, in the functional spec a unit's
     * staff would actually read.
     */
    public function testSpecificationsCoverTheManagementActions(): void
    {
        $specs = $this->read('specifications.md');

        $this->assertStringContainsString('never restore an item they wrote themselves', $specs);
        $this->assertStringContainsString('cannot be left', $specs);
        $this->assertStringContainsString('last moderator', $specs);
        $this->assertStringContainsString('Rouvrir', $specs);
        $this->assertStringContainsString('open, non-section', $specs);
    }

    /**
     * Which of an account's members a poll may be answered for is a rule
     * with two halves, and a unit's staff has to be able to read both in
     * the functional spec: it decides whether a count they act on is
     * complete, and whether it counts anybody it should not.
     */
    public function testSpecificationsSayWhichMembersAPollMayBeAnsweredFor(): void
    {
        $specs = $this->read('specifications.md');

        $this->assertStringContainsString("a section group asks its section's question", $specs);
        $this->assertStringContainsString('offers every member the account reaches', $specs);
    }

    /**
     * And the person doing the voting reads none of the above: they read
     * the help panel. A rule about whose answer counts is exactly the
     * kind that drifts out of the help text first, since nothing breaks
     * when it does.
     */
    public function testTheHelpTopicSaysWhichMembersAPollMayBeAnsweredFor(): void
    {
        $help = $this->read('modules/groups/help/groupes.md');

        // Substrings that survive a re-wrap of the paragraph: the two
        // halves of the rule, and the heading the picker draws.
        $this->assertStringContainsString("c'est le groupe qui le décide", $help);
        $this->assertStringContainsString('seuls ceux qui en font partie', $help);
        $this->assertStringContainsString('Hors de ce groupe', $help);
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
        // Covered by the storage/** catch-all (audit hardening) rather than a
        // per-module line — assert the path actually resolves to ignored.
        $root = dirname(__DIR__, 3);
        $output = [];
        exec('git -C ' . escapeshellarg($root) . ' check-ignore ' . escapeshellarg('storage/groups/preview.jpg') . ' 2>/dev/null', $output, $status);
        $this->assertSame(0, $status, 'storage/groups/ content must be gitignored');
    }

    /**
     * Every module that writes under storage/ must be listed, not just
     * this one — the check is cheap enough to apply to all of them.
     */
    public function testEveryModuleWritingUnderStorageIsGitignored(): void
    {
        $gitignore = $this->read('.gitignore');

        // A single catch-all (`storage/**`, re-including only `.gitkeep`
        // placeholders) ignores every module's storage/<id>/ content — present
        // AND future — so there is no per-module enumeration to forget when a
        // new module starts writing under storage/ (audit hardening).
        $this->assertStringContainsString('storage/**', $gitignore);
        $this->assertStringContainsString('!storage/**/.gitkeep', $gitignore);

        // The catch-all must not accidentally leave a real module's content
        // uncovered: a sample path under each storage-writing module resolves
        // to ignored.
        $root = dirname(__DIR__, 3);
        foreach ((array) glob($root . '/modules/*', GLOB_ONLYDIR) as $moduleDir) {
            $moduleId = basename((string) $moduleDir);
            if (!$this->writesUnderStorage((string) $moduleDir, $moduleId)) {
                continue;
            }

            $output = [];
            exec('git -C ' . escapeshellarg($root) . ' check-ignore ' . escapeshellarg("storage/{$moduleId}/sample.dat") . ' 2>/dev/null', $output, $status);
            $this->assertSame(0, $status, "module '{$moduleId}' writes under storage/{$moduleId}/ but that path is not gitignored");
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
