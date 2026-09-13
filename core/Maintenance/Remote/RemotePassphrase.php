<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

use Core\Config\SettingService;
use Core\Security\SecretManager;

/**
 * The phrase that encrypts every archive this site sends off-site.
 *
 * **Generated, not chosen, and that is forced by who is present.** A
 * portable archive is encrypted with a passphrase (SECURITY.md §5), and
 * the recurring send happens at four in the morning with nobody at the
 * keyboard. Something has to hold a phrase the task can use unattended,
 * so the phrase lives in `secrets.enc` beside the site's other secrets.
 * Once that is true, asking a human to invent it buys nothing and costs
 * the entropy: a generated phrase is the only kind worth storing.
 *
 * **It is revealable from the Maintenance page, and that is a deliberate
 * divergence from the GitHub webhook secret**, which is shown once and
 * never again. The difference is what the secret protects and where it
 * has to be on the day it matters. A webhook secret lives on this server
 * and is used by this server; the phrase has to be legible to a human
 * standing in front of a server that no longer exists. Hiding it would
 * protect nothing — it is on disk either way, so anyone with the server
 * already has it — while guaranteeing that one day an archive survives
 * its site and nobody alive can open it. The screen says to write it
 * down somewhere else, because that is the only copy that will still be
 * reachable when it is needed.
 *
 * **The generation number is the other half.** Regenerating makes every
 * archive already sent unreadable, permanently — nothing re-encrypts
 * them. So the number goes in the name of every file uploaded, and the
 * screen says how many archives the current phrase opens. An operator
 * looking at a Drive folder can then tell which phrase opens which file
 * instead of guessing, and the warning before regenerating says what it
 * costs rather than asking for a vague confirmation.
 */
final class RemotePassphrase
{
    /** Where the phrase itself lives — `secrets.enc`, never `settings`. */
    public const SECRET_KEY = 'remote_backup_passphrase';

    public const GENERATION_SETTING = 'remote_backup_passphrase_generation';
    public const CREATED_AT_SETTING = 'remote_backup_passphrase_created_at';

    /**
     * The alphabet, and the omissions are the point.
     *
     * No `I`, `L`, `O`, `0` or `1`. This phrase exists to be copied onto
     * paper and typed back months later, possibly by somebody who did not
     * write it — and the pairs that get transcribed wrong are always the
     * same ones. Dropping five characters costs about four bits across
     * the whole phrase and removes the failure where an archive is
     * declared unreadable because a one was read as an ell.
     */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Six groups of five, hyphen-separated: `AB3DE-F7HJK-…`. */
    private const GROUPS = 6;
    private const GROUP_LENGTH = 5;

    public function __construct(
        private readonly SettingService $settings,
        private readonly SecretManager $secrets
    ) {
    }

    /**
     * Declares the two rows that describe the phrase without being it.
     *
     * Neither is a secret: the generation number travels in the name of
     * every uploaded file, and the date is what the screen shows beside
     * it. `editable: false` because both are written when a phrase is
     * generated, and a hand-edited generation would make the file names
     * lie about which phrase opens them.
     */
    public static function register(SettingService $settings): void
    {
        $settings->register(self::GENERATION_SETTING, '0', 'text',
            'Génération de la phrase de passe distante',
            'Le numéro qui figure dans le nom des archives envoyées hors site.', null, null, null, false, 310);
        $settings->register(self::CREATED_AT_SETTING, '', 'text',
            'Phrase de passe distante créée le', 'Date de génération de la phrase de passe hors site.',
            null, null, null, false, 311);
    }

    /**
     * The phrase in force, generating one on first use.
     *
     * Generating here rather than at raccordement means a site that has
     * never sent anything has no phrase to lose, and the first scheduled
     * send creates it — no step for the operator to forget.
     *
     * @throws RemoteBackupException when `secrets.enc` cannot be read
     */
    public function current(): string
    {
        $existing = $this->stored();
        if ($existing !== '') {
            return $existing;
        }

        return $this->regenerate();
    }

    /** The phrase if there is one, without creating it. */
    public function stored(): string
    {
        try {
            $secrets = $this->secrets->readSecrets();
        } catch (\Throwable $e) {
            throw RemoteBackupException::of(
                'Les secrets de ce site sont illisibles : la phrase de passe des sauvegardes distantes ne peut '
                . 'pas être lue.',
                $e
            );
        }

        return is_string($secrets[self::SECRET_KEY] ?? null) ? (string) $secrets[self::SECRET_KEY] : '';
    }

    /**
     * A new phrase, and a generation number one higher.
     *
     * **The secret is written before the number**, the discipline the
     * whole `Remote` namespace follows: the write that can destroy
     * something happens first and is verified, and the rows that merely
     * describe it follow. A number claiming a generation that was never
     * written would put the wrong one in every later file name.
     *
     * @throws RemoteBackupException when `secrets.enc` cannot be read
     */
    public function regenerate(): string
    {
        $phrase = self::generate();

        try {
            $secrets = $this->secrets->readSecrets();
        } catch (\Throwable $e) {
            throw RemoteBackupException::of(
                'Les secrets de ce site sont illisibles : rien n\'a été modifié.',
                $e
            );
        }

        $secrets[self::SECRET_KEY] = $phrase;
        $this->secrets->writeSecrets($secrets);

        $this->settings->setInternal(self::GENERATION_SETTING, (string) ($this->generation() + 1));
        $this->settings->setInternal(self::CREATED_AT_SETTING, (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

        return $phrase;
    }

    /** Which phrase is in force, as it appears in uploaded file names. */
    public function generation(): int
    {
        return max(0, (int) ($this->settings->get(self::GENERATION_SETTING) ?: '0'));
    }

    public function createdAt(): string
    {
        return (string) ($this->settings->get(self::CREATED_AT_SETTING) ?: '');
    }

    /**
     * Thirty characters drawn one at a time from {@see ALPHABET}.
     *
     * `random_int()` rather than `random_bytes()` reduced modulo: the
     * alphabet has 31 characters, which is not a power of two, and the
     * obvious `ord($byte) % 31` would make the first few letters slightly
     * likelier than the last. `random_int()` is uniform over an arbitrary
     * range by construction, and this is not a hot path.
     *
     * Thirty characters over 31 symbols is about 148 bits — far past
     * {@see PortablePassphrase::MIN_LENGTH}, which is the floor for a
     * phrase a human invented and has to remember. Nobody has to remember
     * this one; they have to be able to copy it.
     */
    public static function generate(): string
    {
        $groups = [];
        for ($group = 0; $group < self::GROUPS; $group++) {
            $letters = '';
            for ($position = 0; $position < self::GROUP_LENGTH; $position++) {
                $letters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $letters;
        }

        return implode('-', $groups);
    }
}
