<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Service\IncidentDecisionMigration;
use Modules\Rental\Stay\IncidentDecision;

/**
 * The conversion `withhold` → `charge` (#980, chantier §2.2), told once and
 * run on two engines: in-memory SQLite (`IncidentDecisionMigrationTest`)
 * and the one production runs (`IncidentDecisionMigrationOnMysqlTest`),
 * where `rowCount()` counts the rows CHANGED rather than matched.
 *
 * The class using it opens `$this->pdo` with the rental tables, `settings`
 * and `event_log`, then calls `setUpScenario()`.
 */
trait IncidentDecisionMigrationScenario
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalStayRepository $stay;
    private SettingService $settings;
    private int $bookingId;

    private function setUpScenario(): void
    {
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->stay = new RentalStayRepository($this->pdo, $this->encryption);
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->pdo->exec("INSERT INTO rental_assets (asset_type, name, slug) VALUES ('building', 'Local', 'local')");
        $created = (new RentalBookingRepository($this->pdo, $this->encryption))->create(
            (int) $this->pdo->lastInsertId(),
            'LOC-A2B3C4',
            '2027-07-17',
            '2027-07-20',
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => null,
                'organisation' => null,
                'purpose' => null,
                'comment' => null,
            ],
            null,
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );
        $this->bookingId = $created['id'];
    }

    public function testEveryWithheldIncidentBecomesAChargeOfTheSameAmountOnce(): void
    {
        $decided = $this->incident('Vitre cassée', 4500, 'withhold', 4000);
        $proposed = $this->incident('Chaise brisée', 3000, 'withhold', null);
        $charged = $this->incident('Porte rayée', 2000, 'charge', 1500);
        $waived = $this->incident('Tache', 1000, 'waive', null);
        $pending = $this->incident('Évier', 9000, 'pending', null);

        $this->assertSame(2, $this->migration()->runOnce());

        $this->assertSame([IncidentDecision::CHARGE, 4000], $this->read($decided));
        $this->assertSame([IncidentDecision::CHARGE, 3000], $this->read($proposed), 'the proposed amount, as read');
        $this->assertSame([IncidentDecision::CHARGE, 1500], $this->read($charged));
        $this->assertSame([IncidentDecision::WAIVE, null], $this->read($waived));
        $this->assertSame([IncidentDecision::PENDING, null], $this->read($pending));
        $this->assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM rental_incidents WHERE decision = 'withhold'"
        )->fetchColumn());
        $this->assertSame('1', (string) $this->settings->get(IncidentDecisionMigration::SETTING, 'rental'));

        // Run on every request afterwards: the flag is set, nothing moves —
        // not even a value put back by hand, which reads as a charge anyway.
        $this->pdo->prepare('UPDATE rental_incidents SET decision = ? WHERE id = ?')->execute(['withhold', $charged]);
        $this->assertSame(0, $this->migration()->runOnce());
        $this->assertSame(IncidentDecision::CHARGE, $this->stay->findIncident($charged)?->decision);
    }

    public function testTheStatementAloneIsIdempotent(): void
    {
        // The flag is an optimisation: without it, a second pass changes
        // no row and says so, on this engine's own count.
        $this->incident('Vitre cassée', 4500, 'withhold', null);

        $this->assertSame(1, $this->stay->convertWithheldIncidentsToCharges());
        $this->assertSame(0, $this->stay->convertWithheldIncidentsToCharges());
    }

    public function testASettlementIsNeverTouched(): void
    {
        // A version validated with a withholding keeps its figures and its
        // meaning: the total plus that withholding.
        $this->incident('Vitre cassée', 3000, 'withhold', null);
        $settlementId = $this->stay->createSettlement($this->bookingId, 1, null, [], 40000, 0, 40000, 3000, 47000, 7);
        $this->stay->validateSettlement($settlementId, 7);
        $before = $this->pdo->query('SELECT * FROM rental_settlements')->fetchAll(\PDO::FETCH_ASSOC);

        $this->migration()->runOnce();

        $this->assertSame($before, $this->pdo->query('SELECT * FROM rental_settlements')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertSame(43000, $this->stay->findSettlement($settlementId)?->keptCents());
    }

    public function testItJournalsACountAndNothingElse(): void
    {
        $this->incident('Vitre cassée par Zorglub', 3000, 'withhold', null);

        $this->migration()->runOnce();

        $rows = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'rental_incident_withhold_migrated'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame(['count' => 1], json_decode((string) $rows[0]['context'], true));
        $this->assertStringNotContainsString('Zorglub', (string) $rows[0]['description']);
        $this->assertStringNotContainsString('LOC-A2B3C4', (string) $rows[0]['description']);
    }

    public function testAnInstallationWithNothingToConvertStaysSilentAndTheFlagIsNotEditable(): void
    {
        $this->incident('Vitre cassée', 3000, 'charge', null);

        $this->assertSame(0, $this->migration()->runOnce());

        $this->assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'rental_incident_withhold_migrated'"
        )->fetchColumn());
        $row = (new SettingRepository($this->pdo))->findByModuleAndKey('rental', IncidentDecisionMigration::SETTING);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['editable']);
    }

    private function migration(): IncidentDecisionMigration
    {
        return new IncidentDecisionMigration(
            $this->stay,
            $this->settings,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function incident(string $description, int $proposed, string $decision, ?int $decided): int
    {
        $id = $this->stay->createIncident($this->bookingId, $description, $proposed, [], 7);
        $this->assertNotNull($id);
        $this->pdo->prepare('UPDATE rental_incidents SET decision = ?, decided_amount_cents = ? WHERE id = ?')
            ->execute([$decision, $decided, $id]);

        return $id;
    }

    /** @return array{0: IncidentDecision, 1: ?int} */
    private function read(int $id): array
    {
        $incident = $this->stay->findIncident($id);
        $this->assertNotNull($incident);

        return [$incident->decision, $incident->decidedAmountCents];
    }
}
