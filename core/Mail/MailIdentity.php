<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

use Core\Config\SettingService;

/**
 * The addresses this site sends under, and which of the four jobs each
 * one does (roadmap IT-03, ARCHITECTURE.md §8.106).
 *
 * **Four roles, usually one address.** A message leaving the site names an
 * address four times over, and they are four different questions:
 *
 * - the **expéditeur affiché** (`From:`) — what a reader sees;
 * - the **adresse de réponse** (`Reply-To:`) — where « Répondre » goes;
 * - le **retour des rebonds** (the envelope `Return-Path`) — where the
 *   receiving server writes back when it refuses the message;
 * - les **rapports DMARC** (the `rua=` tag of the DNS record) — where
 *   other operators post their weekly summary.
 *
 * One address answering all four is the ordinary case and a perfectly
 * good configuration. It is also exactly why the distinction has to be
 * spelled out somewhere: a volunteer looking at a single address in a
 * single field has no way to work out that SPF is evaluated against the
 * third of those and not the first, and the one moment the question
 * matters is the moment they are reading this screen.
 *
 * **This class is the single authority on which address plays which
 * role.** Before it, the answer was spread over `MailService`'s send
 * loop, a line of `setup.js` that split the From address on `@`, and
 * nothing at all for the reply address. Deriving the SPF domain from the
 * wrong one of the four is the mistake the roadmap names, and the way to
 * make it unavailable is to stop letting callers pick.
 */
final class MailIdentity
{
    /** `From:` — the address a reader sees. */
    public const ROLE_FROM = 'from';

    /** `Reply-To:` — where a bare « Répondre » lands. */
    public const ROLE_REPLY = 'reply';

    /** The envelope sender: bounces, and the domain SPF is evaluated on. */
    public const ROLE_BOUNCE = 'bounce';

    /** The `rua=` tag: where other operators post their DMARC summary. */
    public const ROLE_DMARC = 'dmarc';

    public const SETTING_FROM_ADDRESS = 'mail_from_address';
    public const SETTING_FROM_NAME = 'mail_from_name';
    public const SETTING_REPLY_ADDRESS = 'mail_reply_address';
    public const SETTING_DMARC_REPORT = 'dmarc_report_email';

    public function __construct(
        public readonly string $fromAddress,
        public readonly string $fromName = '',
        /**
         * Empty means « replies come back to the From address », which is
         * what happens when a message carries no `Reply-To:` at all. It
         * is stored as an absence rather than as a copy of the From
         * address so that changing the From address keeps the two in
         * step instead of leaving a stale duplicate behind.
         */
        private readonly string $configuredReplyAddress = '',
        /** Empty means the same thing, for the `rua=` tag. */
        private readonly string $configuredDmarcReportAddress = ''
    ) {
    }

    public static function fromSettings(SettingService $settings): self
    {
        return new self(
            trim((string) ($settings->get(self::SETTING_FROM_ADDRESS) ?? '')),
            trim((string) ($settings->get(self::SETTING_FROM_NAME) ?? '')),
            trim((string) ($settings->get(self::SETTING_REPLY_ADDRESS) ?? '')),
            trim((string) ($settings->get(self::SETTING_DMARC_REPORT) ?? ''))
        );
    }

    /** What the operator actually typed — empty when they left it alone. */
    public function configuredReplyAddress(): string
    {
        return $this->configuredReplyAddress;
    }

    /** What the operator actually typed — empty when they left it alone. */
    public function configuredDmarcReportAddress(): string
    {
        return $this->configuredDmarcReportAddress;
    }

    /** Where « Répondre » goes, once the fallback is applied. */
    public function replyAddress(): string
    {
        return $this->configuredReplyAddress !== '' ? $this->configuredReplyAddress : $this->fromAddress;
    }

    /**
     * Where the `rua=` tag would point — the expédition address when
     * nothing is configured.
     *
     * **The fallback answers « if reports were asked for, where would
     * they go », never « reports are being collected ».** The site
     * proposes no `_dmarc` record at all while
     * {@see self::configuredDmarcReportAddress()} is empty, so nothing is
     * requested and nothing arrives; the four-roles table therefore shows
     * that row empty rather than showing this address next to a sentence
     * saying no report is requested.
     */
    public function dmarcReportAddress(): string
    {
        return $this->configuredDmarcReportAddress !== ''
            ? $this->configuredDmarcReportAddress
            : $this->fromAddress;
    }

