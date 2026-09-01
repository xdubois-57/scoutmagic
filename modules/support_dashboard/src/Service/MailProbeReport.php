<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

/**
 * The probes of one installation, written out as a text file a maintainer
 * can keep, diff, or paste into a mail to a hosting provider.
 *
 * **Why a second download and not the archive.** The diagnostic archive
 * on a ticket is the file the *instance* uploaded: it was built and
 * encrypted before any of this happened, and there is nothing to add to
 * it that would not mean rewriting somebody else's evidence. What was
 * missing is on this side of the wire — the messages this receiver
 * actually got and the headers they came with — so it is its own file,
 * offered next to the archive because that is where it was looked for.
 *
 * **Plain text on purpose.** A header block is plain text; wrapping it in
 * JSON or a spreadsheet cell is how the folded continuation lines that
 * carry half the diagnosis get mangled. It is written to be read exactly
 * as a mail client's « voir la source » shows it.
 */
final class MailProbeReport
{
    /**
     * @param list<array<string, mixed>> $probes as
     *        `MailProbeService::resultsFor()` returns them
     */
    public static function build(string $ticketReference, array $probes): string
    {
        $lines = [
            'Sondes e-mail de diagnostic — ticket ' . $ticketReference,
            'Généré le ' . (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            '',
            'Les sondes appartiennent à l\'installation, pas au ticket : elles couvrent',
            'toutes celles que cette installation a demandées, avant comme après.',
            '',
            str_repeat('=', 72),
        ];

        if ($probes === []) {
            $lines[] = '';
            $lines[] = 'Cette installation n\'a jamais demandé de sonde.';

            return implode("\n", $lines) . "\n";
        }

        foreach ($probes as $probe) {
            $lines = array_merge($lines, self::one($probe), [str_repeat('=', 72)]);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $probe
     * @return list<string>
     */
    private static function one(array $probe): array
    {
        $authentication = is_array($probe['authentication'] ?? null) ? $probe['authentication'] : [];
        $received = $probe['received_at'] !== null;

        $lines = [
            '',
            'Clé de corrélation : ' . (string) ($probe['correlation_key'] ?? ''),
            'Boîte              : ' . (string) ($probe['mailbox_address'] ?? ''),
            'Émise le           : ' . (string) ($probe['issued_at'] ?? ''),
            'Expire le          : ' . (string) ($probe['expires_at'] ?? ''),
            'Réception          : ' . ($received ? (string) $probe['received_at'] : 'jamais reçue à ce jour'),
        ];

        if ($received && $probe['delay_seconds'] !== null) {
            $lines[] = 'Délai              : ' . (string) $probe['delay_seconds'] . ' s';
        }

        if ($received) {
            foreach (['spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $key => $label) {
                $lines[] = str_pad($label, 19) . ': ' . self::verdictLabel((string) ($authentication[$key] ?? 'absent'));
            }
        }

        $relays = is_array($authentication['relays'] ?? null) ? $authentication['relays'] : [];
        if ($relays !== []) {
            $lines[] = '';
            $lines[] = 'Chaîne de relais :';
            foreach ($relays as $index => $relay) {
                $lines[] = '  ' . ($index + 1) . '. ' . (string) $relay;
            }
        }

        $rawHeaders = $probe['raw_headers'] ?? null;
        $lines[] = '';
        if (is_string($rawHeaders) && $rawHeaders !== '') {
            $lines[] = 'En-têtes reçus :';
            $lines[] = '';
            foreach (preg_split('/\R/', $rawHeaders) ?: [] as $headerLine) {
                $lines[] = '  ' . $headerLine;
            }
        } elseif ($received) {
            $lines[] = 'En-têtes reçus : aucun conservé — sonde antérieure à leur conservation,'
                . ' ou boîte n\'en ayant transmis aucun (ce qui explique à soi seul trois'
                . ' verdicts « non renseigné »).';
        }

        return $lines;
    }

    /**
     * The same words the page uses. « absent » on a screen and « absent »
     * in a file that a maintainer forwards to a hosting provider have to
     * say the same thing, and « non renseigné » is the one that does not
     * read as a failure.
     */
    private static function verdictLabel(string $verdict): string
    {
        return match ($verdict) {
            'pass' => 'réussi',
            'fail' => 'échec',
            'absent' => 'non renseigné',
            'unverified' => 'signée, non vérifiée',
            default => $verdict,
        };
    }
}
