<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\File\EncryptedFileStorageService;
use Core\Journal\JournalService;
use Core\Security\Role;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRow;
use Modules\Finance\Repository\CampaignRowRepository;

/**
 * Creating a payment campaign, and the four gestures that follow it:
 * closing it, marking the families notified, writing the treasurers'
 * note on one line, and forgetting the source spreadsheet once its
 * retention has run out.
 *
 * A campaign invoices an amount to each member of a list and follows the
 * payments. **One receivable per member** — a family of three receives
 * three requests, each with its own structured communication. The
 * household decides the tariff; it never decides the receivable, which
 * is what makes a transfer identifiable when it lands. The federation's
 * own invoice is nominative for the same reason.
 *
 * Each row is the `source_reference_id` of the receivable it produces,
 * under `source_module = 'finance'` — never a `'manual'` catch-all,
 * which would make the receivable's origin unreadable the moment there
 * were two kinds of them.
 *
 * Nothing here is a draft: the receivables exist from the import
 * onwards, so there is nothing a draft could usefully hold back. Two
 * states, open and closed, and closing freezes the campaign without
 * hiding it.
 */
class CampaignService
{
    public const SOURCE_MODULE = 'finance';

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const STORAGE_SUBDIRECTORY = 'finance/campaigns';

    public function __construct(
        private \PDO $pdo,
        private CampaignRepository $campaigns,
        private CampaignRowRepository $rows,
        private CampaignImportService $importService,
        private ExpectedReceivableInterface $receivables,
        private StructuredCommunicationService $communications,
        private AccountRepository $accountRepository,
        private AccountVisibility $accountVisibility,
        private EncryptedFileStorageService $fileStorage,
        private JournalService $journal
    ) {
    }

    /**
     * Reads the file, and only if every one of its lines resolves does it
     * create anything.
     *
     * The whole thing runs in one database transaction: a campaign whose
     * rows landed but whose receivables did not would look complete and
     * collect nothing, and nobody would find out until somebody wondered
     * why a family was never asked. The uploaded file is stored first,
     * outside the transaction, and deleted again if the transaction
     * fails — a filesystem cannot roll back, so the order is: write the
     * bytes, write the rows, and undo the bytes by hand on the way out.
     *
     * @throws CampaignImportException when the file is refused — nothing
     *         at all is created in that case
     * @throws FinanceException when the label, the account or the season
     *         is not usable
     */
    public function createFromFile(
        string $label,
        int $scoutYearId,
        int $accountId,
        string $filePath,
        string $originalFilename,
        Role $viewerRole,
        ?int $actorUserAccountId
    ): int {
        $label = trim($label);
        if ($label === '') {
            throw new FinanceException('Donnez un nom à la campagne.');
        }
        if (mb_strlen($label) > 150) {
            throw new FinanceException('Le nom de la campagne est trop long (150 caractères au maximum).');
        }

        $account = $this->accountRepository->findById($accountId);
        if (!$this->accountVisibility->isVisibleTo($account, $viewerRole)) {
            throw new FinanceException("Ce compte n'existe pas ou ne vous est pas accessible.");
        }

        // Read and validate BEFORE storing anything: a refused file must
        // leave no trace at all, not even an orphan blob on disk.
        $import = $this->importService->read($filePath);

        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            throw new FinanceException("Le fichier chargé n'a pas pu être relu.");
        }

        $fileId = $this->fileStorage->store(
            $contents,
            self::XLSX_MIME,
            $originalFilename,
            self::STORAGE_SUBDIRECTORY,
            'intendant',
            'finance',
            $actorUserAccountId
        );

        $this->pdo->beginTransaction();
        try {
            $campaignId = $this->campaigns->create(
                $label,
                $scoutYearId,
                $accountId,
                $fileId,
                $originalFilename,
                $import->mergeColumns,
                $actorUserAccountId
            );

            foreach ($import->rows as $row) {
                $rowId = $this->rows->create(
                    $campaignId,
                    $row['member_id'],
                    $row['amount_cents'],
                    $row['line'],
                    $row['merge_data']
                );

                $this->receivables->createReceivable(
                    self::SOURCE_MODULE,
                    $rowId,
                    $accountId,
                    $row['amount_cents'],
                    $this->communications->generate(),
                    // No label: the payer is named by member_id, and a
                    // decrypted name repeated here would be one more copy
                    // of the same personal data for no reader.
                    null,
                    $row['member_id']
                );
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->fileStorage->delete($fileId);
            throw $e;
        }

        $this->journal->log(
            'finance',
            'campaign_created',
            'info',
            'Campagne de paiement créée',
            [
                'campaign_id' => $campaignId,
                'account_id' => $accountId,
                'scout_year_id' => $scoutYearId,
                'row_count' => $import->count(),
                'total_cents' => $import->totalCents(),
            ],
            $actorUserAccountId
        );

        return $campaignId;
    }

