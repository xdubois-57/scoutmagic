<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Service;

use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\Journal\JournalService;
use Modules\TestTools\Repository\CapturedEmailRepository;

/**
 * The mail sandbox's business layer (ARCHITECTURE.md §8.63): the arm
 * switch, the browsable list, and the raw message a detail page shows.
 *
 * Nothing here touches $_POST or $_SESSION — the Controller reads the
 * request, this class decides.
 */
class MailSandboxService
{
    public const MODULE_ID = 'test_tools';

    /** The arm switch. Registered non-editable, toggled only from here. */
    public const SETTING_ARMED = 'mail_capture_armed';

    /** How many captured messages are kept — see purge() below. */
    public const SETTING_RETENTION = 'mail_capture_retention';

    public const DEFAULT_RETENTION = 500;

    /**
     * The word an operator has to type to empty the sandbox by hand.
     * French, because it is UI text the operator reads and retypes; the
     * comparison is server-side and exact (§8.18's danger-zone pattern).
     */
    public const CONFIRMATION_WORD = 'EFFACER';

    public function __construct(
        private CapturedEmailRepository $repository,
        private SettingService $settingService,
        private EncryptedFileStorageService $fileStorage,
        private JournalService $journalService
    ) {
    }

    /**
     * Whether capture is armed right now.
     *
     * Static so the composition root can ask the question before any of
     * this module's services exist — it has to decide whether to inject the
     * transport into MailService, and that happens long before a route is
     * ever resolved.
     */
    public static function isArmed(SettingService $settingService): bool
    {
        return (string) ($settingService->get(self::SETTING_ARMED, self::MODULE_ID) ?? '0') === '1';
    }

    public function armed(): bool
    {
        return self::isArmed($this->settingService);
    }

    /**
     * Arms or disarms the capture.
     *
     * Journaled at level `security` every single time, in both directions:
     * this switch changes the sending behaviour of the whole site, and an
     * operator finding no mail arriving needs the journal to say when it
     * was turned on and by whom. No address ever appears in the entry.
     */
    public function setArmed(bool $armed, ?int $userId = null): void
    {
        // setInternal(), not set(): the switch is deliberately registered
        // non-editable so it never renders as a row on Configuration >
        // Paramètres. It is toggled here and only here.
        $this->settingService->setInternal(self::SETTING_ARMED, $armed ? '1' : '0', self::MODULE_ID);

        $this->journalService->log(
            self::MODULE_ID,
            $armed ? 'mail_capture_armed' : 'mail_capture_disarmed',
            'security',
            $armed
                ? 'Capture des e-mails sortants activée : plus aucun message ne quitte le serveur.'
                : 'Capture des e-mails sortants désactivée : les messages repartent normalement.',
            [],
            $userId
        );
    }

    /**
     * @param array<int, int>|null $ids restrict to these ids (a body search's result)
     * @return array<int, CapturedEmail>
     */
    public function page(
        int $limit,
        int $offset,
        ?string $subject = null,
        ?string $recipient = null,
        ?array $ids = null
    ): array {
        return $this->repository->findPage($limit, $offset, $subject, $recipient, $ids);
    }

    /**
     * @param array<int, int>|null $ids
     */
    public function count(?string $subject = null, ?string $recipient = null, ?array $ids = null): int
    {
        return $this->repository->countAll($subject, $recipient, $ids);
    }

    public function find(int $id): ?CapturedEmail
    {
        return $this->repository->findById($id);
    }

    /**
     * The stored raw RFC 5322 message, or null when assembly had failed and
     * there is nothing to show.
     */
    public function rawMessage(CapturedEmail $email): ?string
    {
        return $this->retrieve($email->mimeFileId);
    }

    /**
     * The message's own HTML half, or null when it had none.
     *
     * Stored at capture time straight off the PHPMailer instance, so
     * nothing here parses MIME. The caller renders it inside a sandboxed
     * iframe and never inline — see the detail template.
     */
    public function htmlBody(CapturedEmail $email): ?string
    {
        return $this->retrieve($email->bodyHtmlFileId);
    }

    /** The message's plain-text half, or null when it had none. */
    public function textBody(CapturedEmail $email): ?string
    {
        return $this->retrieve($email->bodyTextFileId);
    }