    /**
     * The envelope sender — the `MAIL FROM` of the SMTP conversation, and
     * therefore the `Return-Path` the receiving server writes.
     *
     * **Always the From address, and not by accident.** `MailService`
     * assigns `$mail->Sender = $this->fromAddress` unconditionally, a
     * mailing's own sender section included: an override replaces the
     * visible `From:` and never the envelope, so bounces keep coming back
     * to the site rather than to whichever section sent the campaign.
     * `Tests\Core\Mail\MailIdentityTest` pins that against the real
     * `MailService`, because the day the two disagree is the day
     * {@see self::spfDomain()} starts answering about the wrong domain
     * and nothing anywhere says so.
     */
    public function envelopeSender(): string
    {
        return $this->fromAddress;
    }

    /** Same address, named for the role the screen shows it under. */
    public function bounceAddress(): string
    {
        return $this->envelopeSender();
    }

    /**
     * The domain SPF is evaluated on — the trap the roadmap names.
     *
     * SPF authorises a *sending host* for the domain of the **envelope
     * sender**, never for the domain of the `From:` header (RFC 7208
     * §2.4). Checking the `From:` domain gives the right answer only for
     * as long as the two addresses agree, and checking the reply or the
     * DMARC-report domain — both of which an operator may legitimately
     * point at another provider entirely — gives a confident wrong
     * answer: a red « SPF manquant » on a domain that never sends
     * anything, or a green one on a domain that does.
     */
    public function spfDomain(): string
    {
        return self::domainOf($this->envelopeSender());
    }

    /**
     * The domain the DKIM signature is made for — the `d=` tag.
     *
     * `MailService` signs with the site's own configured address, so this
     * is the From domain. DMARC then passes on the DKIM side only when
     * `d=` aligns with the `From:` domain, which is why the signature
     * follows the From address rather than the envelope.
     */
    public function dkimDomain(): string
    {
        return self::domainOf($this->fromAddress);
    }

    /**
     * Whether everything the site puts in a message lines up under one
     * domain — the state in which SPF and DKIM both align and DMARC has
     * nothing to complain about.
     */
    public function isAligned(): bool
    {
        return $this->spfDomain() !== '' && $this->spfDomain() === $this->dkimDomain();
    }

    /**
     * The four-roles table, in the order the screen reads them.
     *
     * @return list<array{role: string, label: string, address: string, source: string, explanation: string}>
     */
    public function roles(): array
    {
        $inherited = 'Reprend l\'adresse d\'expédition';
        $own = 'Adresse renseignée';
        $none = 'Aucun rapport demandé';

        return [
            [
                'role' => self::ROLE_FROM,
                'label' => 'Expéditeur affiché',
                'address' => $this->fromAddress,
                'source' => $own,
                'explanation' => 'Ce que la personne voit dans sa boîte, à côté du nom d\'expédition.',
            ],
            [
                'role' => self::ROLE_REPLY,
                'label' => 'Réponses',
                'address' => $this->replyAddress(),
                'source' => $this->configuredReplyAddress !== '' ? $own : $inherited,
                'explanation' => 'Où arrive une réponse quand la personne clique simplement sur « Répondre ».',
            ],
            [
                'role' => self::ROLE_BOUNCE,
                'label' => 'Retour des rebonds',
                'address' => $this->bounceAddress(),
                'source' => 'Toujours l\'adresse d\'expédition',
                'explanation' => 'Où le serveur du destinataire écrit quand il refuse le message. C\'est aussi le '
                    . 'domaine sur lequel le SPF est vérifié — pas celui de l\'expéditeur affiché, même quand les '
                    . 'deux se ressemblent.',
            ],
            [
                'role' => self::ROLE_DMARC,
                'label' => 'Rapports DMARC',
                // Empty when nothing is configured, and NOT the expédition
                // address: this row says where the site asks for reports,
                // and without an address it asks for none at all — no
                // `rua=` is proposed, so no report is ever sent. Showing
                // the expédition address here would put an address in a
                // row whose own sentence says nothing is collected, and a
                // reader would have to choose which half to believe.
                'address' => $this->configuredDmarcReportAddress,
                'source' => $this->configuredDmarcReportAddress !== '' ? $own : $none,
                'explanation' => 'Où les autres opérateurs envoient leur résumé périodique des messages reçus en '
                    . 'votre nom. Facultatif : sans adresse, aucun rapport n\'est demandé.',
            ],
        ];
    }

    /**
     * The domain part of an address, lowercased — empty when there is no
     * address, or nothing after the `@`.
     */
    public static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return '';
        }

        return strtolower(trim(substr($address, $at + 1)));
    }
}
