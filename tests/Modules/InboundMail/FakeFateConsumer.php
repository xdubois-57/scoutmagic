<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Modules\InboundMail\Api\AttachmentFate;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\ReportsAttachmentFates;

/**
 * A consumer that says what became of each attachment its link covers
 * (`Api\ReportsAttachmentFates`, #1406).
 */
class FakeFateConsumer extends FakeMessageConsumer implements ReportsAttachmentFates
{
    /** @var list<array{string, array<int, int>}> what it was asked, as role and member ids */
    public array $asked = [];

    /**
     * @param list<AttachmentFate> $fates
     * @param bool $broken a module whose own tables are unreachable
     */
    public function __construct(string $id, private array $fates = [], private bool $broken = false)
    {
        parent::__construct($id);
    }

    public function attachmentFates(
        InboundMessage $message,
        MessageLink $link,
        string $role,
        array $linkedMemberIds
    ): array {
        $this->asked[] = [$role, $linkedMemberIds];
        if ($this->broken) {
            throw new \RuntimeException('finance tables unreachable');
        }

        return $this->fates;
    }
}
