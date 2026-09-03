<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Mail;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\Rental\Mail\MailboxSelection;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Choosing which of the unit's mailboxes this module listens to (§7.4).
 *
 * The boundary this file exists to pin: **what crosses it is a name and a
 * state**. A manager picking a box must be able to recognise it and must
 * not be able to reach it — no host, no port, no account, no password —
 * and that is a property of what the API returns, not of what a template
 * happens to print.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailboxSelectionTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingService = new SettingService(new SettingRepository($this->pdo));

        // The declaration ModuleManager would have installed from
        // module.json. Seeded here because SettingService refuses to write
        // a setting nothing declared — which is the behaviour that keeps a
        // typo'd key from silently becoming a new setting.
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'rental',
            MailboxSelection::SETTING_KEY,
            '',
            'text',
            'Boîtes surveillées',
            'Identifiants des boîtes surveillées.',
        ]);
    }

    /**
     * @param array<int, array{name: string, state: string, is_enabled: bool}> $summaries
     */
    private function inboundMail(array $summaries): InboundMailInterface
    {
        return new class ($summaries) implements InboundMailInterface {
            use \Tests\Modules\InboundMail\InertInboundMail;

            /**
             * @param array<int, array{name: string, state: string, is_enabled: bool}> $summaries
             */
            public function __construct(private array $summaries)
            {
            }

            public function findForReference(string $consumerId, string $businessReference): array
            {
                return [];
            }

            public function findOneForReference(
                string $consumerId,
                string $businessReference,
                int $messageId
            ): ?InboundMessage {
                return null;
            }

            public function detach(
                string $consumerId,
                string $businessReference,
                int $messageId,
                array $preserveFileIds = []
            ): bool {
                return false;
            }

            public function move(
        string $consumerId,
        string $fromReference,
        string $toReference,
        int $messageId,
        ?int $userAccountId = null
    ): bool
            {
                return false;
            }

            public function purgeReference(string $consumerId, string $businessReference): int
            {
                return 0;
            }

            public function isCollecting(): bool
            {
                return $this->summaries !== [];
            }

            public function findReferenceByThread(string $consumerId, int $mailboxId, array $messageIds): ?string
            {
                return null;
            }

            public function listMailboxSummaries(): array
            {
                return $this->summaries;
            }
        };
    }

    private function selection(?InboundMailInterface $inboundMail): MailboxSelection
    {
        return new MailboxSelection($this->settingService, $inboundMail);
    }

    private static function summaries(): array
    {
        return [
            3 => ['name' => 'Locations', 'state' => 'Synchronisée', 'is_enabled' => true],
            7 => ['name' => 'Secrétariat', 'state' => 'En erreur', 'is_enabled' => true],
        ];
    }

    public function testWithoutTheModuleNothingIsOffered(): void
    {
        $selection = $this->selection(null);

        $this->assertFalse($selection->isAvailable());
        $this->assertSame([], $selection->availableMailboxes());
        $this->assertSame([], $selection->selectedIds());
    }

    public function testAnEmptySelectionMeansEveryMailbox(): void
    {
        // Most units have one box; asking them to tick it before anything
        // works would be a configuration step whose only sensible answer is
        // "yes", and one whose omission would look like a broken sync.
        $this->assertSame([], $this->selection($this->inboundMail(self::summaries()))->selectedIds());
    }

    public function testASelectionRoundTrips(): void
    {
        $selection = $this->selection($this->inboundMail(self::summaries()));

        $selection->save([3, 7]);

        $this->assertSame([3, 7], $selection->selectedIds());
    }

    public function testAnIdThatIsNotAConfiguredMailboxIsDropped(): void
    {
        // A stale id from a deleted box would otherwise silently narrow the
        // selection to nothing a manager could see or undo.
        $selection = $this->selection($this->inboundMail(self::summaries()));

        $selection->save([3, 999]);

        $this->assertSame([3], $selection->selectedIds());
    }

    public function testRubbishInTheFormIsDroppedRatherThanStored(): void
    {
        $selection = $this->selection($this->inboundMail(self::summaries()));

        $selection->save(['3', 'nimporte quoi', '-1', '0']);

        $this->assertSame([3], $selection->selectedIds());
    }

    public function testTheSameIdTwiceIsStoredOnce(): void
    {
        $selection = $this->selection($this->inboundMail(self::summaries()));

        $selection->save([3, 3, 3]);

        $this->assertSame([3], $selection->selectedIds());
    }

    public function testSavingNothingGoesBackToEveryMailbox(): void
    {
        $selection = $this->selection($this->inboundMail(self::summaries()));
        $selection->save([3]);

        $selection->save([]);

        $this->assertSame([], $selection->selectedIds());
    }

    public function testAManagerOnlyEverLearnsTheNameAndTheState(): void
    {
        $selection = $this->selection($this->inboundMail(self::summaries()));

        foreach ($selection->availableMailboxes() as $mailbox) {
            $this->assertSame(['name', 'state', 'is_enabled'], array_keys($mailbox));
        }
    }
}
