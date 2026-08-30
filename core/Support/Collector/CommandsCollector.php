<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use Core\System\ExecutableLocator;
use Core\System\ShellExecutor;

/**
 * `commands.txt` — for every external command this codebase actually
 * shells out to: whether it is available, where it resolved, and what
 * version answered.
 *
 * The list is **derived from the code**, not from what a diagnostic tool
 * usually probes. As of this iteration:
 *
 * - `ffmpeg`, `ffprobe` — `Modules\Gallery\Service\VideoProcessingService`
 *   and `FfmpegAvailability` (gallery video transcoding);
 * - `gs`, `qpdf`, `pdftocairo` — `Core\Pdf\PdfCompressor`'s three
 *   interchangeable backends for section-document compression.
 *
 * Beside that list, and clearly separated from it, sits an inventory of
 * binaries **nothing here calls**. Their presence or absence is a fact
 * about the host, and this archive is read by something that cannot ask a
 * follow-up question — a list curated down to current usage necessarily
 * misses the next dependency somebody adds. `mysql` and `mysqldump` lead
 * it for a specific reason: dumping (`Core\Database\DatabaseDumper`) and
 * restoring (`Core\Database\DatabaseRestorer`) became pure PHP over PDO
 * when those binaries proved unusable on the production host, and whether
 * they exist at all is the first thing anyone re-litigating that decision
 * asks. What changed is the question — from "what does the code need" to
 * "what does the code need, and what does this host have".
 *
 * Everything here is best effort. A missing binary is a *result*, not a
 * failure — gallery video and PDF compression are both optional features
 * that degrade cleanly — and a host where shell execution does not work at
 * all reports that once, up front, rather than as five identical errors.
 *
 * That last verdict is **demonstrated, not declared**
 * (`ShellExecutor::probe()`). Reading `disable_functions` answers whether
 * the function may be called, which on a shared host is regularly wrong in
 * the optimistic direction: a security module intercepts the call, the
 * account's shell is `/sbin/nologin`, the filesystem is mounted `noexec`,
 * PATH is empty. All of those read as "available" from the ini and as
 * "binary missing" from here — five commands reported absent when the real
 * answer was that nothing can run at all. Both answers are now printed,
 * and they are never merged into one line.
 */
class CommandsCollector implements SupportCollectorInterface
{
    /** @var array<string, array{0: string, 1: string}> command => [version flag, what uses it] */
    private const COMMANDS = [
        'ffmpeg' => ['-version', 'Modules\Gallery — transcodage vidéo'],
        'ffprobe' => ['-version', 'Modules\Gallery — analyse vidéo'],
        'gs' => ['-version', 'Core\Pdf\PdfCompressor — compression PDF (Ghostscript)'],
        'qpdf' => ['--version', 'Core\Pdf\PdfCompressor — compression PDF (repli qpdf)'],
        'pdftocairo' => ['-v', 'Core\Pdf\PdfCompressor — compression PDF (repli pdftocairo)'],
    ];

    /**
     * Binaries nothing in this codebase calls, probed anyway.
     *
     * Their presence or absence is a fact about the HOST, and the archive
     * is read by something that cannot ask a follow-up question. `mysql`
     * and `mysqldump` head the list precisely because they are absent from
     * the one above: dumping and restoring became pure PHP over PDO when
     * those binaries proved unusable on the production host, and "are they
     * there at all" remains the first thing anyone re-litigating that
     * decision wants to know.
     *
     * @var array<string, string> command => version flag
     */
    private const HOST_INVENTORY = [
        'mysql' => '--version',
        'mysqldump' => '--version',
        'php' => '-v',
        'git' => '--version',
        'curl' => '--version',
        'wget' => '--version',
        'openssl' => 'version',
        'zip' => '-v',
        'unzip' => '-v',
        'tar' => '--version',
        'gzip' => '--version',
        'convert' => '-version',
        'identify' => '-version',
        'exiftool' => '-ver',
        'pdfinfo' => '-v',
        'pdftoppm' => '-v',
        'sendmail' => '-d0.1',
        'crontab' => '-V',
    ];

