<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * A change to the ways into an account that its holder must hear about
 * (issue #1115, audit SEC-018): each one is a way an attacker holding a
 * stolen session could make that access outlive the session.
 *
 * What the holder reads, and what the journal records, is decided here
 * once, so the e-mail, the in-app notification and the journal entry can
 * never describe the same event in three different ways.
 */
enum AccountSecurityChange: string
{
    case PasswordSet = 'password_set';
    case PasswordChanged = 'password_changed';
    case PasskeyAdded = 'passkey_added';
    case PasskeyRemoved = 'passkey_removed';
    case DeviceCredentialCreated = 'device_credential_created';

    /**
     * The sentence the holder reads, in the e-mail and in the
     * notification alike.
     */
    public function label(): string
    {
        return match ($this) {
            self::PasswordSet => 'Un mot de passe a été défini pour votre compte',
            self::PasswordChanged => 'Le mot de passe de votre compte a été modifié',
            self::PasskeyAdded => 'Une clé d\'accès (passkey) a été ajoutée à votre compte',
            self::PasskeyRemoved => 'Une clé d\'accès (passkey) a été retirée de votre compte',
            self::DeviceCredentialCreated => 'Un identifiant d\'appareil pour la synchronisation des contacts '
                . 'a été créé pour votre compte',
        };
    }

    /**
     * The journal entry the alert writes, or null when the action already
     * writes its own: Core\Contact\Device\DeviceCredentialService journals
     * `device_credential_created` itself, and a second line for the same
     * event would only make the journal harder to read.
     *
     * @return array{action: string, description: string}|null
     */
    public function journalEntry(): ?array
    {
        return match ($this) {
            self::PasswordSet => [
                'action' => 'password_set',
                'description' => 'Mot de passe défini depuis « Mon compte »',
            ],
            self::PasswordChanged => [
                'action' => 'password_changed',
                'description' => 'Mot de passe modifié depuis « Mon compte »',
            ],
            self::PasskeyAdded => [
                'action' => 'passkey_registered',
                'description' => 'Clé d\'accès (passkey) ajoutée depuis « Mon compte »',
            ],
            self::PasskeyRemoved => [
                'action' => 'passkey_deleted',
                'description' => 'Clé d\'accès (passkey) retirée depuis « Mon compte »',
            ],
            self::DeviceCredentialCreated => null,
        };
    }
}
