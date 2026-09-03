<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\AddressedReference;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Repository\InboundMailboxRepository;

/**
 * Signed reply addresses (§8.58): `locations+rental.LOC-2027-0042.9f3a1b2c4d5e@unite.be`.
 *
 * **Why.** Every other rule reads something the correspondent wrote —
 * the reference they kept in the subject, the address they wrote from,
 * the dates they mention — and each of them fails on an ordinary day: a
 * subject rewritten, the group's treasurer answering instead of the
 * renter, a booking made by phone. What the site itself puts in the
 * `Reply-To` of what it sends comes back untouched by a bare « Répondre »
 * on every mail client, and says exactly which object the message is
 * about, before anybody reads a word of it.
 *
 * **Why signed.** `+rental.LOC-2027-0042` alone could be typed by anyone
 * who knows a reference, and a reference is printed on a contract. The
 * twelve hex characters are a keyed hash of `consumer|reference` under
 * the site's blind-index key: an address that fails it is an ordinary
 * address, and the consumer never hears of it.
 *
 * **Why a setting, on by default.** Plus-addressing is what nearly every
 * provider does with `local+tag@` (Gmail, Outlook, Infomaniak, OVH…), and
 * a unit whose provider does not is one whose replies would bounce — so
 * the operator can turn it off, and the rules above are then all there is.
 *
 * **Which box.** The one declared dedicated to the consumer, else the
 * first enabled box that consumer analyses — whose account name must be
 * an address, since an IMAP login that is not one has no domain to reply
 * to. Nothing is minted when there is no such box: on a site that
 * collects no mail, a reply address would be a promise nobody keeps.
 */
class ReplyAddressService
{
    public const SETTING_KEY = 'inbound_mail_reply_addressing';

    public const SIGNATURE_LENGTH = 12;

    private const SIGNATURE_PURPOSE = 'inbound_reply_address';

    public function __construct(
        private InboundMailboxRepository $mailboxes,
        private EncryptionService $encryption,
        private ?MailboxScopeService $scopes = null,
        private ?SettingService $settings = null
    ) {
    }

    public function isEnabled(): bool
    {
        if ($this->settings === null) {
            return true;
        }

        return (string) $this->settings->get(self::SETTING_KEY, 'inbound_mail', '1') === '1';
    }

    /**
     * The address a consumer puts in `Reply-To` of what it sends about
     * that object, or null when the feature is off, the reference cannot
     * travel in an address, or no box of this consumer can receive it.
     */
    public function addressFor(string $consumerId, string $businessReference): ?string
    {
        if (!$this->isEnabled()
            || preg_match('/^[a-z_]+$/', $consumerId) !== 1
            || preg_match('/^[A-Za-z0-9_-]{1,80}$/', $businessReference) !== 1
        ) {
            return null;
        }

        $mailbox = $this->mailboxFor($consumerId);
        if ($mailbox === null) {
            return null;
        }

        [$local, $domain] = explode('@', $mailbox->username, 2);

        return $local . '+' . $consumerId . '.' . $businessReference . '.'
            . $this->signature($consumerId, $businessReference) . '@' . $domain;
    }

    /**
     * The object a message was addressed to, when one of its recipients
     * is an address this site minted — or null.
     *
     * Read even when the setting has since been turned off: an address
     * sent while it was on is still the site's own, and the replies to
     * mail already sent keep arriving for months.
     *
     * The reference comes back in whatever case the mail layer left it
     * (see `signature()`); the consumer canonicalises it.
     *
     * @param string[] $toEmails
     */
    public function resolve(array $toEmails): ?AddressedReference
    {
        foreach ($toEmails as $email) {
            if (preg_match(
                '/^[^@+\s]+\+([a-z_]+)\.([A-Za-z0-9_-]{1,80})\.([A-Fa-f0-9]{' . self::SIGNATURE_LENGTH . '})@[^@\s]+$/',
                trim($email),
                $m
            ) !== 1) {
                continue;
            }

            if (hash_equals($this->signature($m[1], $m[2]), strtolower($m[3]))) {
                return new AddressedReference($m[1], $m[2]);
            }
        }

        return null;
    }

    /**
     * Over the LOWERCASE form, deliberately: the IMAP layer lowercases
     * every recipient it reads, so a reference like `LOC-2027-0042` comes
     * back as `loc-2027-0042` and a signature over the original would
     * never verify. The consumer gets the reference as the address
     * carried it and canonicalises the case itself, since only it knows
     * what its references look like.
     */
    private function signature(string $consumerId, string $businessReference): string
    {
        return substr(
            $this->encryption->blindIndex(strtolower($consumerId . '|' . $businessReference), self::SIGNATURE_PURPOSE),
            0,
            self::SIGNATURE_LENGTH
        );
    }

    private function mailboxFor(string $consumerId): ?Mailbox
    {
        $fallback = null;
        foreach ($this->mailboxes->findEnabled() as $mailbox) {
            if (!str_contains($mailbox->username, '@')) {
                continue;
            }
            if ($mailbox->isDedicated() && $mailbox->dedicatedTo === $consumerId) {
                return $mailbox;
            }
            if ($fallback === null
                && ($this->scopes === null || $this->scopes->scopeFor($mailbox, $consumerId)->analyzes)
            ) {
                $fallback = $mailbox;
            }
        }

        return $fallback;
    }
}
