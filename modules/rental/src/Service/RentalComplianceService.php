<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\Journal\JournalService;
use Modules\Rental\Compliance\ComplianceItem;
use Modules\Rental\Repository\RentalComplianceRepository;

/**
 * The asset paperwork register (§6.33).
 *
 * **This is a reminder list, not a compliance check, and the code is built
 * so it cannot quietly become one.** There is no rule engine, no status
 * derived from a label, no "missing document" warning — the module has no
 * idea what paperwork a hall in one commune needs versus another, and
 * inventing an answer would be a legal opinion dressed as a green tick. The
 * page says so in as many words, and this service backs that up by only
 * ever answering "what expires, and when".
 *
 * **Suggested labels live in a setting, never in the code** (§6.33). "Label
 * Atouts Camps" and "attestation incendie" are what the federation and the
 * communes call things *today*; a rename must cost a unit one text field,
 * not a software release.
 */
class RentalComplianceService
{
    public const SETTING_LABEL_SUGGESTIONS = 'compliance_label_suggestions';
    private const MODULE = 'rental';

    /**
     * How far ahead the register warns. Two months is roughly what renewing
     * a communal authorisation or an insurance policy takes once somebody
     * starts chasing it — and short enough that the warning still feels
     * like news rather than background noise.
     */
    public const EXPIRY_WARNING_DAYS = 60;

    /**
     * Documents proving a hall's paperwork are internal: readable by the
     * asset's managers and the Staff d'U, never by a renter and never
     * publicly.
     */
    public const FILE_ROLE_MIN = 'identified';
    public const STORAGE_SUBDIRECTORY = 'rental/compliance';
    public const FILE_OWNER_TYPE = 'rental_compliance';

    public function __construct(
        private RentalComplianceRepository $repository,
        private SettingService $settingService,
        private JournalService $journal,
        private ?FileRepository $fileRepository = null
    ) {
    }

    /**
     * @return ComplianceItem[]
     */
    public function forAsset(int $assetId): array
    {
        return $this->repository->findForAsset($assetId);
    }

    /**
     * The labels offered as a starting point when adding an entry.
     *
     * Suggestions, in the strict sense: any other text is accepted, and
     * nothing about the register behaves differently for a suggested label
     * than for one somebody typed.
     *
     * @return string[]
     */
    public function labelSuggestions(): array
    {
        $raw = (string) ($this->settingService->get(self::SETTING_LABEL_SUGGESTIONS, self::MODULE) ?: '');

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $label) => $label !== ''
        ));
    }

    /**
     * @throws RentalException
     */
    public function add(
        int $assetId,
        string $label,
        ?string $expiresOn,
        ?string $remark,
        ?int $fileId = null,
        ?int $actorMemberId = null
    ): int {
        $label = trim($label);
        if ($label === '') {
            throw new RentalException('Un intitulé est nécessaire.');
        }

        $expiresOn = self::normaliseDate($expiresOn);

        $id = $this->repository->create($assetId, $label, $fileId, $expiresOn, self::optionalText($remark));

        // The label is about a document, never about a person — which is
        // exactly why the interface has to keep it that way (SECURITY.md
        // §5), and why it is safe here.
        $this->journal->log(
            'rental',
            'rental_compliance_added',
            'info',
            'Entrée de conformité ajoutée : ' . $label,
            ['asset_id' => $assetId, 'item_id' => $id],
            $actorMemberId
        );

        return $id;
    }

    /**
     * @throws RentalException
     */
    public function update(
        int $assetId,
        int $itemId,
        string $label,
        ?string $expiresOn,
        ?string $remark,
        ?int $actorMemberId = null
    ): void {
        $item = $this->requireItemOfAsset($assetId, $itemId);

        $label = trim($label);
        if ($label === '') {
            throw new RentalException('Un intitulé est nécessaire.');
        }

        $this->repository->update($itemId, $label, self::normaliseDate($expiresOn), self::optionalText($remark));

        $this->journal->log(
            'rental',
            'rental_compliance_updated',
            'info',
            'Entrée de conformité modifiée : ' . $item->label,
            ['asset_id' => $assetId, 'item_id' => $itemId],
            $actorMemberId
        );
    }

    /**
     * @throws RentalException
     */
    public function attachFile(int $assetId, int $itemId, int $fileId, ?int $actorMemberId = null): void
    {
        $item = $this->requireItemOfAsset($assetId, $itemId);

        $this->repository->setFile($itemId, $fileId);

        // The file being replaced is deleted only after the row points at
        // the new one: a failure between the two leaves an orphan file,
        // which is recoverable, rather than a register entry pointing at
        // nothing, which is not.
        if ($item->fileId !== null && $item->fileId !== $fileId) {
            $this->fileRepository?->delete($item->fileId);
        }

        $this->journal->log(
            'rental',
            'rental_compliance_file_attached',
            'info',
            'Document de conformité mis à jour : ' . $item->label,
            ['asset_id' => $assetId, 'item_id' => $itemId],
            $actorMemberId
        );
    }

    /**
     * @throws RentalException
     */
    public function delete(int $assetId, int $itemId, ?int $actorMemberId = null): void
    {
        $item = $this->requireItemOfAsset($assetId, $itemId);

        $this->repository->delete($itemId);

        if ($item->fileId !== null) {
            $this->fileRepository?->delete($item->fileId);
        }

        $this->journal->log(
            'rental',
            'rental_compliance_deleted',
            'warning',
            'Entrée de conformité supprimée : ' . $item->label,
            ['asset_id' => $assetId, 'item_id' => $itemId],
            $actorMemberId
        );
    }

    /**
     * The entries a reminder run should speak up about: expired, or expiring
     * inside the warning window, and not already warned about since the
     * last change.
     *
     * @return ComplianceItem[]
     */
    public function dueForReminder(\DateTimeImmutable $today): array
    {
        $limit = $today->setTime(0, 0)->modify('+' . self::EXPIRY_WARNING_DAYS . ' days')->format('Y-m-d');

        return array_values(array_filter(
            $this->repository->findExpiringBy($limit),
            static fn(ComplianceItem $item) => $item->remindedOn === null
                || $item->remindedOn < $today->format('Y-m-d')
        ));
    }

    public function markReminded(int $itemId, \DateTimeImmutable $on): void
    {
        $this->repository->markReminded($itemId, $on->format('Y-m-d'));
    }

    /**
     * @throws RentalException
     */
    private function requireItemOfAsset(int $assetId, int $itemId): ComplianceItem
    {
        $item = $this->repository->findById($itemId);
        if ($item === null || $item->assetId !== $assetId) {
            // The asset check is the real guard: an item id alone must not
            // let a manager of one asset touch another asset's register.
            throw new RentalException("Cette entrée n'existe pas.");
        }

        return $item;
    }

    /**
     * An empty date field means "no expiry", not "today" — the difference
     * between paperwork that never runs out and paperwork that ran out this
     * morning.
     */
    private static function normaliseDate(?string $date): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);

        return $parsed !== false ? $parsed->format('Y-m-d') : null;
    }

    private static function optionalText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
