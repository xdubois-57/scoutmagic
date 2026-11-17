<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Mailbox;

/**
 * A mail provider the configuration form recognises from an address (D8),
 * with the connection settings it prefills.
 *
 * The values are the providers' own published IMAP settings, checked
 * against their documentation when this list was written. They are a
 * prefill and nothing more: the three fields stay visible and editable,
 * and « Vérifier et enregistrer » is what proves them.
 */
final class MailProvider
{
    /** The app-password help shown under the password field, if any. */
    public const HELP_GMAIL = 'gmail';
    public const HELP_OUTLOOK = 'outlook';

    public function __construct(
        /** A stable key, for the form's script and for tests. */
        public readonly string $key,
        /** What the form says it recognised: « Gmail reconnu ». */
        public readonly string $label,
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        /** One of the HELP_* constants, or null for no app-password help. */
        public readonly ?string $passwordHelp = null
    ) {
    }

    /**
     * What the form's script receives: settings and labels, nothing else.
     *
     * @return array{key: string, label: string, host: string, port: int, encryption: string, password_help: ?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'password_help' => $this->passwordHelp,
        ];
    }
}
