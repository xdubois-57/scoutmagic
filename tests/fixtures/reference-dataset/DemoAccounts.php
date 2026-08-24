<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;

/**
 * The login accounts a person needs to walk around the built instance, one per
 * role the site distinguishes.
 *
 * **These passwords are published in `README.md`, on purpose.** They are
 * demonstration credentials for a throwaway instance, not secrets — and saying
 * so out loud is the only honest way to ship them. An instance built with this
 * dataset is not a production instance; the README says that in its first
 * paragraph.
 *
 * Most of these accounts already exist by the time this runs:
 * DeskImportService::ensureUserAccount() creates one for every member whose
 * Desk export carries an email. This class does not create a parallel identity
 * for them — it finds the account behind a Tiers's email and gives it a
 * password, so that logging in as "the chief of Seeonee" logs in as the actual
 * member, with their real functions and their real role.
 *
 * The role each account ends up with is therefore NOT set here. It is derived
 * by the site from that member's confirmed functions
 * (Core\Security\RoleResolver), which is exactly what the dataset is meant to
 * demonstrate.
 */
final class DemoAccounts
{
    /**
     * The one account with no member behind it: the installation's own
     * superadmin, which every ScoutMagic install has and which no Desk export
     * ever produces.
     */
    public const SUPERADMIN_EMAIL = 'superadmin@example.com';

    /** One password for all of them: nothing here is protecting anything. */
    public const PASSWORD = 'Reference-Dataset-2026!';

    /**
     * Which member to hand each demonstration role to, by the Tiers pinned in
     * ScenarioCatalog — so the accounts stay tied to the people whose stories
     * the dataset tells.
     *
     * @var array<string, array{tiers: string, role: string, why: string}>
     */
    public const MEMBER_ACCOUNTS = [
        'chef_unite' => [
            'tiers' => 'T0015',
            'role' => 'admin',
            'why' => 'Animateur en A1, Chef d\'unité ensuite — le parcours du scénario 10, et le membre par qui Staff d\'U se peuple.',
        ],
        'intendant' => [
            'tiers' => 'T0016',
            'role' => 'intendant',
            'why' => 'Intendant d\'unité les trois années (scénario 11) : le rôle qui voit les Finances sans être chef.',
        ],
        'chef_section' => [
            'tiers' => 'T0014',
            'role' => 'chief',
            'why' => 'Animateur qui change de section entre A1 et A2 (scénario 9) : deux staffs à voir depuis un seul compte.',
        ],
        'anime' => [
            'tiers' => 'T0012',
            'role' => 'identified',
            'why' => 'Éclaireur qui gagne son totem entre A1 et A2 (scénario 7) : une fiche membre qui change d\'une année à l\'autre.',
        ],
        'parent' => [
            'tiers' => 'T0020',
            'role' => 'identified',
            'why' => 'Aîné d\'une fratrie de trois partageant une adresse email de parent (scénario 17) : un compte pour plusieurs enfants.',
        ],
    ];

    /**
     * @param array<string, Person> $people the dataset's people, keyed by Tiers
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly EncryptionService $encryption,
        private readonly array $people,
    ) {
    }

    /**
     * Create the superadministrator and return its id.
     *
     * **Called before the imports, not after.** Every Desk import writes an
     * `import_journal` row crediting an account, and that column carries a
     * foreign key to `user_accounts`. Passing a hard-coded `1` works on a
     * freshly installed instance by coincidence — the first account created is
     * usually id 1 — and fails outright on any instance whose accounts were
     * ever renumbered. The builder therefore creates this account first and
     * credits the imports to it, which is also what actually happens: it is
     * the superadministrator who runs a Desk import.
     */
    public function ensureSuperadmin(): int
    {
        $repository = new UserAccountRepository($this->pdo, $this->encryption);

        $superadmin = $repository->findByEmail(self::SUPERADMIN_EMAIL)
            ?? $repository->create(self::SUPERADMIN_EMAIL, true);

        $repository->updatePasswordHash($superadmin->id, password_hash(self::PASSWORD, PASSWORD_DEFAULT));
        $repository->updateProfile($superadmin->id, 'Super', 'Administrateur');

        return $superadmin->id;
    }

    /**
     * Give every member-backed demonstration account a password.
     *
     * Called after the imports, because these accounts do not exist before
     * them: DeskImportService::ensureUserAccount() creates one for every
     * member whose export carries an email.
     *
     * @return array<string, string> handle => the email actually used
     */
    public function seedMemberAccounts(): array
    {
        $repository = new UserAccountRepository($this->pdo, $this->encryption);
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $used = ['superadmin' => self::SUPERADMIN_EMAIL];

        foreach (self::MEMBER_ACCOUNTS as $handle => $account) {
            $email = $this->emailOfTiers($account['tiers']);
            if ($email === null) {
                // A member whose export carries no email has no account, and
                // this is worth saying rather than silently skipping: the
                // handle simply has no login on this instance.
                continue;
            }

            $user = $repository->findByEmail($email);
            if ($user === null) {
                continue;
            }

            $repository->updatePasswordHash($user->id, $hash);
            $used[$handle] = $email;
        }

        return $used;
    }

    /**
     * The email a Tiers carries in the dataset, taken from the generator
     * rather than from the database: the database holds it encrypted behind a
     * blind index, and the generator is the authority on what it contains
     * anyway.
     *
     * Lowercased, because that is what DeskImportService::importMember()
     * normalised before creating the account.
     */
    private function emailOfTiers(string $tiers): ?string
    {
        $email = ($this->people[$tiers] ?? null)?->email;

        return $email !== null ? strtolower($email) : null;
    }
}
