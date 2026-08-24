<?php

declare(strict_types=1);

namespace Tests\Core\System;

use Core\Security\Role;
use PHPUnit\Framework\TestCase;

if (!defined('E2E_SUPPORT_TEST')) {
    define('E2E_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/e2e-support.php';

/**
 * scripts/e2e-support.php declares no namespace (it is a CLI script the
 * shell harness runs directly), so the functions under test live in the
 * global namespace — hence the leading backslash, same as
 * Tests\Core\System\E2eActivationOrderTest.
 *
 * What is pinned here is the DESCRIPTOR, not the seeding: the rows are
 * written against a real MySQL database inside a provisioning run, and
 * that run already fails closed if any of the five accounts does not
 * resolve to its intended role (e2e_assert_resolved_roles()). What a
 * unit test can hold is the part a typo would quietly break without
 * anything else noticing — the role ladder being complete, the addresses
 * being unroutable, and the environment prefixes not colliding with the
 * two accounts that already exist.
 */
class E2eRoleAccountsTest extends TestCase
{
    public function testEveryRungOfTheLadderBetweenIdentifiedAndSuperadminIsCovered(): void
    {
        $roles = array_map(
            static fn(array $account): Role => $account['role'],
            \e2e_role_accounts()
        );

        // identified and superadmin come from the two accounts the
        // browser suite already provisions; these three are the rest.
        $this->assertSame([Role::INTENDANT, Role::CHIEF, Role::ADMIN], $roles);
    }

    public function testTogetherWithTheTwoExistingAccountsEveryNonPublicRoleIsRepresented(): void
    {
        $covered = array_map(
            static fn(array $account): string => $account['role']->value,
            \e2e_role_accounts()
        );
        $covered[] = Role::IDENTIFIED->value;
        $covered[] = Role::SUPERADMIN->value;
        sort($covered);

        $expected = array_values(array_map(
            static fn(Role $role): string => $role->value,
            array_filter(Role::cases(), static fn(Role $role): bool => $role !== Role::PUBLIC)
        ));
        sort($expected);

        $this->assertSame($expected, $covered);
    }

    /**
     * .invalid is reserved by RFC 6761 and resolves nowhere, so a
     * misconfigured run can never send mail to a real mailbox.
     */
    public function testEveryDefaultAddressIsUnroutable(): void
    {
        foreach (\e2e_role_accounts() as $account) {
            $this->assertStringEndsWith('@example.invalid', $account['default_email']);
        }
    }

    /**
     * E2E_ADMIN_* has named the SUPER-admin since long before roles were
     * provisioned; the `admin`-role account must not quietly take those
     * variables over.
     */
    public function testNoEnvironmentPrefixCollidesWithTheTwoExistingAccounts(): void
    {
        $prefixes = array_map(
            static fn(array $account): string => $account['env_prefix'],
            \e2e_role_accounts()
        );

        $this->assertNotContains('E2E_ADMIN', $prefixes);
        $this->assertNotContains('E2E_MEMBER', $prefixes);
        $this->assertSame($prefixes, array_unique($prefixes));
    }

    public function testEveryIdentifierIsDistinctSoNoTwoAccountsOverwriteEachOther(): void
    {
        $accounts = \e2e_role_accounts();

        foreach (['key', 'env_prefix', 'default_email', 'desk_id', 'function_code'] as $field) {
            $values = array_map(static fn(array $account): string => $account[$field], $accounts);
            $this->assertSame($values, array_unique($values), "duplicate {$field} in e2e_role_accounts()");
        }
    }

    /**
     * The desk ids and function codes the seeder writes must not collide
     * with the ones the two existing fixtures already use, or a seeder
     * would silently attach a role to the wrong member.
     */
    public function testIdentifiersDoNotCollideWithTheExistingFixtures(): void
    {
        $taken = ['E2E-ADMIN', 'E2E-MEMBER', 'E2E-FCT', 'E2E-CDU', 'E2E-SEC', 'E2E-BR'];

        foreach (\e2e_role_accounts() as $account) {
            $this->assertNotContains($account['desk_id'], $taken);
            $this->assertNotContains($account['function_code'], $taken);
        }
    }

    /**
     * Config Desk labels each of its controls with a Desk code —
     * "Rôle pour <code>", "Nom de la section <code>" — and Playwright's
     * getByLabel() matches on a SUBSTRING. A code that merely STARTS
     * WITH one an existing fixture uses therefore makes
     * tests/e2e/specs/config-desk.spec.js's own locators resolve to
     * several elements and fail on strict mode. That is exactly what
     * `E2E-FCT-INT` and `E2E-SEC-ROLES` each did, on two separate runs.
     *
     * Prefix-freedom in BOTH directions, not just uniqueness, is the
     * property that matters, and it has to cover every code the fixture
     * writes — the section and the age branch as much as the functions.
     */
    public function testNoDeskCodeSharesAPrefixWithAnExistingFixturesCode(): void
    {
        $existing = ['E2E-FCT', 'E2E-CDU', 'E2E-SEC', 'E2E-BR', 'E2E-ADMIN', 'E2E-MEMBER', 'STAFFDU'];

        $ours = array_map(
            static fn(array $account): string => $account['function_code'],
            \e2e_role_accounts()
        );
        foreach (\e2e_role_accounts() as $account) {
            $ours[] = $account['desk_id'];
        }
        $ours = array_merge($ours, array_values(\e2e_role_fixture_codes()));

        foreach ($ours as $code) {
            foreach ($existing as $taken) {
                $this->assertFalse(
                    str_starts_with($code, $taken),
                    "{$code} starts with {$taken}, which makes Config Desk's label for it ambiguous "
                    . 'with the one the existing end-to-end scenario already targets'
                );
                $this->assertFalse(
                    str_starts_with($taken, $code),
                    "{$taken} starts with {$code}, same ambiguity in the other direction"
                );
            }
        }
    }

    /**
     * A function label is user-facing French (AGENTS.md § Language) and
     * NOT NULL in the schema.
     */
    public function testEveryFunctionCarriesALabelAndAName(): void
    {
        foreach (\e2e_role_accounts() as $account) {
            $this->assertNotSame('', trim($account['function_label']));
            $this->assertNotSame('', trim($account['first_name']));
            $this->assertNotSame('', trim($account['last_name']));
        }
    }

    public function testTheAddressComesFromTheEnvironmentWhenSetAndIsNormalised(): void
    {
        $account = \e2e_role_accounts()[0];
        $variable = $account['env_prefix'] . '_EMAIL';
        $previous = getenv($variable);

        try {
            putenv("{$variable}=  MiXeD@Example.Invalid  ");
            $this->assertSame('mixed@example.invalid', \e2e_role_account_email($account));

            putenv("{$variable}=");
            $this->assertSame($account['default_email'], \e2e_role_account_email($account));
        } finally {
            if ($previous === false) {
                putenv($variable);
            } else {
                putenv("{$variable}={$previous}");
            }
        }
    }
}
