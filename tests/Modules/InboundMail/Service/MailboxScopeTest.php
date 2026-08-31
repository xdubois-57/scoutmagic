<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\MailboxPurpose;
use Modules\InboundMail\Api\MailboxScope;
use Modules\InboundMail\Api\ReadMode;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Service\MailboxScopeService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * Who may do what with which box.
 *
 * The two questions the configuration screen asks are deliberately not one,
 * and what this file pins is that the code keeps them apart: analysing is
 * not reading, a dedicated box's answers come from its purpose rather than
 * from rows that could drift, and a module nobody has answered for does
 * nothing at all.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailboxScopeTest extends TestCase
{
    private \PDO $pdo;
    private InboundMailboxRepository $mailboxes;
    private MessageConsumerRegistry $registry;
    private MailboxScopeService $scopes;
    private int $mailboxId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);

        $this->mailboxes = new InboundMailboxRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->registry = new MessageConsumerRegistry();
        $this->registry->register(new FakeMessageConsumer('rental'));
        $this->registry->register(new FakeMessageConsumer('camps'));
        $this->registry->register(new FakeMessageConsumer('finance'));

        $this->scopes = new MailboxScopeService($this->mailboxes, $this->registry);
        $this->mailboxId = $this->createMailbox();
    }

    // ── Absence is an answer ────────────────────────────────────────────

    public function testAModuleNobodyAnsweredForDoesNothing(): void
    {
        // A module installed after a box was configured must not inherit
        // anything. Anything else silently widens who reads a unit's mail
        // on an upgrade nobody read the notes for.
        $scopes = $this->scopes->scopesFor($this->mailbox());

        foreach (['rental', 'camps', 'finance'] as $id) {
            $this->assertFalse($scopes[$id]->analyzes, $id . ' must start inert');
            $this->assertSame(ReadMode::NONE, $scopes[$id]->effectiveReadMode());
        }
    }

    public function testEveryRegisteredConsumerGetsAScopeEvenWithNoRow(): void
    {
        $this->assertSame(
            ['rental', 'camps', 'finance'],
            array_keys($this->scopes->scopesFor($this->mailbox()))
        );
    }

    // ── Analysing is not reading ────────────────────────────────────────

    public function testAModuleThatDoesNotAnalyseCannotReadEitherHoweverTheRowReads(): void
    {
        // The two columns are written independently, and « personne ne
        // classe ce courrier, mais tout le monde peut le lire » is not a
        // state the screen can produce or that anybody meant.
        $this->mailboxes->saveScope($this->mailboxId, new MailboxScope('rental', false, ReadMode::ALL));

        $this->assertSame(
            ReadMode::NONE,
            $this->scopes->scopeFor($this->mailbox(), 'rental')->effectiveReadMode()
        );
    }

    public function testSortingWithoutAListIsARealAnswer(): void
    {
        $this->scopes->saveSharedScopes($this->mailboxId, [
            'rental' => ['analyze' => true, 'read' => 'none'],
        ]);

        $scope = $this->scopes->scopeFor($this->mailbox(), 'rental');
        $this->assertTrue($scope->analyzes);
        $this->assertFalse($scope->effectiveReadMode()->opensAList());
    }

    // ── Only the authorised consumers are even asked ────────────────────

    public function testOnlyTheModulesTheBoxWasOpenedToAreOfferedItsMail(): void
    {
        $this->scopes->saveSharedScopes($this->mailboxId, [
            'rental' => ['analyze' => true, 'read' => 'relevant'],
            'camps' => ['analyze' => false, 'read' => 'none'],
        ]);

        $this->assertSame(
            ['rental'],
            array_map(
                static fn($consumer) => $consumer->consumerId(),
                $this->scopes->analyzingConsumers($this->mailbox())
            )
        );
    }

    // ── A dedicated box's answers come from its purpose ─────────────────

    public function testADedicatedBoxGivesItsModuleEverythingAndTheOthersNothing(): void
    {
        $this->scopes->saveDedicated($this->mailboxId, 'camps');

        $scopes = $this->scopes->scopesFor($this->mailbox());

        $this->assertTrue($scopes['camps']->analyzes);
        $this->assertSame(ReadMode::ALL, $scopes['camps']->effectiveReadMode());
        $this->assertFalse($scopes['rental']->analyzes);
        $this->assertFalse($scopes['finance']->analyzes);
    }

    public function testAStaleRowCannotResurrectAModuleOnADedicatedBox(): void
    {
        // The box was shared and rental read it. It is dedicated to camps
        // now, and rental's row is still there. The purpose wins — the
        // alternative is a module reading a box the operator shut it out of.
        $this->scopes->saveSharedScopes($this->mailboxId, [
            'rental' => ['analyze' => true, 'read' => 'all'],
        ]);
        $this->mailboxes->setPurpose($this->mailboxId, MailboxPurpose::DEDICATED, 'camps');
        $this->pdo->exec(
            "INSERT INTO inbound_mailbox_consumers (mailbox_id, consumer_id, analyze_enabled, read_mode)
             VALUES (99, 'rental', 1, 'all')"
        );

        $scopes = $this->scopes->scopesFor($this->mailbox());

        $this->assertFalse($scopes['rental']->analyzes);
        $this->assertSame(ReadMode::ALL, $scopes['camps']->effectiveReadMode());
    }

    public function testDeclaringABoxSharedAgainRestoresPerModuleAnswers(): void
    {
        $this->scopes->saveDedicated($this->mailboxId, 'camps');
        $this->scopes->saveSharedScopes($this->mailboxId, [
            'rental' => ['analyze' => true, 'read' => 'relevant'],
        ]);

        $scopes = $this->scopes->scopesFor($this->mailbox());

        $this->assertSame(MailboxPurpose::SHARED, $this->mailbox()->purpose);
        $this->assertTrue($scopes['rental']->analyzes);
        $this->assertFalse($scopes['camps']->analyzes, 'the dedication is really gone');
    }

    // ── Which boxes a consumer may read in full ─────────────────────────

    public function testABoxIsReadableInFullThroughItsDedicationOrThroughAnExplicitChoice(): void
    {
        $dedicated = $this->createMailbox('Camps');
        $shared = $this->createMailbox('Info');
        $narrow = $this->createMailbox('Autre');

        $this->scopes->saveDedicated($dedicated, 'camps');
        $this->scopes->saveSharedScopes($shared, ['camps' => ['analyze' => true, 'read' => 'all']]);
        $this->scopes->saveSharedScopes($narrow, ['camps' => ['analyze' => true, 'read' => 'relevant']]);

        $this->assertSame([$dedicated, $shared], $this->mailboxes->mailboxIdsReadableInFull('camps'));
    }

    public function testAModuleThatOnlySortsABoxNeverReadsItInFull(): void
    {
        $this->scopes->saveSharedScopes($this->mailboxId, ['rental' => ['analyze' => true, 'read' => 'none']]);

        $this->assertSame([], $this->mailboxes->mailboxIdsReadableInFull('rental'));
    }

    // ── The Camps reprise (A8) ──────────────────────────────────────────

    public function testTheCampsDedicatedBoxesAreTakenOverOnce(): void
    {
        $second = $this->createMailbox('Camps 2');
        $settings = $this->settingsWith([
            ['camps', MailboxScopeService::CAMPS_LEGACY_SETTING, $this->mailboxId . ',' . $second],
        ]);

        $this->assertSame(2, $this->scopes->repriseCampsDedicatedBoxes($settings, new SettingRepository($this->pdo)));

        $this->assertSame(MailboxPurpose::DEDICATED, $this->mailbox()->purpose);
        $this->assertSame('camps', $this->mailbox()->dedicatedTo);
        $this->assertSame([$this->mailboxId, $second], $this->mailboxes->mailboxIdsReadableInFull('camps'));
    }

    public function testTheRepriseRunsOnceAndThenNeverAgain(): void
    {
        $settings = $this->settingsWith([
            ['camps', MailboxScopeService::CAMPS_LEGACY_SETTING, (string) $this->mailboxId],
        ]);
        $repository = new SettingRepository($this->pdo);

        $this->scopes->repriseCampsDedicatedBoxes($settings, $repository);
        // Somebody has since decided the box is shared after all. A second
        // reprise must not undo their decision.
        $this->scopes->saveSharedScopes($this->mailboxId, []);

        $this->assertSame(0, $this->scopes->repriseCampsDedicatedBoxes($settings, $repository));
        $this->assertSame(MailboxPurpose::SHARED, $this->mailbox()->purpose);
    }

    public function testARepriseWithNothingToMigrateIsStillFinished(): void
    {
        $settings = $this->settingsWith([]);
        $repository = new SettingRepository($this->pdo);

        $this->assertSame(0, $this->scopes->repriseCampsDedicatedBoxes($settings, $repository));
        $this->assertSame(
            '1',
            $settings->get(MailboxScopeService::REPRISE_DONE_SETTING, 'inbound_mail', ''),
            'otherwise a legacy setting is re-read on every page view for the life of the installation'
        );
    }

    public function testABoxDeletedSinceIsSkippedRatherThanRecreated(): void
    {
        $settings = $this->settingsWith([
            ['camps', MailboxScopeService::CAMPS_LEGACY_SETTING, '4242'],
        ]);

        $this->assertSame(0, $this->scopes->repriseCampsDedicatedBoxes($settings, new SettingRepository($this->pdo)));
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function idLists(): array
    {
        return [
            'commas' => ['1,2,3'],
            'spaces' => ['1 2 3'],
            'mixed with noise' => ['1, 2,,  3 '],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('idLists')]
    public function testTheLegacyIdListIsReadHoweverItWasTyped(string $raw): void
    {
        $this->assertSame([1, 2, 3], MailboxScopeService::parseIds($raw));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function mailbox(): \Modules\InboundMail\Mailbox\Mailbox
    {
        $mailbox = $this->mailboxes->findById($this->mailboxId);
        $this->assertNotNull($mailbox);

        return $mailbox;
    }

    private function createMailbox(string $name = 'Unité'): int
    {
        return $this->mailboxes->create(
            $name,
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            'contact@unite.be',
            'secret',
            [],
            true
        );
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $rows module, key, value
     */
    private function settingsWith(array $rows): SettingService
    {
        // The marker row exists on a real installation because module.json
        // declares it, and `SettingRepository::updateValue()` updates a row
        // rather than creating one. Seeding it here is mirroring the
        // manifest, not papering over anything.
        $rows[] = ['inbound_mail', MailboxScopeService::REPRISE_DONE_SETTING, ''];

        foreach ($rows as [$module, $key, $value]) {
            $this->pdo->prepare('DELETE FROM settings WHERE module_id = ? AND setting_key = ?')
                ->execute([$module, $key]);
            $stmt = $this->pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value, module_id, setting_type, label, description)
                 VALUES (?, ?, ?, \'text\', \'x\', \'x\')'
            );
            $stmt->execute([$key, $value, $module]);
        }

        return new SettingService(new SettingRepository($this->pdo));
    }
}
