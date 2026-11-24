<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Task;

use Core\Badge\BadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Finance\Mail\FinanceMessageConsumer;
use Modules\Finance\Mail\MailReceiptFiler;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpenseReceiptService;
use Modules\Finance\Service\ReceiptExtractionService;
use Modules\Finance\Service\ReceiptService;
use Modules\Finance\Service\TreasurerScopeService;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\LlmConnector\Api\LlmConnectorInterface;

/**
 * The mail already associated with an account whose receipt was never
 * filed, filed now — once, a few at a time, then never again (#1342,
 * decision 9).
 *
 * `onLinked()` files a message's receipts when its association is new, and
 * until #1342 that could fail and stay failed: the scheduled path built its
 * store without the reading (§A), a manual association to « Compte inconnu »
 * filed nothing (§E), and a refusal left only a journal line (§F). The
 * deferred pass now catches each NEW message up on arrival; this task does
 * the same for the history, through the courrier's own published lookup
 * (`Api\InboundMailInterface::findForReference()`) rather than a second scan
 * of its tables.
 *
 * **What it files is what the arrival would have filed**: an association
 * nobody made, on its own account or the pile, by the unattended door.
 * **What it leaves alone** is everything `MailReceiptFiler::catchUp()`
 * leaves alone — a person's association, an attachment already through
 * finance, and any bytes finance holds in any state, a deleted receipt
 * included.
 *
 * **Bounded and finite.** At most {@see self::BATCH_SIZE} attachments an
 * hour, each remembered once looked at, whatever came of it; the first run
 * that finds nothing left records {@see self::DONE_SETTING} and the chain
 * ends — every later association is caught up on arrival.
 */
class CatchUpMailReceiptsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'catch_up_mail_receipts';
    public const REFERENCE = 'mail-receipts';

    /** How many attachments one run looks at: each is a file read, maybe a filing. */
    public const BATCH_SIZE = 20;

    public const INTERVAL_SECONDS = 3600;

    /** Set once the history is caught up: the chain is not seeded again. */
    public const DONE_SETTING = 'finance_mail_receipts_caught_up_at';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $inboundMail = $context->getOptional(InboundMailInterface::class);
        $scheduler = SchedulerService::forPdo($context->connection->getPdo());

        // No courrier: nothing to catch up yet, and not « done » either —
        // the day the module is turned on, the history is there.
        if ($inboundMail === null) {
            $scheduler->rearmAfter('finance', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);

            return;
        }

        $considered = $this->catchUpABatch($context, $inboundMail);

        if ($considered === 0) {
            try {
                $context->settings->setInternal(self::DONE_SETTING, date('Y-m-d H:i:s'), 'finance');

                return;
            } catch (\Core\Config\SettingException) {
                // The manifest's settings not synchronised yet: the chain
                // goes on, an hour of nothing to do at a time, until the
                // marker can be written.
                $scheduler->rearmAfter('finance', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);

                return;
            }
        }

        $context->journal->log(
            'finance',
            'mail_receipts_caught_up',
            'info',
            'Reçus du courrier déjà rattaché repris',
            ['attachments' => $considered],
            null
        );
        $scheduler->rearmAfter('finance', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }

    private function catchUpABatch(TaskContext $context, InboundMailInterface $inboundMail): int
    {
        $pdo = $context->connection->getPdo();
        $accounts = new AccountRepository($pdo, $context->encryption);
        $fileRepository = new FileRepository($pdo);
        $fileStorage = new EncryptedFileStorageService($fileRepository, $context->encryption, $context->storagePath);
        $files = new StoredFileReader($fileRepository, $fileStorage, $context->storagePath);
        $treasurerScope = new TreasurerScopeService(Connection::withPdo($pdo), new BadgeRepository($pdo));

        $filer = new MailReceiptFiler(
            $pdo,
            $context->encryption,
            new ExpenseReceiptService(
                $accounts,
                $treasurerScope,
                new ReceiptService(
                    new AttachmentRepository($pdo, $context->encryption),
                    $accounts,
                    new TransactionAttachmentRepository($pdo),
                    $fileStorage,
                    new TransactionRepository($pdo, $context->encryption),
                    $context->settings,
                    // Read like every other receipt (#1342 §A).
                    new ReceiptExtractionService(
                        SchedulerService::forPdo($pdo),
                        $context->getOptional(LlmConnectorInterface::class)
                    )
                ),
                (int) (new ScoutYearService($pdo))->getCurrentYear()['id']
            ),
            // The same trace both composition roots install: an id and a
            // type, never the filename (§8.6).
            static function (\Throwable $e, string $mimeType, int $attachmentId) use ($context): void {
                $context->journal->log(
                    'finance',
                    'inbound_receipt_not_filed',
                    'warning',
                    "Reçu reçu par courriel non classé : le module finances l'a refusé",
                    [
                        'attachment_id' => $attachmentId,
                        'mime_type' => $mimeType,
                        'exception' => $e::class,
                        'reason' => $e->getMessage(),
                    ],
                    null
                );
            }
        );
        $readFile = static fn(int $fileId): ?string => $files->read($fileId);

        $references = [FinanceMessageConsumer::REFERENCE_UNKNOWN];
        foreach ($accounts->findAllOrdered() as $account) {
            $references[] = FinanceMessageConsumer::referenceFor($account->id);
        }

        $considered = 0;
        foreach ($references as $reference) {
            foreach ($inboundMail->findForReference(FinanceMessageConsumer::CONSUMER_ID, $reference) as $message) {
                $considered += $filer->catchUp(
                    $message,
                    $message->linksFor(FinanceMessageConsumer::CONSUMER_ID),
                    $readFile
                );
                if ($considered >= self::BATCH_SIZE) {
                    return $considered;
                }
            }
        }

        return $considered;
    }

    /**
     * Seed the chain until the history is caught up — from the shared
     * scheduler bootstrap, so on every request: seed() and not rearm(), see
     * SchedulerService::seed().
     */
    public static function bootstrap(SchedulerService $scheduler, SettingService $settings): void
    {
        if ((string) $settings->get(self::DONE_SETTING, 'finance', '') !== '') {
            return;
        }

        $scheduler->seedAfter('finance', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