    /**
     * The message's header block: everything up to the first empty line of
     * the raw message.
     *
     * A split on the header/body separator RFC 5322 defines, not a parser —
     * no folding is unfolded, no field is interpreted, nothing is looked
     * up. The tab shows the headers exactly as the library wrote them.
     */
    public function headers(CapturedEmail $email): ?string
    {
        $message = $this->rawMessage($email);
        if ($message === null) {
            return null;
        }

        foreach (["\r\n\r\n", "\n\n"] as $separator) {
            $position = strpos($message, $separator);
            if ($position !== false) {
                return substr($message, 0, $position);
            }
        }

        // A message with no body at all is all headers.
        return $message;
    }

    /**
     * The ids of the retained messages whose plain-text body contains
     * $needle, scanned newest first and **bounded**.
     *
     * Every body is encrypted at rest, so there is no index to search and
     * no LIKE to push down: each candidate has to be read and decrypted.
     * The bound is what keeps that affordable, and it is stated in the
     * interface rather than hidden — a search that quietly stopped looking
     * would be worse than one that says where it stopped.
     *
     * @return array<int, int>
     */
    public function searchBodies(string $needle, int $bound): array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return [];
        }

        $matches = [];
        foreach ($this->repository->findRecentIds($bound) as $id) {
            $email = $this->repository->findById($id);
            if ($email === null) {
                continue;
            }

            foreach ([$this->textBody($email), $this->htmlBody($email)] as $body) {
                if ($body !== null && mb_stripos($body, $needle) !== false) {
                    $matches[] = $id;
                    continue 2;
                }
            }
        }

        return $matches;
    }

    private function retrieve(?int $fileId): ?string
    {
        if ($fileId === null) {
            return null;
        }

        try {
            return $this->fileStorage->retrieve($fileId);
        } catch (\RuntimeException) {
            // The row outlived its file (a manual deletion, a restored
            // backup). The sandbox says so rather than erroring out.
            return null;
        }
    }

    /**
     * Deletes a captured message: its metadata rows AND every encrypted
     * file they reference, never one without the other.
     *
     * The files go first: a run interrupted between the two leaves a row
     * pointing at a file that is gone (a detail page that says so, and
     * which the next purge removes anyway), never a file no row references
     * and nothing will ever delete again.
     */
    public function delete(int $id): void
    {
        foreach ($this->repository->findFileIds($id) as $fileId) {
            $this->fileStorage->delete($fileId);
        }

        $this->repository->delete($id);
    }

    /**
     * How many captured messages this installation keeps.
     *
     * A non-numeric or non-positive setting falls back to the default
     * rather than disabling retention: "0" typed into the field must not
     * silently mean "keep everything for ever".
     */
    public function retentionLimit(): int
    {
        $configured = (int) ($this->settingService->get(self::SETTING_RETENTION, self::MODULE_ID) ?? 0);

        return $configured > 0 ? $configured : self::DEFAULT_RETENTION;
    }

    /**
     * Drops everything past the retention limit, **oldest first**, up to
     * $maxPerRun messages. Returns how many were deleted.
     *
     * Bounded by count rather than by age on purpose (see
     * Modules\TestTools\Task\PurgeCapturedEmailsHandler).
     */
    public function purge(int $maxPerRun): int
    {
        $deleted = 0;
        foreach ($this->repository->findIdsBeyond($this->retentionLimit(), $maxPerRun) as $id) {
            $this->delete($id);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Empties the sandbox: every captured message, and every encrypted
     * file any of them references. Returns how many were deleted.
     *
     * Journaled at `security`, like the arm switch — this is the operator
     * destroying the evidence they were collecting, and the journal is the
     * only place that will still say it happened.
     */
    public function empty(?int $userId = null): int
    {
        $deleted = 0;
        foreach ($this->repository->findAllIds() as $id) {
            $this->delete($id);
            $deleted++;
        }

        $this->journalService->log(
            self::MODULE_ID,
            'mail_capture_emptied',
            'security',
            'Bac à sable e-mail vidé : ' . $deleted . ' message(s) supprimé(s).',
            ['count' => $deleted],
            $userId
        );

        return $deleted;
    }
}
