<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingException;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Mail\MailService;

/**
 * « Copie des e-mails d'inscription » (chantier #843, D9 / IT-06): which
 * address, if any, receives a copy (`Cc:`) of the three e-mails the unit
 * writes to a family — the receipt (Service\RegistrationService), the
 * acceptance and the refusal (Service\RequestEmailService), resends
 * included, since a resend goes through the same send.
 *
 * **On by default, and the default address is the unit's own**: the core
 * setting « Email de contact » (`contact_email`), the address the Contact
 * page publishes. An address typed in the box replaces it.
 *
 * Deliberately NOT copied, each for its reason (the module's journal
 * lists them too): the unit alert, which already goes to the unit; the
 * reenrollment e-mails, which D9 leaves out; and the confirmation of a
 * secondary tracking address, whose link confirms an address that then
 * sees the whole request — a copy would hand that capability to a shared
 * mailbox.
 *
 * Two moments, two postures. **Saving refuses** a switched-on copy whose
 * address is invalid or cannot be resolved, so the box never stores a
 * setting that cannot work — and both settings are `editable: false`, so
 * the box is the only place they are written and its rule holds
 * everywhere. **Sending never fails over it**: the unit address may be
 * cleared later on another page, and a family's receipt is not held
 * hostage to the unit's own copy — the e-mail leaves without one, and the
 * journal says so (without the address). A copy the relay itself refuses
 * is the core's to handle: the message still counts as sent, and
 * `mail_copy_refused` is journaled (Core\Mail\PhpMailerTransport).
 */
class FamilyEmailCopyService
{
    public const SETTING_ENABLED = 'registration_email_cc_enabled';
    public const SETTING_ADDRESS = 'registration_email_cc_address';

    /** The core setting holding the unit's own address (Contact page). */
    public const SETTING_UNIT_ADDRESS = 'contact_email';

    public const MESSAGE_INVALID = "Cette adresse n'est pas valide. Rien n'a été enregistré.";
    public const MESSAGE_UNRESOLVABLE = "L'unité n'a pas d'adresse e-mail par défaut (« Email de contact », "
        . "page Paramètres) : indiquez l'adresse à mettre en copie, ou coupez l'option. Rien n'a été "
        . "enregistré.";

    public function __construct(
        private SettingService $settingService,
        private ?JournalService $journalService = null
    ) {
    }

    public function isEnabled(): bool
    {
        return (string) $this->settingService->get(self::SETTING_ENABLED, 'registration', '1') === '1';
    }

    /** What the box holds — empty means « the unit's address ». */
    public function configuredAddress(): string
    {
        return trim((string) $this->settingService->get(self::SETTING_ADDRESS, 'registration', ''));
    }

    /** The unit's own address, the default when the box is empty. */
    public function unitAddress(): string
    {
        return trim((string) $this->settingService->get(self::SETTING_UNIT_ADDRESS));
    }

    /**
     * What the box on « Réglages » shows: the switch, the typed address,
     * the unit's own (the field's placeholder), and whether a switched-on
     * copy can actually be sent — false when the unit's address has been
     * cleared since, on another page, which the box then says.
     *
     * @return array{enabled: bool, address: string, unit_address: string, resolvable: bool}
     */
    public function boxState(): array
    {
        $address = $this->configuredAddress();

        return [
            'enabled' => $this->isEnabled(),
            'address' => $address,
            'unit_address' => $this->unitAddress(),
            'resolvable' => $this->resolve($address) !== null,
        ];
    }

    /**
     * The address to put in copy of an e-mail to a family, or null for
     * none: the option is off, or no valid address can be resolved — in
     * which case the e-mail leaves without a copy and the journal says so.
     */
    public function copyAddress(): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $address = $this->resolve($this->configuredAddress());
        if ($address === null) {
            $this->journalService?->log(
                'registration',
                'registration_email_copy_unresolved',
                'warning',
                "E-mail à la famille envoyé sans copie : aucune adresse en copie valide n'est configurée"
            );
        }

        return $address;
    }

    /**
     * Saves the box as one decision — the switch and the address together,
     * or nothing.
     *
     * @param string|null $address What was typed; null when the field was
     *        not submitted (disabled while the switch is off), which keeps
     *        the stored address as it is.
     * @throws RegistrationException with a French sentence, nothing saved
     */
    public function save(bool $enabled, ?string $address): void
    {
        $address = $address === null ? $this->configuredAddress() : trim($address);

        if ($address !== '' && !self::isValidAddress($address)) {
            throw new RegistrationException(self::MESSAGE_INVALID);
        }
        if ($enabled && $this->resolve($address) === null) {
            throw new RegistrationException(self::MESSAGE_UNRESOLVABLE);
        }

        try {
            // Internal: both settings are `editable: false`, so this box —
            // which checks them together — is the only way to write them.
            $this->settingService->setManyInternal([
                self::SETTING_ENABLED => $enabled ? '1' : '0',
                self::SETTING_ADDRESS => $address,
            ], 'registration');
        } catch (SettingException $e) {
            throw new RegistrationException(
                "Les réglages n'ont pas pu être enregistrés — rechargez la page et réessayez.",
                0,
                $e
            );
        }
    }

    /**
     * The rule Core\Mail\MailService applies to a copy address itself —
     * the same one, not a copy of it, so an address this box accepts is
     * never one the send then refuses.
     */
    public static function isValidAddress(string $address): bool
    {
        return MailService::isValidAddress($address);
    }

    /** The typed address when there is one, else the unit's — if valid. */
    private function resolve(string $typed): ?string
    {
        $address = $typed !== '' ? $typed : $this->unitAddress();

        return $address !== '' && self::isValidAddress($address) ? $address : null;
    }
}
