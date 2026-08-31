<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * One module's standing with one mailbox: may it sort this box, and how
 * much of it may its users read.
 *
 * The absence of a scope is itself an answer — « ne rien faire ». A module
 * installed after a box was configured is therefore inert on it until
 * somebody says otherwise, which is the only safe default for a setting
 * whose entire subject is who sees what.
 */
class MailboxScope
{
    public function __construct(
        public readonly string $consumerId,
        public readonly bool $analyzes = false,
        public readonly ReadMode $readMode = ReadMode::NONE
    ) {
    }

    /** Nothing at all — what a module with no row gets. */
    public static function inert(string $consumerId): self
    {
        return new self($consumerId, false, ReadMode::NONE);
    }

    /**
     * A consumer that does not analyse a box cannot read it either.
     *
     * Enforced here rather than trusted from the database, because the two
     * columns can be written independently and « personne ne classe ce
     * courrier, mais tout le monde peut le lire » is not a state the screen
     * can produce or that anybody meant.
     */
    public function effectiveReadMode(): ReadMode
    {
        return $this->analyzes ? $this->readMode : ReadMode::NONE;
    }
}
