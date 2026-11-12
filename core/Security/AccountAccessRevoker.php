<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Contact\Device\DeviceCredentialRepository;
use Core\Journal\JournalService;

/**
 * Takes back the lasting ways into an account — its passkeys and its
 * contact-synchronisation device credentials — in one gesture (issue
 * #1115, audit SEC-018).
 *
 * A password reset used to end every open session and stop there. Someone
 * who had held a stolen session long enough to register a passkey or mint
 * a device credential of their own kept that, and signed straight back in
 * after the reset that was meant to throw them out. Two callers now use
 * this class:
 *
 * - **a password reset by link** (Core\Security\PasswordResetService):
 *   the reset page lists every live passkey and device, each ticked, and
 *   the holder unticks the ones they recognise;
 * - **« Déconnecter partout »** on « Mon compte »
 *   (Core\Http\Controller\AccountController): everything, no exception.
 *
 * **What is kept is named, never what is revoked.** revoke() is handed
 * the identifiers the holder chose to keep and takes back everything else
 * the account holds at that instant. A form that arrives without its list
 * (no JavaScript, a tampered request) therefore revokes everything, and so
 * does a passkey registered between the page loading and the form being
 * sent — the safe answer in both cases, since the holder never saw it.
 *
 * A passkey is deleted (the table has no « revoked » state and nothing
 * reads a dead one); a device credential is marked revoked, as it is
 * everywhere else, so the row stays as the trace that the device existed.
 *
 * One `security` journal entry per call, holding identifiers only — never
 * a label somebody typed (SECURITY.md §11).
 *
 * @phpstan-type Access array{id: int, label: string}
 * @phpstan-type LiveAccesses array{passkeys: list<Access>, devices: list<Access>}
 */
final class AccountAccessRevoker
{
    public const REASON_PASSWORD_RESET = 'password_reset';
    public const REASON_SIGN_OUT_EVERYWHERE = 'sign_out_everywhere';

    public function __construct(
        private WebAuthnCredentialRepository $passkeys,
        private DeviceCredentialRepository $devices,
        private JournalService $journalService
    ) {
    }

    /**
     * The account's passkeys and its device credentials that still work,
     * newest first, as the reset page shows them: an identifier and the
     * name the holder gave it, nothing else.
     *
     * @return LiveAccesses
     */
    public function liveAccesses(int $userAccountId): array
    {
        $passkeys = [];
        foreach ($this->passkeys->findByUserAccountId($userAccountId) as $row) {
            $passkeys[] = ['id' => (int) $row['id'], 'label' => (string) ($row['device_label'] ?? '')];
        }

        $devices = [];
        foreach ($this->devices->findAllForAccount($userAccountId) as $credential) {
            if (!$credential->isRevoked()) {
                $devices[] = ['id' => $credential->id, 'label' => $credential->label];
            }
        }

        return ['passkeys' => $passkeys, 'devices' => $devices];
    }

    /**
     * Revoke every passkey and every live device credential of the
     * account, except the ones named in the two keep lists. An identifier
     * in a keep list that is not the account's own is simply never
     * matched: it can neither keep nor revoke somebody else's access.
     *
     * @param list<int> $keepPasskeyIds
     * @param list<int> $keepDeviceIds
     * @param self::REASON_* $reason
     * @return array{passkeys: int, devices: int} how many of each were taken back
     */
    public function revoke(
        int $userAccountId,
        array $keepPasskeyIds,
        array $keepDeviceIds,
        string $reason,
        ?int $actorAccountId
    ): array {
        $live = $this->liveAccesses($userAccountId);

        $revokedPasskeys = [];
        foreach ($live['passkeys'] as $passkey) {
            if (!in_array($passkey['id'], $keepPasskeyIds, true)) {
                $this->passkeys->delete($passkey['id']);
                $revokedPasskeys[] = $passkey['id'];
            }
        }

        $revokedDevices = [];
        foreach ($live['devices'] as $device) {
            if (!in_array($device['id'], $keepDeviceIds, true)) {
                $this->devices->revoke($device['id']);
                $revokedDevices[] = $device['id'];
            }
        }

        $this->journalService->log(
            'core',
            'account_accesses_revoked',
            'security',
            $reason === self::REASON_PASSWORD_RESET
                ? 'Clés numériques et appareils retirés lors d\'une réinitialisation du mot de passe'
                : 'Déconnexion partout : sessions, clés numériques et appareils retirés',
            [
                'reason' => $reason,
                'user_account_id' => $userAccountId,
                'passkey_ids' => $revokedPasskeys,
                'device_credential_ids' => $revokedDevices,
                'kept_passkeys' => count($live['passkeys']) - count($revokedPasskeys),
                'kept_devices' => count($live['devices']) - count($revokedDevices),
            ],
            $actorAccountId
        );

        return ['passkeys' => count($revokedPasskeys), 'devices' => count($revokedDevices)];
    }
}
