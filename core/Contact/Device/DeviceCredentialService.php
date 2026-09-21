<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\Device;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Security\UserAccountRepository;

/**
 * Creating, listing and revoking the credentials an address-book client
 * synchronises with — and the site-wide switch that stops all of them at
 * once.
 *
 * **The journal records the lifecycle and never a synchronisation.** A
 * client polls every few minutes; a successful sync in the journal would
 * be thousands of entries a week burying everything else in it. What is
 * worth reading back is a credential created, a credential revoked, and
 * an authentication that failed — which is what
 * {@see DeviceAuthenticator} writes.
 *
 * No entry ever carries a secret, a label, an e-mail address or a
 * member's name: an identifier and an account id, and nothing else.
 */
class DeviceCredentialService
{
    /** The site-wide switch. On by default: it is a cut-out, not an opt-in. */
    public const SETTING_SYNC_ENABLED = 'contact_sync_enabled';

    /**
     * Per account. Not a security boundary — every one of them belongs to
     * the same person and dies with their role — but a bound on a list a
     * screen has to stay readable with, and on a table anybody signed in
     * as admin could otherwise grow without limit.
     */
    public const MAX_PER_ACCOUNT = 10;

    public const MAX_LABEL_LENGTH = 100;

    public function __construct(
        private DeviceCredentialRepository $repository,
        private SettingService $settingService,
        private JournalService $journalService,
        /**
         * Only to name the owner of each device on the superadmin's page.
         * Trailing and optional like every other late collaborator in
         * this codebase: without it the page names accounts by their id,
         * which is honest rather than broken.
         */
        private ?UserAccountRepository $userAccountRepository = null
    ) {
    }

    public function isSyncEnabled(): bool
    {
        return (string) ($this->settingService->get(self::SETTING_SYNC_ENABLED) ?? '1') === '1';
    }

    public function setSyncEnabled(bool $enabled, ?int $actorAccountId): void
    {
        $this->settingService->setInternal(self::SETTING_SYNC_ENABLED, $enabled ? '1' : '0');

        $this->journalService->log(
            'core',
            $enabled ? 'contact_sync_enabled' : 'contact_sync_disabled',
            'security',
            $enabled
                ? 'Synchronisation des contacts réactivée pour tout le site'
                : 'Synchronisation des contacts coupée pour tout le site',
            [],
            $actorAccountId
        );
    }

    /**
     * @return list<DeviceCredential>
     */
    public function listForAccount(int $userAccountId): array
    {
        return $this->repository->findAllForAccount($userAccountId);
    }

    /**
     * @return list<DeviceCredential>
     */
    public function listAll(): array
    {
        return $this->repository->findAll();
    }

    /**
     * Every credential of every account, each paired with its owner's
     * name — what the superadmin's page renders, assembled here so the
     * controller asks one collaborator instead of joining two
     * (`ARCHITECTURE.md` § Layered MVC).
     *
     * The names are resolved in ONE query for the whole page, never one
     * per credential, and only the two name columns are decrypted: an
     * address is never read on this path.
     *
     * @return array{credentials: list<DeviceCredential>, owners: array<int, string>}
     */
    public function listAllWithOwners(): array
    {
        $credentials = $this->repository->findAll();

        if ($this->userAccountRepository === null || $credentials === []) {
            return ['credentials' => $credentials, 'owners' => []];
        }

        $names = $this->userAccountRepository->findNamesByIds(array_values(array_unique(array_map(
            static fn(DeviceCredential $credential): int => $credential->userAccountId,
            $credentials
        ))));

        $owners = [];
        foreach ($names as $accountId => $name) {
            $display = trim(((string) ($name['first_name'] ?? '')) . ' ' . ((string) ($name['last_name'] ?? '')));
            // An account that has never filled its profile in is named by
            // its id rather than by an empty string, so every row of the
            // page says whose device it is.
            $owners[$accountId] = $display !== '' ? $display : 'Compte ' . $accountId;
        }

        return ['credentials' => $credentials, 'owners' => $owners];
    }

    /**
     * The credential $id, but only when it belongs to $userAccountId —
     * null for anything else, so a caller cannot tell "not yours" from
     * "does not exist" and cannot enumerate other people's devices.
     */
    public function findOwned(int $id, int $userAccountId): ?DeviceCredential
    {
        $credential = $id > 0 ? $this->repository->findById($id) : null;

        return $credential !== null && $credential->userAccountId === $userAccountId ? $credential : null;
    }

    public function findAny(int $id): ?DeviceCredential
    {
        return $id > 0 ? $this->repository->findById($id) : null;
    }

    public function canCreateFor(int $userAccountId): bool
    {
        return $this->repository->countLiveForAccount($userAccountId) < self::MAX_PER_ACCOUNT;
    }

    /**
     * 32 bytes from `random_bytes()`, hex-encoded so it can be typed or
     * pasted into a client's password field, returned here **once** and
     * never recoverable afterwards — the same treatment as the GitHub
     * webhook secret (`MaintenanceController::generateWebhookSecret()`,
     * ARCHITECTURE.md §8.17).
     */
    public function create(int $userAccountId, string $label, ?int $actorAccountId): NewDeviceCredential
    {
        $secret = bin2hex(random_bytes(32));
        $created = $this->repository->create($userAccountId, $this->normaliseLabel($label), $secret);

        // **The secret is handed back before anything else is attempted.**
        // It exists in exactly one place and cannot be recovered, so the
        // row is created and returned in one step — no re-read that could
        // fail, and no work between the insert and the value the caller
        // needs. The journal entry comes after, because an audit line that
        // failed to write is a gap in the journal; a secret lost between
        // the insert and the response is a credential nobody can ever use,
        // occupying one of this account's slots forever.
        $this->journalService->log(
            'core',
            'device_credential_created',
            'security',
            'Identifiant d\'appareil créé pour la synchronisation des contacts',
            ['credential_id' => $created->id, 'user_account_id' => $userAccountId],
            $actorAccountId
        );

        return new NewDeviceCredential($created, $secret);
    }

    /**
     * Revoking never deletes and never reaches the device: it stops the
     * site answering, and the copy already pulled down stays where it is.
     * The screen says so in those words.
     */
    public function revoke(DeviceCredential $credential, ?int $actorAccountId, bool $bySuperAdmin = false): void
    {
        $this->repository->revoke($credential->id);

        $this->journalService->log(
            'core',
            'device_credential_revoked',
            'security',
            $bySuperAdmin
                ? 'Identifiant d\'appareil révoqué par un superadministrateur'
                : 'Identifiant d\'appareil révoqué par son propriétaire',
            ['credential_id' => $credential->id, 'user_account_id' => $credential->userAccountId],
            $actorAccountId
        );
    }

    /**
     * A device name its owner types. Trimmed, bounded, and given a
     * fallback rather than refused — a nameless device in the list is
     * worse than a generically named one.
     */
    private function normaliseLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);

        return $label === ''
            ? 'Appareil sans nom'
            : mb_substr($label, 0, self::MAX_LABEL_LENGTH);
    }
}
