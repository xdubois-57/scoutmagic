<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Scheduler\SchedulerContinuation;
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
 * `disable_functions`. Those two facts decided the whole design of the
 * scheduler's continuation, and neither of them was visible in a support
 * package. Now that chained continuation is in production, "pourquoi la
 * file n'avance pas chez cette unité" is the first question support will
 * be asked, and this file is the answer to it.
 *
 * Its companion is `cron-cadence.txt`, which answers what has actually
 * been happening; this one answers what is possible here. Both targets of
 * the loop are tried and both are reported, never just the first that
 * works: loopback and the public name fail for different reasons, and
 * knowing which of the two answers is what turns a report into a fix.
 *
 * **The continuation secret is reported as present or absent and never
 * printed.** It authenticates a request to this site's own scheduler
 * endpoint, and this archive leaves the installation — it is transmitted
 * to support over an API. A token in a support package is a token in
 * every hand and every store that package passes through
 * (`Core\Security\CapabilityToken`, contract point 2). `base_url` is printed, because it is the site's public address and
 * the archive already carries it in a dozen places.
 */
class BackgroundExecutionCollector implements SupportCollectorInterface
{
    /**
     * A support package must never hang on a socket. Two seconds is the
     * same budget SchedulerContinuation gives a real hop.
     */
    private const CONNECT_TIMEOUT_SECONDS = 2.0;

    /**
     * The loopback test asks for this rather than the continuation
     * endpoint: it is public, cheap, and has NO side effect. Probing the
     * continuation endpoint would either run scheduler work or — with a
     * wrong secret — write a `security` journal entry every time somebody
     * generates a support package.
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
            . ' — sans elle, l\'ordonnanceur déclenché par une visite fait attendre ce visiteur';
        $lines[] = 'stream_socket_client : ' . (function_exists('stream_socket_client') ? 'oui' : 'non')
            . ' — sans elle, aucun saut de continuation ne peut être émis';
        $lines[] = 'Exécution shell vérifiée : ' . ($shell['works'] ? 'oui' : 'NON')
            . ' (' . ($shell['function'] ?? 'aucune fonction') . ' — ' . $shell['detail'] . ')';
        $lines[] = 'SAPI : ' . PHP_SAPI;
        $lines[] = '';
        $lines[] = 'Note : le spawn d\'un processus CLI détaché n\'est délibérément pas tenté, ici comme';
        $lines[] = 'ailleurs — il est mesuré non fonctionnel sur l\'hébergement de référence et resterait';
        $lines[] = 'exposé au ramassage des processus orphelins. Ce que ScoutMagic utilise, c\'est la';
        $lines[] = 'boucle HTTP testée plus bas.';
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
     * that loop is the engine of the whole continuation mechanism: without
     * it a queue only advances when somebody visits, and "pourquoi la file
     * n'avance pas chez cette unité" is the first support question a
     * chained scheduler produces.
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
     * @param array<int, string> $lines
     */
    private function settings(array &$lines, SupportCollectorContext $context): void
    {
        $settings = $context->settings();

        $baseUrl = trim((string) ($settings->get('base_url') ?? ''));
        $maxHops = (string) ($settings->get(SchedulerContinuation::MAX_HOPS_SETTING) ?? '(non défini)');

        $lines[] = '## Réglages de l\'ordonnanceur';
        $lines[] = 'base_url : ' . ($baseUrl === '' ? '(vide — aucun saut ne peut partir)' : $baseUrl);
        $lines[] = 'Budget d\'une tranche (' . SchedulerContinuation::BUDGET_SETTING . ') : '
            . (string) ($settings->get(SchedulerContinuation::BUDGET_SETTING) ?? '(non défini)') . ' s';
        $lines[] = 'Plafond de sauts (' . SchedulerContinuation::MAX_HOPS_SETTING . ') : ' . $maxHops
            . ($maxHops === '0' ? ' — la continuation est DÉSACTIVÉE sur cette installation' : '');
        $lines[] = 'Compteur de sauts courant (' . SchedulerContinuation::HOPS_SETTING . ') : '
            . (string) ($settings->get(SchedulerContinuation::HOPS_SETTING) ?? '(non défini)');

        // Presence only, never the value: this authenticates a request to
        // the site's own scheduler endpoint, and this archive leaves the
        // installation.
        $lines[] = 'Secret de continuation : ' . ($this->hasContinuationSecret($context) ? 'présent' : 'ABSENT — aucun saut ne peut être authentifié');
        $lines[] = '';
    }

    private function hasContinuationSecret(SupportCollectorContext $context): bool
    {
        try {
            $secrets = (new \Core\Security\SecretManager(
                $context->storagePath() . '/keys/master.key',
                $context->storagePath() . '/config/secrets.enc'
            ))->readSecrets();

            return trim((string) ($secrets['scheduler_continuation_secret'] ?? '')) !== '';
        } catch (\Throwable) {
            return false;
        }
    }
}