    /**
     * @throws FinanceException
     */
    public function close(int $campaignId, Role $viewerRole, ?int $actorUserAccountId): void
    {
        $campaign = $this->requireCampaign($campaignId, $viewerRole);

        $this->campaigns->setStatus($campaign->id, Campaign::STATUS_CLOSED, date('Y-m-d H:i:s'));
        $this->journal->log(
            'finance',
            'campaign_closed',
            'info',
            'Campagne de paiement clôturée',
            ['campaign_id' => $campaign->id],
            $actorUserAccountId
        );
    }

    /**
     * @throws FinanceException
     */
    public function reopen(int $campaignId, Role $viewerRole, ?int $actorUserAccountId): void
    {
        $campaign = $this->requireCampaign($campaignId, $viewerRole);

        $this->campaigns->setStatus($campaign->id, Campaign::STATUS_OPEN, null);
        $this->journal->log(
            'finance',
            'campaign_reopened',
            'info',
            'Campagne de paiement rouverte',
            ['campaign_id' => $campaign->id],
            $actorUserAccountId
        );
    }

    /**
     * The treasurers' own note on one receivable, **never visible to the
     * family**. A blank note clears it.
     *
     * The journal records the row's id and nothing else: a note is free
     * text about a person, and the whole point of a journal is that it
     * can be read by somebody who has no business reading that.
     *
     * @throws FinanceException
     */
    public function setNote(int $rowId, ?string $note, Role $viewerRole, ?int $actorUserAccountId): void
    {
        $row = $this->rows->findById($rowId);
        if ($row === null) {
            throw new FinanceException("Cette créance n'existe pas.");
        }
        $this->requireCampaign($row->campaignId, $viewerRole);

        $this->rows->setNote($row->id, $note, $actorUserAccountId);
    }

    /**
     * @throws FinanceException
     */
    public function requireCampaign(int $campaignId, Role $viewerRole): Campaign
    {
        $campaign = $this->campaigns->findById($campaignId);
        if ($campaign === null) {
            throw new FinanceException("Cette campagne n'existe pas.");
        }

        // Same predicate as every other finance page: a campaign booked
        // against a section's account belongs to that section's
        // treasurer, here as anywhere else.
        if (!$this->accountVisibility->isVisibleTo(
            $this->accountRepository->findById($campaign->accountId),
            $viewerRole
        )) {
            throw new FinanceException("Cette campagne n'existe pas.");
        }

        return $campaign;
    }

    /**
     * Records that the treasurer has told the families. Not the creation
     * of the campaign: the reminder leaves by hand from the mass-mail
     * draft, so a notification sent at import time would announce a
     * message nobody has written yet.
     *
     * @throws FinanceException
     */
    public function markNotified(int $campaignId, Role $viewerRole, ?int $actorUserAccountId): Campaign
    {
        $campaign = $this->requireCampaign($campaignId, $viewerRole);

        $this->campaigns->markNotified($campaign->id, date('Y-m-d H:i:s'), $actorUserAccountId);

        $refreshed = $this->campaigns->findById($campaign->id);
        \assert($refreshed !== null);

        return $refreshed;
    }

    /**
     * Deletes a campaign's source spreadsheet and the columns copied out
     * of it, once its scout year has fallen out of the retention window.
     *
     * The campaign, its rows, its amounts and its receivables all stay:
     * they are the financial history, and nothing about them was ever
     * temporary. What goes is the personal data the file carried and the
     * copy of it kept for the merge variables — keeping the copy would
     * make the deletion of the file decorative.
     */
    public function forgetSourceFile(Campaign $campaign): void
    {
        if ($campaign->sourceFileId !== null) {
            $this->fileStorage->delete($campaign->sourceFileId);
        }

        $this->rows->forgetMergeDataForCampaign($campaign->id);
        $this->campaigns->forgetSourceFile($campaign->id);
    }

    /**
     * @return CampaignRow[]
     */
    public function rowsOf(int $campaignId): array
    {
        return $this->rows->findByCampaignId($campaignId);
    }
}
