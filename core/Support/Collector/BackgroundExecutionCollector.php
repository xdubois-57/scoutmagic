<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use Core\System\ShellExecutor;

/**
 * `background-execution.txt` — whether this installation can run work in
 * the background at all, and by which mechanism.
 *
 * This is the permanent form of the one-off probe that established what
 * the reference host could and could not do: the self-directed HTTP loop
 * works there, on every target, while a detached CLI spawn does not —
 * `system`, `passthru`, `proc_open` and `popen` are all in
 * `disable_functions`. Those two facts decided how this project drives
 * background work, and neither of them was visible in a support package.
 *
 * **What it answers has narrowed, on purpose.** It used to describe the
 * transport of a live mechanism: the scheduler continued itself over that
 * HTTP loop, so « pourquoi la file n'avance pas chez cette unité » was
 * usually a question about the loop. A real crontab is now a requirement,
 * verified before a first install can complete, and `public/cron.php` is
 * the only engine — so the queue's health is `cron-cadence.txt`'s
 * subject, and this file is the environment underneath it: what this host
 * allows a PHP process to do, and whether the site can reach itself at
 * all, which still decides whether a webhook, an update download or an
 * external probe has any chance of working here.
 */
class BackgroundExecutionCollector implements SupportCollectorInterface
{
    /** A support package must never hang on a socket. */
    private const CONNECT_TIMEOUT_SECONDS = 2.0;

    /**
     * Public, cheap, and with NO side effect: generating a support package
     * must never be a way to make the installation do work, nor to make it
     * write a journal entry.
     */
    private const PROBE_PATH = '/api/version';

    public function name(): string
    {
        return 'background_execution';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $lines = [];
        $lines[] = '# Exécution en arrière-plan';
        $lines[] = '#';
        $lines[] = '# scheduled-tasks.xlsx dit ce qui est en file, cron-cadence.txt ce qui s\'est';
        $lines[] = '# réellement passé. Ce fichier-ci dit ce qui est POSSIBLE sur cet hébergement.';
        $lines[] = '';

        $this->mechanisms($lines);
        $this->limits($lines);
        $this->loopback($lines, $context);
        $this->settings($lines, $context);

        $context->addFileFromContent('background-execution.txt', implode("\n", $lines) . "\n");
    }

    /**
     * @param array<int, string> $lines
     */
    private function mechanisms(array &$lines): void
    {
        $shell = ShellExecutor::probe();

        $lines[] = '## Mécanismes disponibles';
        $lines[] = 'fastcgi_finish_request : ' . (function_exists('fastcgi_finish_request') ? 'oui' : 'non')
            . ' — sans elle, tout travail effectué après la réponse fait attendre le visiteur';
        $lines[] = 'stream_socket_client : ' . (function_exists('stream_socket_client') ? 'oui' : 'non')
            . ' — sans elle, le site ne peut ouvrir aucune connexion sortante lui-même';
        $lines[] = 'Exécution shell vérifiée : ' . ($shell['works'] ? 'oui' : 'NON')
            . ' (' . ($shell['function'] ?? 'aucune fonction') . ' — ' . $shell['detail'] . ')';
        $lines[] = 'SAPI : ' . PHP_SAPI;
        $lines[] = '';
        $lines[] = 'Note : le spawn d\'un processus CLI détaché n\'est délibérément pas tenté, ici comme';
        $lines[] = 'ailleurs — il est mesuré non fonctionnel sur l\'hébergement de référence et resterait';
        $lines[] = 'exposé au ramassage des processus orphelins. Le travail de fond de ScoutMagic est';
        $lines[] = 'entièrement porté par le crontab de l\'hébergeur (voir cron-cadence.txt).';
        $lines[] = '';
    }

    /**
     * The three limits that decide what a background pass can do here,
     * printed raw. `disable_functions` in particular is quoted in full
     * rather than summarised: which functions a host disables is the
     * single most useful line when a mechanism silently does nothing, and
     * the interesting entry is always the one nobody thought to check for.
     *
     * @param array<int, string> $lines
     */
    private function limits(array &$lines): void
    {
        $openBasedir = trim((string) ini_get('open_basedir'));
        $disabled = trim((string) ini_get('disable_functions'));

        $lines[] = '## Limites';
        $lines[] = 'max_execution_time : ' . (string) ini_get('max_execution_time') . ' s';
        $lines[] = 'memory_limit : ' . (string) ini_get('memory_limit');
        $lines[] = 'open_basedir : ' . ($openBasedir === '' ? '(aucun)' : $openBasedir);
        $lines[] = 'disable_functions : ' . ($disabled === '' ? '(aucune)' : $disabled);
        $lines[] = '';
    }

