<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Badge;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

#[Group('database')]
final class ReferentBadgeNamesOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private function service(\PDO $pdo): BadgeService
    {
        $connection = Connection::withPdo($pdo);
        $members = new MemberBadgeRepository($pdo);
        return new BadgeService(new BadgeRepository($pdo), $members, new SectionService(
            new SectionRepository($connection),
            new MemberProfileRepository($connection, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            $members
        ));
    }

    /**
     * @param array<string, string> $names
     * @return array<string, int>
     */
    private function sections(\PDO $pdo, array $names): array
    {
        $pdo->exec("INSERT INTO age_branches (desk_code, label) VALUES ('LOU', 'Louveteaux')");
        $branch = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO sections (desk_code, name, age_branch_id) VALUES (?, ?, ?)');
        $ids = [];
        foreach ($names as $code => $name) {
            $insert->execute([$code, $name, $branch]);
            $ids[$code] = (int) $pdo->lastInsertId();
        }
        return $ids;
    }

    public function testCollationEquivalentNamesDoNotOverwriteCustomBadges(): void
    {
        $pdo = $this->productionEngine();
        $sections = $this->sections($pdo, ['A' => 'Éclaireurs', 'B' => 'eclaireurs']);
        $repository = new BadgeRepository($pdo);
        $custom = $repository->create('Référent ÉCLAIREURS', false);
        $suffix = $repository->create('Référent Éclaireurs (A)', false);
        $service = $this->service($pdo);

        $service->syncSectionReferentBadges();
        $service->syncSectionReferentBadges();

        $this->assertSame('Référent Éclaireurs (A, 2)', $repository->findByReferentSectionId($sections['A'])->name);
        $this->assertSame('Référent eclaireurs (B)', $repository->findByReferentSectionId($sections['B'])->name);
        $this->assertSame('Référent ÉCLAIREURS', $repository->findById($custom)->name);
        $this->assertNull($repository->findById($custom)->referentSectionId);
        $this->assertSame('Référent Éclaireurs (A)', $repository->findById($suffix)->name);
        $this->assertCount(4, $repository->findAll());
    }

    public function testLongUnicodeNamesAndDeskCodesFitTheDeclaredColumn(): void
    {
        $pdo = $this->productionEngine();
        $first = 'A' . str_repeat('0', 49);
        $second = 'B' . str_repeat('0', 49);
        $sections = $this->sections($pdo, [$first => str_repeat('É🐺', 50), $second => str_repeat('É🐺', 50)]);
        $service = $this->service($pdo);

        $service->syncSectionReferentBadges();
        $service->syncSectionReferentBadges();

        $repository = new BadgeRepository($pdo);
        $names = [];
        foreach ($sections as $section) {
            $name = $repository->findByReferentSectionId($section)->name;
            $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
            $this->assertSame(100, mb_strlen($name, 'UTF-8'));
            $names[] = $name;
        }
        $this->assertCount(2, array_unique($names));
        $this->assertTrue(str_ends_with($names[0], '(' . $first . ')') || str_ends_with($names[1], '(' . $second . ')'));
    }
}
