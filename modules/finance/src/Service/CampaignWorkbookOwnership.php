<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Config\SettingService;
use Modules\Finance\File\FinanceAccountOwnershipChecker;
use Modules\Finance\Repository\CampaignRepository;

/**
 * Gives every campaign workbook stored before issue #1111 the owner it would
 * get today — once — so it is served under its account's rule like a
 * receipt rather than at a flat `intendant`. CampaignRepository::
 * backfillSourceFileOwnership() does the set-based stamping; this decides
 * whether to run it, the shape of ReceiptService::ensureReceiptFileOwnership().
 *
 * **Called whether or not the finance module is enabled**, which is the one
 * way it differs from the receipts': with the module disabled at upgrade an
 * unowned workbook would still be served by /files/{id}, which does not ask
 * which module a file belongs to, whereas a stamped one is refused to all
 * while the module's ownership checker is away. Its flag is the module's
 * own, read in the module's scope like every module setting
 * (ModuleSettingsAreReadInTheirScopeTest): registering a setting writes a
 * row and asks nothing of the module's state. The finance tables exist
 * either way, the whole declared schema being migrated.
 *
 * The flag is read through SettingService's per-request cache, so every run
 * after the first costs one array lookup and no query.
 */
final class CampaignWorkbookOwnership
{
    public const DONE_FLAG = 'campaign_workbooks_owned';

    private const MODULE_ID = 'finance';

    public function __construct(
        private CampaignRepository $campaigns,
        private SettingService $settings
    ) {
    }

    /** @return int how many workbooks were stamped — 0 on every run after the first */
    public function ensure(): int
    {
        if ($this->settings->get(self::DONE_FLAG, self::MODULE_ID, '0') === '1') {
            return 0;
        }

        $stamped = $this->campaigns->backfillSourceFileOwnership(FinanceAccountOwnershipChecker::OWNER_TYPE);

        $this->settings->register(
            self::DONE_FLAG,
            '0',
            'boolean',
            'Classeurs de campagne rattachés à leur compte',
            'Indique si les classeurs sources des campagnes existantes ont reçu leur compte comme propriétaire.',
            self::MODULE_ID,
            null,
            null,
            false,
            999
        );
        $this->settings->setInternal(self::DONE_FLAG, '1', self::MODULE_ID);

        return $stamped;
    }
}
