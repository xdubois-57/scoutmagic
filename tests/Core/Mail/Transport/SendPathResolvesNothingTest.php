<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use PHPUnit\Framework\TestCase;

/**
 * **Nothing on the send path asks the DNS anything** (issue #422).
 *
 * The attribution of « famille.be » to Google is read from a cache that a
 * scheduled task fills; the transport reads that cache and never resolves.
 * The rule is structural rather than behavioural because its failure is
 * not a wrong answer — it is a mailing that waits ten seconds per message
 * on a resolver that stopped answering, which no functional test running
 * against a healthy machine would ever see. So this reads the code.
 *
 * `MailTransportChainTest` holds the behavioural half: a personal domain
 * follows its provider's decision, and an unknown one is only noted.
 */
class SendPathResolvesNothingTest extends TestCase
{
    /**
     * Every way PHP has to ask a resolver, and the classes of this site
     * that do.
     */
    private const FORBIDDEN = [
        'dns_get_record',
        'getmxrr',
        'checkdnsrr',
        'dns_check_record',
        'dns_get_mx',
        'gethostbyname',
        'gethostbynamel',
        'socket_addrinfo_lookup',
        'MxLookup',
        // `\bMxLookup\b` does not match inside « DnsMxLookup »: the
        // concrete resolver has to be named on its own.
        'DnsMxLookup',
        'DnsRecordReader',
        'DnsVerifier',
        'ResolveMailboxProvidersHandler',
    ];

    /** @return list<string> */
    private static function sendPathFiles(): array
    {
        $root = dirname(__DIR__, 4);
        $files = [
            $root . '/core/Mail/MailService.php',
            $root . '/core/Mail/Feedback/Seed/SeedCopyRepository.php',
        ];

        $found = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root . '/core/Mail/Transport',
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($found as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function testTheSendPathNamesNoResolver(): void
    {
        $files = self::sendPathFiles();
        $this->assertContains(
            dirname(__DIR__, 4) . '/core/Mail/Transport/MailboxProviderRepository.php',
            $files,
            'The cache the send path reads must be among the files checked.'
        );

        foreach ($files as $path) {
            $code = (string) file_get_contents($path);
            foreach (self::FORBIDDEN as $needle) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b' . preg_quote($needle, '/') . '\b/i',
                    // Comments may explain the rule; code may not break it.
                    self::withoutComments($code),
                    basename($path) . ' names « ' . $needle . ' »: the send path must read '
                    . 'mail_domain_providers and never resolve (issue #422).'
                );
            }
        }
    }

    private static function withoutComments(string $code): string
    {
        $kept = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }
}
