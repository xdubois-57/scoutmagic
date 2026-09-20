<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Seed;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Mail\Feedback\Seed\SeedMailboxes;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\InboundMailInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * **Whether the measurement can see what it claims to measure.**
 *
 * A mailbox declared under « Courrier entrant » is read in its INBOX and
 * nowhere else until somebody names more folders. For every other
 * consumer that is the right default; for this one it inverts the
 * answer — the copy a provider shelves as spam is never fetched, never
 * recorded, and two days later the sweep writes « jamais arrivé » on it.
 * The gravest verdict the seed screen has would then be produced,
 * systematically, by the one outcome the screen exists to detect.
 *
 * @group database
 */
#[Group('database')]
class SeedMailboxesTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
    }

    /**
     * @param list<list<string>> $folders
     */
    private function boxesWatching(array $folders): SeedMailboxes
    {
        $stub = $this->createStub(InboundMailInterface::class);
        $stub->method('watchedFoldersFor')->willReturn($folders);

        return $this->seedMailboxes($stub);
    }

    private function seedMailboxes(?InboundMailInterface $module): SeedMailboxes
    {
        return new SeedMailboxes(
            new SeedCopyRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            new SettingService(new SettingRepository($this->pdo)),
            $module
        );
    }

    public function testABoxReadOnlyInItsInboxIsBlind(): void
    {
        $this->assertSame(1, $this->boxesWatching([['INBOX']])->boxesBlindToSpam());
    }

    public function testABoxWatchingItsJunkFolderIsNot(): void
    {
        $this->assertSame(0, $this->boxesWatching([['INBOX', 'Junk']])->boxesBlindToSpam());
    }

    /** Only the blind ones are counted, not all of them. */
    public function testOnlyTheBlindBoxesAreCounted(): void
    {
        $this->assertSame(
            2,
            $this->boxesWatching([['INBOX'], ['INBOX', 'Spam'], ['INBOX'], ['Junk']])->boxesBlindToSpam()
        );
    }

    /**
     * **The same vocabulary the verdict uses**, deliberately: a folder
     * this site would read as « Indésirables » is exactly the folder it
     * needs to be watching, and a second list of provider folder names
     * would drift from the first.
     *
     * @param list<string> $folders
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('junkFolderNames')]
    public function testEveryNameTheVerdictCallsJunkCountsAsWatching(array $folders): void
    {
        $this->assertSame(0, $this->boxesWatching([$folders])->boxesBlindToSpam());
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function junkFolderNames(): array
    {
        return [
            'Google' => [['INBOX', 'Spam']],
            'nested under the inbox' => [['INBOX', 'INBOX/Junk']],
            'OVH, in French' => [['INBOX', 'Indésirables']],
            'unaccented' => [['INBOX', 'Indesirables']],
            'an older server' => [['INBOX', 'Bulk Mail']],
        ];
    }

    /** No module at all is no boxes, so nothing to report. */
    public function testWithoutTheModuleThereIsNothingToReport(): void
    {
        $this->assertSame(0, $this->seedMailboxes(null)->boxesBlindToSpam());
    }

    /**
     * **A module that cannot answer is « nothing to report »**, like
     * `addresses()`: this is a diagnostic, and a warning raised by a
     * hiccup teaches its reader to ignore the warning — which is the one
     * thing this warning cannot afford.
     */
    public function testAModuleThatThrowsRaisesNoWarning(): void
    {
        $stub = $this->createStub(InboundMailInterface::class);
        $stub->method('watchedFoldersFor')->willThrowException(new \RuntimeException('imap down'));

        $this->assertSame(0, $this->seedMailboxes($stub)->boxesBlindToSpam());
    }
}