    public function name(): string
    {
        return 'commands';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $shell = ShellExecutor::probe();
        $shellAvailable = $shell['works'];

        $lines = [];
        $lines[] = '# Commandes externes utilisées par ScoutMagic';
        $lines[] = '# Une commande absente n\'est pas une anomalie : les fonctionnalités concernées';
        $lines[] = '# (vidéo de la galerie, compression PDF) se désactivent proprement.';
        $lines[] = '';
        // Declared and demonstrated are two different answers, and the
        // gap between them is where a support question usually lives: a
        // host can leave exec() out of disable_functions and still get
        // nowhere with it. Both are reported, never merged.
        $lines[] = 'Exécution de commandes déclarée : ' . ($shell['declared'] ? 'oui' : 'non (disable_functions)');
        $lines[] = 'Exécution de commandes vérifiée : ' . ($shell['works'] ? 'oui' : 'NON');
        $lines[] = 'Fonction utilisée : ' . ($shell['function'] ?? '(aucune)');
        $lines[] = 'Détail de la vérification : ' . $shell['detail'];
        $lines[] = 'proc_open disponible : ' . (function_exists('proc_open') ? 'oui' : 'non');
        $lines[] = '';

        if (!$shellAvailable) {
            $context->markUnavailable(
                $shell['declared'] ? 'shell_execution_declared_but_not_working' : 'shell_execution_disabled'
            );
            foreach ([...array_keys(self::COMMANDS), ...array_keys(self::HOST_INVENTORY)] as $command) {
                $lines[] = $command . ' : non vérifiable (l\'exécution de commandes ne fonctionne pas ici)';
            }
            $context->addFileFromContent('commands.txt', implode("\n", $lines) . "\n");

            return;
        }

        $lines[] = '# Utilisées par le code';
        $lines[] = '';
        foreach (self::COMMANDS as $command => [$versionFlag, $usedBy]) {
            $this->report($lines, $command, $versionFlag, $usedBy);
        }

        // Beside the list derived from the code, not instead of it: what
        // the host happens to have is a fact worth recording even when
        // nothing here calls it. See HOST_INVENTORY.
        $lines[] = '# Inventaire de l\'hôte (rien dans ce code ne les appelle)';
        $lines[] = '';
        foreach (self::HOST_INVENTORY as $command => $versionFlag) {
            $this->report($lines, $command, $versionFlag, 'inventaire seulement');
        }

        $context->addFileFromContent('commands.txt', implode("\n", $lines) . "\n");
    }

    /**
     * @param array<int, string> $lines
     */
    private function report(array &$lines, string $command, string $versionFlag, string $usedBy): void
    {
        $lines[] = '## ' . $command . ' — ' . $usedBy;

        $path = ExecutableLocator::find($command);
        if ($path === null) {
            $lines[] = 'disponible : non';
            $lines[] = '';

            return;
        }

        $lines[] = 'disponible : oui';
        $lines[] = 'chemin : ' . $path;
        $lines[] = 'version : ' . $this->probeVersion($path, $versionFlag);
        $lines[] = '';
    }

    /**
     * A version banner, or a short note when the binary resolved but would
     * not answer. Never fatal: a binary that exists and refuses to run is
     * itself a useful diagnostic.
     */
    private function probeVersion(string $path, string $versionFlag): string
    {
        try {
            $result = ShellExecutor::run(
                escapeshellcmd($path) . ' ' . escapeshellarg($versionFlag) . ' 2>&1'
            );
        } catch (\Throwable) {
            return '(non obtenue)';
        }

        $output = trim((string) preg_replace('/\s+/u', ' ', $result['output']));
        if ($output === '') {
            return '(non obtenue, code ' . $result['returnCode'] . ')';
        }

        return mb_substr($output, 0, 200);
    }
}
