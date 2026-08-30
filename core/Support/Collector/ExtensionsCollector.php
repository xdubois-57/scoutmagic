<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `extensions.txt` — every PHP extension this installation actually needs,
 * whether it is loaded, and what stops working when it is not.
 *
 * `phpinfo.html` already lists the loaded extensions, and that is not the
 * same thing. It answers "what does this host have", in a page nobody
 * reads to the end; the question a support request actually asks is "what
 * does ScoutMagic need that this host does not have", which no amount of
 * scrolling through phpinfo answers — you have to already know the list to
 * spot what is missing from it. So the list lives here, and the file names
 * the consequence of each absence rather than leaving it to be inferred.
 *
 * **Derived from the code and from what the dependencies declare**, the
 * same rule CommandsCollector follows for binaries — not from what a
 * diagnostic tool usually probes. Each entry names what would break, and
 * that attribution is the part worth keeping accurate: an extension listed
 * here whose absence turns out to break nothing is noise, and one that is
 * missing from the list is a support question nobody can answer.
 *
 * Required and optional are kept apart because they lead to different
 * conversations. A missing required extension means the site is broken or
 * about to be; a missing optional one means a feature degrades, which is a
 * design decision rather than an incident.
 */
class ExtensionsCollector implements SupportCollectorInterface
{
    /** @var array<string, string> extension => what depends on it */
    private const REQUIRED = [
        'pdo' => 'Core\Database\Connection — toute la persistance',
        'pdo_mysql' => 'Core\Database\Connection — le pilote MySQL/MariaDB lui-même',
        'json' => 'partout : payloads de tâches, contexte du journal, checkpoints de migration',
        'mbstring' => 'partout, plus dompdf, web-push et php-imap',
        'openssl' => 'Core\Security\EncryptionService (données personnelles chiffrées), web-push, php-imap',
        'hash' => 'Core\Security — HMAC des jetons et empreintes de schéma',
        'filter' => 'validation des entrées, plus PHPMailer et PhpSpreadsheet',
        'ctype' => 'PHPMailer et PhpSpreadsheet',
        'pcre' => 'partout (aws-sdk le déclare explicitement)',
        'gd' => 'Core\Photo\* et Modules\Gallery — toute la transformation d\'images',
        'zip' => 'ZipArchive : sauvegardes, ce paquet de support, exports XLSX, php-imap',
        'zlib' => 'PhpSpreadsheet et smalot/pdfparser',
        'iconv' => 'smalot/pdfparser et php-imap',
        'fileinfo' => 'détection de type des fichiers téléversés, PhpSpreadsheet, php-imap',
        'dom' => 'dompdf, PhpSpreadsheet',
        'libxml' => 'PhpSpreadsheet, php-imap',
        'simplexml' => 'PhpSpreadsheet, aws-sdk',
        'xml' => 'PhpSpreadsheet',
        'xmlreader' => 'PhpSpreadsheet — lecture XLSX',
        'xmlwriter' => 'PhpSpreadsheet — écriture XLSX',
        'curl' => 'minishlink/web-push (notifications push) et le client OVH de Modules\SosStaff',
    ];

    /** @var array<string, string> extension => which feature degrades */
    private const OPTIONAL = [
        'imagick' => 'Core\File\PdfRasterizer — aperçu des PDF ; sans elle, pas de vignette',
        'exif' => 'orientation des photos téléversées ; sans elle, certaines arrivent tournées',
        'intl' => 'Modules\Rental — formatage local des dates de la page publique',
        'gmp' => 'accélère minishlink/web-push ; sans gmp NI bcmath, le calcul des clés est nettement plus lent',
        'bcmath' => 'repli de gmp pour minishlink/web-push',
        'opcache' => 'performance seule ; son absence n\'est jamais une panne, mais explique des temps de réponse',
        'sockets' => 'suggérée par aws-sdk',
        'pcntl' => 'suggérée par aws-sdk',
    ];

    public function name(): string
    {
        return 'extensions';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $loaded = get_loaded_extensions();
        $lowercase = array_map('strtolower', $loaded);

        $lines = [];
        $lines[] = '# Extensions PHP';
        $lines[] = '#';
        $lines[] = '# La liste est dérivée du code de ScoutMagic et de ce que ses dépendances';
        $lines[] = '# déclarent exiger, pas de ce qu\'un outil de diagnostic sonde d\'habitude.';
        $lines[] = '# phpinfo.html dit ce que cet hébergeur A ; ce fichier-ci dit ce qui MANQUE.';
        $lines[] = '';
        $lines[] = 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')';
        $lines[] = '';

        $missingRequired = $this->section($lines, 'REQUISES', self::REQUIRED, $lowercase);
        $missingOptional = $this->section($lines, 'OPTIONNELLES', self::OPTIONAL, $lowercase);

        $lines[] = '## Verdict';
        if ($missingRequired === []) {
            $lines[] = 'Toutes les extensions requises sont présentes.';
        } else {
            $lines[] = 'MANQUANTES ET REQUISES : ' . implode(', ', $missingRequired);
            $context->addNote(
                'Extensions PHP requises absentes : ' . implode(', ', $missingRequired)
            );
        }
        $lines[] = $missingOptional === []
            ? 'Toutes les extensions optionnelles sont présentes.'
            : 'Absentes mais optionnelles : ' . implode(', ', $missingOptional);
        $lines[] = '';

        sort($loaded);
        $lines[] = '## Toutes les extensions chargées (' . count($loaded) . ')';
        $lines[] = implode(', ', $loaded);

        $context->addFileFromContent('extensions.txt', implode("\n", $lines) . "\n");
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, string> $expected
     * @param array<int, string> $loadedLowercase
     * @return array<int, string> the ones that are missing
     */
    private function section(array &$lines, string $title, array $expected, array $loadedLowercase): array
    {
        $missing = [];
        $lines[] = '## ' . $title;
        foreach ($expected as $extension => $usedBy) {
            $present = in_array($extension, $loadedLowercase, true);
            if (!$present) {
                $missing[] = $extension;
            }
            $lines[] = sprintf('%-10s %-8s %s', $extension, $present ? 'présente' : 'ABSENTE', $usedBy);
        }
        $lines[] = '';

        return $missing;
    }
}
