<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\AttachmentRepository;

/**
 * Receipt upload/replace/archive and linking to movements — not built
 * yet, a later iteration of the module spec. Encrypted-at-rest storage
 * for the underlying file (files.encrypted, a currently-unused core
 * capability — see schema.sql's comment on finance_attachments) is wired
 * up here once this service's real implementation lands.
 */
class ReceiptService
{
    public function __construct(private AttachmentRepository $attachmentRepository)
    {
    }
}
