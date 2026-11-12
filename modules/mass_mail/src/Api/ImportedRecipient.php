<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Api;

/**
 * One address an imported email went to, with what the former site knew
 * of it. Three states and no fourth: `accepted` (the sending server took
 * the message — shown « Accepté par le serveur d'envoi », never « Remis »,
 * which nothing proves), `failed`, and `unknown`.
 */
final class ImportedRecipient
{
    public const ACCEPTED = 'accepted';
    public const FAILED = 'failed';
    public const UNKNOWN = 'unknown';

    /**
     * @param list<int> $memberIds members.id the address belonged to
     */
    public function __construct(
        public readonly string $address,
        public readonly string $status = self::UNKNOWN,
        public readonly ?\DateTimeImmutable $sentAt = null,
        public readonly array $memberIds = []
    ) {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A recipient address is an address.');
        }
        if (!in_array($status, [self::ACCEPTED, self::FAILED, self::UNKNOWN], true)) {
            throw new \InvalidArgumentException('A recipient status is accepted, failed or unknown.');
        }
    }
}
