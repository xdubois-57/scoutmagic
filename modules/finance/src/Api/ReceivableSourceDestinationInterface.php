<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * The screen where a source module's receivables are managed — the second
 * half of {@see ReceivableSourceDescriberInterface}, implemented by the
 * same object (issue #836).
 *
 * A neighbour rather than a new method on that interface: a module that can
 * name its receivables is not thereby a module with a screen to send a
 * treasurer to, and one without such a screen should not have to write a
 * method that answers null for ever. Finance asks a describer whether it
 * also implements this, and offers no link when it does not.
 *
 * Finance still learns nothing about the source: the module resolves its
 * own address, from its own id, for this viewer.
 */
interface ReceivableSourceDestinationInterface
{
    /**
     * Where ONE of this module's references is managed, for this viewer —
     * or null when there is no such screen, when the object is gone, or
     * when that screen would refuse this viewer.
     *
     * Called once per group rendered, or once per row where a source's
     * groups hold a single receivable each.
     */
    public function destinationFor(int $sourceReferenceId, ReceivableViewer $viewer): ?ReceivableDestination;
}
