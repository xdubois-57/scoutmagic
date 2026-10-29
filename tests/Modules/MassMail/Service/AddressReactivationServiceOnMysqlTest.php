<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Tests\UsesProductionEngine;

/** Changed-row counts and transactions, on the real declared production schema. */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class AddressReactivationServiceOnMysqlTest extends AddressReactivationServiceTest
{
    use UsesProductionEngine;

    protected function database(): \PDO
    {
        return $this->productionEngine();
    }
    public function testTwoWorkersCannotRestoreOrJournalTheSameAddressTwice(): void
    {
        $other = self::productionEngineConnection(self::$productionEngineDatabase)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $owner = $this->serviceOn($this->pdo);
        $contender = $this->serviceOn($other);
        $this->pdo->beginTransaction();
        try {
            $this->assertSame(5, $owner->reactivate('shared@example.org', null));
            try {
                $contender->reactivate('shared@example.org', null);
                $this->fail('The first transaction must hold the address lock.');
            } catch (\Core\Member\MemberEmailException $error) {
                $cause = $error->getPrevious();
                $this->assertInstanceOf(\PDOException::class, $cause);
                $this->assertSame(1205, $cause->errorInfo[1]);
            }
        } finally {
            $this->pdo->commit();
        }
        $this->assertSame(0, $contender->reactivate('shared@example.org', null));
        $this->assertSame(2, (int) $other->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'member_email_reactivated'"
        )->fetchColumn());
        $this->assertSame(1, (int) $other->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'mailing_address_reactivated'"
        )->fetchColumn());
    }

    private function serviceOn(\PDO $pdo): \Core\Member\EmailAddressReactivationService
    {
        $encryption = new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($pdo));
        return new \Core\Member\EmailAddressReactivationService(
            new \Core\Member\MemberEmailRepository($pdo, $encryption), $journal,
            new \Modules\MassMail\Service\AddressReactivationService($pdo,
                new \Modules\MassMail\Repository\ContactRepository($pdo, $encryption),
                new \Modules\MassMail\Repository\SuppressedAddressRepository($pdo), $journal)
        );
    }

}
