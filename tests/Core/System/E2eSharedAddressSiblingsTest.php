<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

if (!defined('E2E_SUPPORT_TEST')) {
    define('E2E_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/e2e-support.php';

/**
 * The household tests/e2e/specs/mass-mail-shared-address.spec.js seeds
 * (scripts/e2e-support.php seed-shared-address-siblings), held as a
 * DESCRIPTOR — the same split as Tests\Core\System\E2eRoleAccountsTest:
 * the rows themselves are written into a real database by the scenario,
 * which fails on the first one that does not mean what it should. What a
 * unit test can hold is the shape the scenario's every count depends on,
 * and that a typo would change without anything failing to parse: three
 * DISTINCT addresses (the scenario expects exactly three messages), two
 * siblings, and a stamp that really makes a second household distinct.
 */
class E2eSharedAddressSiblingsTest extends TestCase
{
    public function testTheHouseholdHasTwoSiblingsAndThreeDistinctAddresses(): void
    {
        $fixture = \e2eSharedAddressSiblings('abc123');

        $this->assertCount(2, $fixture['siblings']);
        $addresses = [
            $fixture['family_email'],
            ...array_column($fixture['siblings'], 'own_email'),
        ];
        $this->assertSame($addresses, array_values(array_unique(array_map('strtolower', $addresses))));
        $this->assertCount(3, $addresses);
    }

    /** RFC 6761: a misconfigured run can never reach a real mailbox. */
    public function testEveryAddressIsUnroutable(): void
    {
        $fixture = \e2eSharedAddressSiblings('abc123');

        foreach ([$fixture['family_email'], ...array_column($fixture['siblings'], 'own_email')] as $address) {
            $this->assertStringEndsWith('@example.invalid', $address);
        }
    }

    /**
     * `--repeat-each` replays the scenario against one instance: a second
     * household must not reuse a section name (the list picker would be
     * ambiguous), a Desk id (members.desk_id, and the merge's Tiers), or an
     * address (the account login is unique).
     */
    public function testTwoStampsShareNoIdentifier(): void
    {
        $first = \e2eSharedAddressSiblings('run1');
        $second = \e2eSharedAddressSiblings('run2');

        $identifiers = static fn(array $fixture): array => [
            $fixture['section']['name'],
            $fixture['section']['desk_code'],
            $fixture['family_email'],
            ...array_column($fixture['siblings'], 'desk_id'),
            ...array_column($fixture['siblings'], 'own_email'),
        ];

        $this->assertSame([], array_intersect($identifiers($first), $identifiers($second)));
    }

    /**
     * The other fixtures are found by these codes, and Playwright's label
     * matching is by substring — a Desk id or section code that merely
     * starts with one of them would be found in their place.
     */
    public function testIdentifiersStayClearOfTheExistingFixtures(): void
    {
        $fixture = \e2eSharedAddressSiblings('abc123');
        $taken = ['E2E-ADMIN', 'E2E-MEMBER', 'E2E-FCT', 'E2E-CDU', 'E2E-SEC', 'E2E-BR'];

        foreach ([$fixture['section']['desk_code'], ...array_column($fixture['siblings'], 'desk_id')] as $code) {
            foreach ($taken as $existing) {
                $this->assertStringStartsNotWith($existing, $code);
            }
        }
        $this->assertStringNotContainsString('Meute E2E', $fixture['section']['name']);
    }
}
