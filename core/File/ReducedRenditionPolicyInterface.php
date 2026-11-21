<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * The registry's rendition-aware question (issue #1108, #1265): may THIS
 * reduced rendition of this owner's file — a derivative from
 * Core\Photo\ImageVariantService, never the original — be served on
 * `role_min` alone?
 *
 * Optional, like {@see FileIndexingPolicyInterface}. A checker that does
 * not implement it is asked {@see FileOwnershipCheckerInterface::isAllowed()}
 * for every rendition, which is the conservative answer: the owner rule
 * applies to the thumbnail too. One that does implement it can say that a
 * face shown small on a group's member list is not the same disclosure as
 * a sharp, full photograph, and keep its rule for the rest.
 *
 * **The rendition is part of the question, and the answer is a closed
 * list.** A policy that only saw the owner answered for every derivative
 * the same way, so one that said yes to the thumbnail also said yes to a
 * 1024 px `md` the day one was generated. An implementation names the
 * renditions it lets through and returns false for every other value —
 * present or future — which then follows the owner's rule: never "anything
 * but the original".
 */
interface ReducedRenditionPolicyInterface
{
    /**
     * Whether the reduced rendition `$rendition` (`thumb`, `md`, …) of the
     * file owned by $ownerId is served to whoever already passes the file's
     * `role_min`. False falls back to the owner's own rule.
     */
    public function allowsReducedRendition(int $ownerId, string $rendition): bool;
}
