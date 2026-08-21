<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Client\MailboxConnectionException;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;

/**
 * Everything the superadmin configuration page does, minus the HTTP.
 *
 * The one rule worth stating twice: **nothing here ever returns a secret**.
 * `testConnection()` reads the password to try it and never puts it in its
 * answer; a save with a blank password field leaves the stored one alone
 * (see the repository) rather than clearing it, because the page cannot
 * show it and an operator editing the port has no way to retype it.
 */
class MailboxAdminService
{
    public function __construct(
        private InboundMailboxRepository $repository,
        private MailboxClientFactory $clientFactory,
        private MailboxErrorFormatter $errorFormatter
    ) {
    }

    /**
     * @return Mailbox[]
     */
    public function listMailboxes(): array
    {
        return $this->repository->findAll();
    }

    /**
     * What a module or a page outside Configuration may know about the
     * boxes: their name and whether they are working (§7.4). Never the
     * host, the port or the account.
     *
     * @return array<int, array{name: string, state: string, is_enabled: bool}>
     */
    public function listPublicSummaries(): array
    {
        $summaries = [];
        foreach ($this->repository->findAll() as $mailbox) {
            $summaries[$mailbox->id] = $mailbox->publicSummary();
        }

        return $summaries;
    }

    /**
     * @param string[] $folders
     */
    public function create(
        string $name,
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        array $folders,
        bool $isEnabled
    ): int {
        return $this->repository->create(
            $name,
            // The manifest offers IMAP and only IMAP (§7.2); the enum's
            // other case exists for tests, and letting it be chosen here
            // would put a fixture in front of an operator.
            ProviderType::IMAP,
            $host,
            $port,
            self::normaliseEncryption($encryption),
            $username,
            $password,
            $folders,
            $isEnabled
        );
    }

    /**
     * @param string[] $folders
     * @param string $password blank leaves the stored one untouched
     */
    public function update(
        int $id,
        string $name,
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        array $folders,
        bool $isEnabled
    ): void {
        $this->repository->update(
            $id,
            $name,
            $host,
            $port,
            self::normaliseEncryption($encryption),
            $username,
            $folders,
            $isEnabled
        );

        if ($password !== '') {
            $this->repository->setPassword($id, $password);
        }
    }

    public function setEnabled(int $id, bool $isEnabled): void
    {
        $this->repository->setEnabled($id, $isEnabled);
    }

    public function delete(int $id): void
    {
        $this->repository->delete($id);
    }

    /**
     * Try the box and say what happened, in a sentence an operator can act
     * on.
     *
     * The result is also written to the mailbox row, so a test that fails
     * leaves the same trace a failed synchronisation would — an operator
     * should not have to remember which of the two produced the error they
     * are looking at.
     *
     * @return array{ok: bool, message: string, folders: string[]}
     */
    public function testConnection(int $id, \DateTimeImmutable $now): array
    {
        $mailbox = $this->repository->findById($id);
        $credentials = $this->repository->findCredentials($id);

        if ($mailbox === null || $credentials === null) {
            return ['ok' => false, 'message' => 'Boîte introuvable.', 'folders' => []];
        }

        $client = $this->clientFactory->forMailbox($mailbox);

        try {
            $client->connect($mailbox, $credentials);
            $folders = $client->listFolders();
            $client->disconnect();
        } catch (MailboxConnectionException | \RuntimeException $e) {
            $reason = $this->errorFormatter->format($e);
            $this->repository->recordFailure($id, $reason, $now);
            $client->disconnect();

            return ['ok' => false, 'message' => $reason, 'folders' => []];
        }

        $this->repository->recordSuccess($id, $now);

        return [
            'ok' => true,
            'message' => 'Connexion réussie. ' . count($folders) . ' dossier(s) visible(s).',
            'folders' => $folders,
        ];
    }

    /**
     * An allowlist, never the raw input: 'none' is not on it, so there is
     * no configuration path to an unencrypted IMAP session (§7.5).
     */
    private static function normaliseEncryption(string $encryption): string
    {
        $encryption = strtolower(trim($encryption));

        return in_array($encryption, ['ssl', 'tls', 'starttls'], true) ? $encryption : 'ssl';
    }

    /**
     * The folder list as an operator typed it: one per line, trimmed, empty
     * lines dropped.
     *
     * @return string[]
     */
    public static function parseFolders(string $raw): array
    {
        $folders = array_map('trim', preg_split('/[\r\n]+/', $raw) ?: []);

        return array_values(array_unique(array_filter($folders, static fn(string $f) => $f !== '')));
    }
}
