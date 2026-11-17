<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\Database\ConstraintViolation;
use Modules\InboundMail\Api\MailboxPurpose;
use Modules\InboundMail\Client\MailboxConnectionException;
use Modules\InboundMail\Client\RemoteFolder;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Mailbox\SyncState;
use Core\Config\SettingService;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Task\PurgeUnlinkedMessagesHandler;

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
        private MailboxErrorFormatter $errorFormatter,
        /**
         * Only ever asked for counts and for the configured retention —
         * never for a message. This service renders a superadmin screen,
         * and a screen about plumbing has no business holding anybody's
         * mail.
         */
        private ?InboundMessageRepository $messageRepository = null,
        private ?SettingService $settings = null
    ) {
    }

    /**
     * How many messages each box is holding, for the index's « N messages
     * conservés ».
     *
     * A count and nothing else: it is the honest measure of what the unit
     * has accumulated since the module started keeping everything, and it
     * is the number an operator looks at before deciding a retention is
     * too long.
     *
     * @return array<int, int> keyed by mailbox id
     */
    public function storedMessageCounts(): array
    {
        return $this->messageRepository?->countByMailbox() ?? [];
    }

    /**
     * The retention the configuration screen quotes, live rather than
     * hard-coded: a screen that promised ninety days while the setting said
     * thirty would be worse than one that promised nothing.
     */
    public function retentionDays(): int
    {
        return $this->settings === null
            ? PurgeUnlinkedMessagesHandler::DEFAULT_RETENTION_DAYS
            : (new PurgeUnlinkedMessagesHandler())->retentionDays($this->settings);
    }

    /**
     * @return Mailbox[]
     */
    public function listMailboxes(): array
    {
        return $this->repository->findAll();
    }

    public function findById(int $id): ?Mailbox
    {
        return $this->repository->findById($id);
    }

    /**
     * The addresses of the boxes that are the unit's general mail, for the
     * form's « … l'est déjà ».
     *
     * @return list<string>
     */
    public function generalAddresses(): array
    {
        $addresses = [];
        foreach ($this->repository->findAll() as $mailbox) {
            if (!$mailbox->isDedicated()) {
                $addresses[] = $mailbox->displayName();
            }
        }

        return $addresses;
    }

    /**
     * The usage a new box's form proposes (D6): the unit's general mail
     * while there is none, reserved to a module once there is — and
     * general mail anyway when no module could take a reserved box. Only
     * a default: several general boxes stay allowed.
     */
    public function suggestedPurpose(bool $aModuleIsOffered): MailboxPurpose
    {
        return $aModuleIsOffered && $this->generalAddresses() !== []
            ? MailboxPurpose::DEDICATED
            : MailboxPurpose::SHARED;
    }

    /**
     * What a module or a page outside Configuration may know about the
     * boxes: their address and whether they are working (§7.4). Never the
     * host or the port.
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
     * Create a box with its purpose in one write: a reservation the unique
     * index refuses leaves no row behind.
     *
     * @param string[] $folders
     * @throws MailboxReservationRefusedException when the module already has its box
     */
    public function create(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        array $folders,
        bool $isEnabled,
        string $sentFolder = '',
        MailboxPurpose $purpose = MailboxPurpose::SHARED,
        ?string $dedicatedTo = null
    ): int {
        $dedicatedTo = $dedicatedTo !== null ? trim($dedicatedTo) : null;
        $refusal = $this->purposeRefusal(0, $purpose, $dedicatedTo);
        if ($refusal !== null) {
            throw new MailboxReservationRefusedException($refusal);
        }

        try {
            $id = $this->repository->create(
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
                $isEnabled,
                $purpose,
                $dedicatedTo
            );
        } catch (\PDOException $e) {
            if (!ConstraintViolation::isDuplicateKey($e)) {
                throw $e;
            }

            // A save that raced past the check above: the index said no,
            // and no row was written.
            $other = $dedicatedTo === null ? null : $this->repository->findOtherDedicatedTo($dedicatedTo, 0);
            throw new MailboxReservationRefusedException(
                self::alreadyReserved($other === null ? null : $this->repository->findById($other)),
                0,
                $e
            );
        }
        $this->repository->setSentFolder($id, $sentFolder);

        return $id;
    }

    /**
     * @param string[] $folders
     * @param string $password blank leaves the stored one untouched
     * @param string $sentFolder blank: the folder the server marks `\Sent`
     */
    public function update(
        int $id,
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        array $folders,
        bool $isEnabled,
        string $sentFolder = ''
    ): void {
        $this->repository->update(
            $id,
            $host,
            $port,
            self::normaliseEncryption($encryption),
            $username,
            $folders,
            $isEnabled
        );
        $this->repository->setSentFolder($id, $sentFolder);

        if ($password !== '') {
            $this->repository->setPassword($id, $password);
        }
    }

    /**
     * What the configuration form writes on an edit: the connection, the
     * folders and — when one was typed — the password, never the enabled
     * state, which belongs to the list's « Désactiver » (see
     * `InboundMailboxRepository::updateConnection()`).
     *
     * @param string[] $folders
     * @param string $password blank leaves the stored one untouched
     * @param string $sentFolder blank: the folder the server marks `\Sent`
     */
    public function updateConnection(
        int $id,
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        array $folders,
        string $sentFolder = ''
    ): void {
        $this->repository->updateConnection(
            $id,
            $host,
            $port,
            self::normaliseEncryption($encryption),
            $username,
            $folders
        );
        $this->repository->setSentFolder($id, $sentFolder);

        if ($password !== '') {
            $this->repository->setPassword($id, $password);
        }
    }

    /**
     * After an edit whose verification succeeded: the error shown on the
     * list was about settings that no longer stand, and the new ones just
     * connected. See `InboundMailboxRepository::clearFailure()`.
     */
    public function clearFailureAfterVerifiedEdit(int $id): void
    {
        $this->repository->clearFailure($id);
    }

    /**
     * Declare what a box is for — the unit's general mail, or reserved to
     * one module (§8.58).
     *
     * **A module has at most one reserved box** (D5) — except the seed
     * boxes, which may be several. Checked here so the operator reads why
     * in a sentence, and held by the unique index on `dedication_key`
     * should two saves race past the check.
     *
     * @return string|null null when saved, otherwise the reason it was not,
     *   in a sentence for the operator
     */
    public function setPurpose(int $id, MailboxPurpose $purpose, ?string $dedicatedTo): ?string
    {
        $dedicatedTo = $dedicatedTo !== null ? trim($dedicatedTo) : null;

        $refusal = $this->purposeRefusal($id, $purpose, $dedicatedTo);
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            $this->repository->setPurpose($id, $purpose, $dedicatedTo);
        } catch (\PDOException $e) {
            // Only the unique index means « already reserved »: another save
            // reserved an address to this module between the check above and
            // this write. Anything else — a lost connection, a deadlock — is
            // not that, and goes on as the failure it is.
            if (!ConstraintViolation::isDuplicateKey($e)) {
                throw $e;
            }

            return self::alreadyReserved(null);
        }

        return null;
    }

    /**
     * Why this purpose cannot be given to this box, or null when it can —
     * asked before anything is written, so a refused save changes nothing.
     *
     * @param int $id the box, or 0 for one not created yet
     */
    public function purposeRefusal(int $id, MailboxPurpose $purpose, ?string $dedicatedTo): ?string
    {
        if ($purpose !== MailboxPurpose::DEDICATED) {
            return null;
        }

        $dedicatedTo = $dedicatedTo !== null ? trim($dedicatedTo) : '';
        if ($dedicatedTo === '') {
            return 'Choisissez le module auquel cette adresse est réservée.';
        }

        // The seed boxes are the one usage that takes several (D5's
        // exception): copies are measured across several providers.
        if (MailboxPurpose::readsReservedBoxesOnly($dedicatedTo)) {
            return null;
        }

        $other = $this->repository->findOtherDedicatedTo($dedicatedTo, $id);

        return $other === null ? null : self::alreadyReserved($this->repository->findById($other));
    }

    private static function alreadyReserved(?Mailbox $other): string
    {
        return $other !== null
            ? 'Ce module a déjà son adresse réservée : ' . $other->displayName()
                . '. Un module n\'a qu\'une adresse réservée.'
            : 'Ce module a déjà son adresse réservée. Un module n\'a qu\'une adresse réservée.';
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
     * **Nothing is written to the mailbox row** (#1325). A test only logs
     * in and lists the folders: it proves neither that a watched folder
     * opens nor that its messages can be fetched. Recording its success as
     * a synchronisation dated « dernière relève » a run that never
     * happened, and wiped the error of the one that had just failed; its
     * failure, in turn, would mark a working relève as broken. The row
     * belongs to the relève alone, and the test answers on the screen.
     *
     * @return array{ok: bool, message: string, folders: string[], offered_folders?: list<string>,
     *   sent_folder?: ?string}
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
            $listed = $client->listFolders();
            $client->disconnect();
        } catch (MailboxConnectionException | \RuntimeException $e) {
            $client->disconnect();

            return ['ok' => false, 'message' => $this->errorFormatter->format($e), 'folders' => []];
        }

        return [
            'ok' => true,
            'message' => self::successMessage($listed),
            'folders' => RemoteFolder::paths($listed),
            'offered_folders' => RemoteFolder::offeredAmong($listed),
            'sent_folder' => RemoteFolder::sentAmong($listed),
        ];
    }

    /**
     * Same test as `testConnection()`, but from raw form parameters rather
     * than a persisted mailbox — so the operator can verify credentials
     * *before* saving.
     *
     * When `$existingId` identifies an already-saved box **and** $password
     * is blank, the stored password is reused (the page never re-displays
     * it, so "blank = keep" is the only reasonable default — same rule the
     * save path already follows).
     *
     * Like `testConnection()`, it records nothing on the box: see there.
     * `$offerJunk` keeps the junk folder among those offered, for a seed
     * box (`RemoteFolder::offeredAmong()`).
     *
     * @return array{ok: bool, message: string, folders: string[], offered_folders?: list<string>,
     *   sent_folder?: ?string}
     */
    public function testConnectionFromParams(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        ?int $existingId,
        \DateTimeImmutable $now,
        bool $offerJunk = false
    ): array {
        if ($password === '' && $existingId !== null && $existingId > 0) {
            $storedCredentials = $this->repository->findCredentials($existingId);
            if ($storedCredentials === null) {
                return ['ok' => false, 'message' => 'Boîte introuvable.', 'folders' => []];
            }
            $password = $storedCredentials->password;
        }

        if ($password === '') {
            return ['ok' => false, 'message' => 'Le mot de passe est obligatoire.', 'folders' => []];
        }

        $mailbox = new Mailbox(
            id: 0,
            providerType: ProviderType::IMAP,
            host: $host,
            port: $port,
            encryption: self::normaliseEncryption($encryption),
            username: $username,
            folders: [],
            isEnabled: true,
            syncState: SyncState::NEVER
        );

        $credentials = new MailboxCredentials($username, $password);
        $client = $this->clientFactory->forMailbox($mailbox);

        try {
            $client->connect($mailbox, $credentials);
            $listed = $client->listFolders();
            $client->disconnect();
        } catch (MailboxConnectionException | \RuntimeException $e) {
            $client->disconnect();

            return ['ok' => false, 'message' => $this->errorFormatter->format($e), 'folders' => []];
        }

        return [
            'ok' => true,
            'message' => self::successMessage($listed),
            'folders' => RemoteFolder::paths($listed),
            'offered_folders' => RemoteFolder::offeredAmong($listed, $offerJunk),
            'sent_folder' => RemoteFolder::sentAmong($listed),
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
     * The folders a box reads, from the « Autres dossiers reçus » the form
     * posted: the inbox is always read (D9), so other folders come in
     * addition to it, never instead. None chosen is the empty list, which
     * reads the inbox alone (`Mailbox::watchedFolders()`).
     *
     * @param string[] $others
     * @return string[]
     */
    public static function foldersWithInbox(array $others): array
    {
        $others = array_values(array_filter(
            $others,
            static fn(string $folder): bool => strcasecmp($folder, 'INBOX') !== 0
        ));

        return $others === [] ? [] : ['INBOX', ...$others];
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

    /**
     * « Connexion réussie », how many folders, and — when the server marks
     * one `\Sent` — which one is the box's sent mail, so an operator sees
     * whether the « Dossier des envoyés » field needs filling at all.
     *
     * @param RemoteFolder[] $listed
     */
    private static function successMessage(array $listed): string
    {
        $message = 'Connexion réussie. ' . count($listed) . ' dossier(s) visible(s).';
        $sent = RemoteFolder::sentAmong($listed);

        return $sent !== null
            ? $message . ' Dossier des envoyés reconnu : « ' . $sent . ' ».'
            : $message;
    }
}
