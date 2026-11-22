<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

use Modules\InboundMail\Mailbox\MailboxCredentials;
use Webklex\PHPIMAP\Folder;

/** Read-only EXAMINE shared by folder status, first-read selection and message queries. */
final class ImapFolderReader
{
    /** @return array<string, mixed> */
    public static function examine(Folder $folder, ?MailboxCredentials $credentials): array
    {
        $response = $folder->getClient()->getConnection()->examineFolder($folder->path);
        if ($response->failed() && $credentials !== null) {
            // Folder::examine() validates before returning: with debug disabled,
            // its exception loses the refusal. Mask known secrets before splitting
            // protocol lines, then preserve only refusals, never sent commands.
            $text = MailboxResponseRedactor::maskKnownSecrets(
                implode('', array_filter($response->getResponse(), 'is_string')), $credentials
            );
            foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
                if (preg_match(
                    '/^\s*(?:\*|[A-Za-z0-9]+)\s+(NO|BAD|BYE)\b(.*)$/',
                    $line,
                    $matches
                ) === 1) {
                    $response->addError(MailboxResponseRedactor::redact($matches[1] . $matches[2], $credentials));
                }
            }
        }

        return $response->validatedData();
    }
}
