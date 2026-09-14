<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Mail\MailTransportInterface;
use Core\Mail\PhpMailerTransport;
use Core\Security\SecretManager;
use PDO;

/**
 * The one construction of the provider chain, for both entry points
 * (ARCHITECTURE.md §8.106).
 *
 * **This exists because two composition roots wiring the same thing by
 * hand is how this project has already broken production, twice.**
 * `create_backup` was registered in `public/index.php` and missing from
 * `public/cron.php`, so every background backup under a real crontab
 * failed silently; `NotificationService` was later built with role
 * resolution on one side and without it on the other (§8.17). The
 * scheduler answered that by moving its wiring into one shared file, and
 * the chain has exactly the same shape: `index.php` needs it for a
 * visitor's mail, `cron.php` needs it for the half of this site's mail
 * that leaves from a scheduled task — the publipostage included, which is
 * the lane the whole mechanism exists to pace and to fall back.
 *
 * A chain built differently on the two paths would mean a mailing obeying
 * quotas under one trigger and ignoring them under the other, which is
 * precisely the kind of divergence nobody notices until a relay is spent.
 *
 * The one thing the two roots legitimately differ on is passed in: the
 * **delivery transport** underneath, which is `Modules\TestTools`'
 * `CaptureTransport` on an installation whose sandbox is armed and
 * `PhpMailerTransport` everywhere else.
 */
final class MailTransportFactory
{
    /**
     * Build the chain, and the directory it reads.
     *
     * Returned as a pair because the caller needs both: the chain goes to
     * `MailServiceFactory`, and the directory goes on to the Courrier
     * sortant screen and to the scheduler's `BulkCadence`. Building a
     * second directory for those would mean reading `secrets.enc` twice
     * and memoising nothing.
     *
     * @param array<string, mixed> $secrets Already decrypted by the caller.
     * @param MailTransportInterface|null $delivery The transport underneath
     *        — null keeps the ordinary `PhpMailerTransport`.
     * @return array{chain: MailTransportChain, directory: MailProviderDirectory,
     *     connections: ProviderConnections, delivery: MailTransportInterface}
     */
    public static function build(
        PDO $pdo,
        array $secrets,
        SettingService $settings,
        ?MailTransportInterface $delivery = null,
        ?JournalService $journal = null,
        ?SecretManager $secretManager = null
    ): array {
        $connections = new ProviderConnections($secrets, $secretManager);
        // Resolved once and handed back, because a second caller needing
        // the transport UNDERNEATH the chain — the manual probe, which
        // pins one relay and therefore cannot go through the chain at all
        // — must get the one this installation actually uses. Spelling
        // `?? new PhpMailerTransport()` a second time in a composition
        // root is how a site configured to capture its mail would start
        // really sending it from one screen (roadmap IT-04).
        $delivery ??= new PhpMailerTransport();
        $directory = new MailProviderDirectory(new MailProviderRepository($pdo), $connections, $settings);
        $chains = new LaneChainRepository($pdo);
        $counters = new SendCounterRepository($pdo);

        return [
            'chain' => new MailTransportChain(
                $directory,
                $chains,
                $counters,
                new TransportConfigurator($connections),
                $delivery,
                $journal,
                // Both are built HERE and not left to the callers, which is
                // the whole point of this factory: they are optional on the
                // constructor so a test can leave them out, and a
                // composition root that forgot them would give an
                // installation a circuit breaker that never records a
                // failure and a reserve the screen displays but nothing
                // enforces. Tests\Core\Mail\Transport\MailTransportFactoryTest
                // pins that they arrive.
                new ProviderHealthRepository($pdo),
                new MailReserve($counters, $chains)
            ),
            'directory' => $directory,
            'connections' => $connections,
            'delivery' => $delivery,
        ];
    }
}