    /**
     * Can this site reach itself over HTTP? Measured, per target, because
     * a host on which the site cannot reach its own public name is a host
     * on which a whole class of things quietly does not work — and none
     * of them ever reports the reason.
     *
     * Both targets are tried and both are reported, never just the first
     * that works: loopback and the public name fail for different reasons
     * — one is firewalled off from PHP, the other goes out through a proxy
     * or a WAF — and knowing which of the two answers is what turns a
     * report into a fix.
     *
     * The destination comes from the configured `base_url` and nothing
     * else. Never `HTTP_HOST`: it is attacker-supplied, this host sits
     * behind a proxy with `SERVER_ADDR = 127.0.0.1`, and a collector that
     * connected wherever a header pointed would be an SSRF triggerable by
     * anyone who can make a superadmin generate a support package.
     *
     * @param array<int, string> $lines
     */
    private function loopback(array &$lines, SupportCollectorContext $context): void
    {
        $lines[] = '## Boucle HTTP vers soi-même';
        $lines[] = '# Cible demandée : ' . self::PROBE_PATH . ' (publique, sans effet de bord).';

        $configured = trim((string) ($context->settings()->get('base_url') ?? ''));
        if ($configured === '') {
            $lines[] = 'base_url vide : aucune cible à tester, et aucun saut ne peut partir.';
            $lines[] = '';

            return;
        }

        $parts = parse_url($configured);
        if (!is_array($parts) || !isset($parts['host'])) {
            $lines[] = 'base_url illisible (' . $configured . ') : aucune cible testable.';
            $lines[] = '';

            return;
        }

        $scheme = ($parts['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $prefix = rtrim((string) ($parts['path'] ?? ''), '/');
        $transport = $scheme === 'https' ? 'ssl' : 'tcp';

        foreach ([
            'loopback (127.0.0.1 avec en-tête Host)' => $transport . '://127.0.0.1:' . $port,
            'nom public' => $transport . '://' . $host . ':' . $port,
        ] as $label => $target) {
            $lines[] = $label . ' → ' . $target;
            $lines[] = '  ' . $this->probe($target, $host, $prefix . self::PROBE_PATH);
        }

        $lines[] = '';
    }

    /**
     * One connect, one request, one status line. Never more: a support
     * collector that reads a whole response body is a support collector
     * that can be made to wait.
     */
    private function probe(string $target, string $host, string $path): string
    {
        // Same certificate rule as a real hop: TLS to 127.0.0.1 validates
        // against the site's own name via SNI, and verification is never
        // switched off.
        $streamContext = stream_context_create([
            'ssl' => [
                'peer_name' => $host,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $streamContext
        );

        if ($socket === false) {
            return 'ÉCHEC de connexion : ' . ($errstr !== '' ? $errstr : 'erreur ' . $errno);
        }

        // An explicit user-agent, for the same reason a hop carries one:
        // a request without one is a documented cause of WAF refusal on
        // other hosts, and a refusal here would be read as "the loop does
        // not work" rather than "the loop was blocked".
        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . "User-Agent: ScoutMagic-Support/1.0 (+background-execution probe)\r\n"
            . "Connection: close\r\n\r\n";

        stream_set_timeout($socket, (int) self::CONNECT_TIMEOUT_SECONDS);
        $written = @fwrite($socket, $request);
        if ($written === false || $written <= 0) {
            @fclose($socket);

            return 'connecté, mais écriture impossible sur la socket';
        }

        $statusLine = @fgets($socket, 2048);
        $info = stream_get_meta_data($socket);
        @fclose($socket);

        if ($info['timed_out']) {
            return 'connecté et requête écrite, mais aucune réponse avant expiration ('
                . self::CONNECT_TIMEOUT_SECONDS . ' s)';
        }

        if (!is_string($statusLine) || trim($statusLine) === '') {
            return 'connecté et requête écrite, mais réponse vide';
        }

        return 'OK — ' . trim($statusLine);
    }

    /**
     * The one setting this file has left to report, and the only one it
     * ever really needed: where this installation believes it lives.
     *
     * The scheduler's own settings used to be printed here — the slice
     * budget, the hop ceiling, the current hop counter and whether the
     * continuation secret existed. They are gone with the mechanism they
     * steered. What is left is `base_url`, which is what the loopback test
     * above aims at, and which is never derived from `HTTP_HOST`.
     *
     * @param array<int, string> $lines
     */
    private function settings(array &$lines, SupportCollectorContext $context): void
    {
        $baseUrl = trim((string) ($context->settings()->get('base_url') ?? ''));

        $lines[] = '## Adresse de référence';
        $lines[] = 'base_url : ' . ($baseUrl === '' ? '(vide — le site ne sait pas comment il s\'appelle)' : $baseUrl);
        $lines[] = '';
        $lines[] = 'C\'est la SEULE source de l\'adresse du site, ici comme ailleurs : jamais HTTP_HOST,';
        $lines[] = 'qui est fourni par l\'appelant. Elle est figée à l\'installation.';
        $lines[] = '';
    }
}
