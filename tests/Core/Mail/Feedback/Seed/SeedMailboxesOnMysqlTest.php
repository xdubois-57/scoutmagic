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
use Tests\UsesProductionEngine;

/**
 * Claiming the seed boxes of a mailing against the REAL engine, because
 * the refusal it is about cannot be produced on SQLite.
 *
 * `SeedCopyRepository::claim()` answers `false` for the unique index and
 * rethrows everything else. On SQLite a trigger's `RAISE()` carries the
 * same driver code as a unique violation (19), so a refused write there
 * reads as « already claimed » and never reaches the caller's catch. A
 * MariaDB or MySQL trigger signals `45000`, which is what a database
 * refusing one row looks like where the site runs.
 */
#[Group('database')]
class SeedMailboxesOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
    }

    /**
     * **One box that cannot be written down costs its own measurement and
     * nothing else.** The box in the middle is refused; the one after it
     * is still claimed, and no exception reaches the mailing that asked.
     */
    public function testABoxWhoseCopyCannotBeWrittenIsSkippedAndTheOthersAreClaimed(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER refuse_outlook_copies BEFORE INSERT ON mail_seed_copies FOR EACH ROW
             BEGIN
                 IF NEW.provider = 'outlook.com' THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'copie refusée';
                 END IF;
             END"
        );

        try {
            $claimed = $this->seedMailboxes(['temoin@gmail.com', 'temoin@outlook.com', 'temoin@laposte.net'])
                ->claimFor('mass_mail:12', new \DateTimeImmutable());
        } finally {
            $this->pdo->exec('DROP TRIGGER refuse_outlook_copies');
        }

        $this->assertSame(['temoin@gmail.com', 'temoin@laposte.net'], $claimed);
        $this->assertSame(
            ['gmail.com', 'laposte.net'],
            $this->pdo->query('SELECT provider FROM mail_seed_copies ORDER BY provider')->fetchAll(\PDO::FETCH_COLUMN),
            'A copy is owed to each box written down, and only to those.'
        );
    }

    /**
     * @param list<string> $boxes
     */
    private function seedMailboxes(array $boxes): SeedMailboxes
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register(SeedMailboxes::SETTING_ENABLED, '0', 'boolean', 'Boîtes témoins', '');
        $settings->setInternal(SeedMailboxes::SETTING_ENABLED, '1');

        $module = $this->createStub(InboundMailInterface::class);
        $module->method('probeAddressesFor')->willReturn($boxes);

        return new SeedMailboxes(
            new SeedCopyRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            $settings,
            $module
        );
    }
}
