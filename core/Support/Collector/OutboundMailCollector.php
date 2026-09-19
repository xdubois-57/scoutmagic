<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Config\SettingService;
use Core\Mail\DnsCheckMemory;
use Core\Mail\Feedback\ReturnPathVerifier;
use Core\Mail\Feedback\ReturnProbeRepository;
use Core\Mail\Feedback\ReturnState;
use Core\Mail\MailIdentity;
use Core\Mail\Probe\MailProbeRepository;
use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailReserve;
use Core\Mail\Transport\ProviderHealthRepository;
use Core\Support\SupportCollectorContext;
use Modules\InboundMail\Api\InboundMailInterface;
use Core\Support\SupportCollectorInterface;

/**
 * `outbound-mail.txt` — how this installation's mail actually leaves, and
 * what the chains have been doing (ARCHITECTURE.md §8.106, §8.48).
 *
 * The question it answers is the one nobody can answer from a screenshot:
 * "mail stopped going out" is the same sentence whether a relay
 * is refusing, a quota is spent, or a lane was emptied on the
 * configuration page three weeks ago. The chains, the counters and the
 * order are what tell those apart, and none of them was in the archive.
 *
 * **What it never carries**, and this is the part to read before adding a
 * line to it: no provider credential, no password, no token, no message
 * body, no recipient address. The package leaves the installation and
 * goes to a third party. What is here is counters, provider names and
 * host names — a host name is a server, an address is a person.
 *
 * `MailProvider` helps rather than hinders: it has no password property
 * at all, so this collector cannot print one by accident. Only
 * `Core\Mail\Transport\TransportConfigurator` reads one.
 *
 * **The one line that needed thought is the circuit breaker's reason.**
 * It is an SMTP server's own sentence, and a server refusing a message
 * says whose — « 550 5.1.1 <...>: Recipient address rejected ». It is
 * safe to print here only because it was redacted of addresses before it
 * was ever stored ({@see \Core\Mail\MailErrorRedaction}), and it goes
 * through {@see SupportCollectorContext::redact()} on the way out as
 * well, which is what turns « safe » into « safe twice ».
 */
class OutboundMailCollector implements SupportCollectorInterface
{
    /** Deep enough to see a pattern, short enough to stay readable. */
    private const COUNTER_DAYS = 30;

    /**
     * Everything after the chains is optional, and null means the section
     * is left out rather than printed empty: a support package collected
     * on an installation whose schema predates one of these tables is
     * still worth having.
     */
    /**
     * How many probes the archive carries.
     *
     * A handful, because the comparison the history exists for is
     * between two or three roads — and an archive that pasted forty rows
     * of it would bury the sections around it.
     */
    private const PROBES_IN_ARCHIVE = 10;

    public function __construct(
        private MailProviderDirectory $directory,
        private LaneChainRepository $chains,
        private ?ProviderHealthRepository $health = null,
        private ?MailReserve $reserve = null,
        private ?DeferredMailRepository $deferred = null,
        private ?DeferredMailQueue $queue = null,
        private ?SettingService $settings = null,
        private ?ReturnProbeRepository $returns = null,
        /**
         * Read-only, and the contrast with `$returns` is the point: the
         * rows say what a probe found, this says whether a probe could
         * have been sent at all. Neither can send one — a support package
         * must not be able to put mail on the wire while it is being
         * assembled.
         */
        private ?InboundMailInterface $inboundMail = null,
        /**
         * The probe history, read-only — like `$returns`, and for the
         * same reason: a support package must never be able to put mail
         * on the wire while it is being assembled, so what it gets is the
         * table and not the sender.
         */
        private ?MailProbeRepository $probes = null,
        /** The bounce state (roadmap IT-05). */
        private ?\Core\Mail\Feedback\Bounce\BounceStateRepository $bounces = null
    ) {
    }

    public function name(): string
    {
        return 'outbound_mail';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $providers = $this->directory->all();

        $lines = [];
        $lines[] = 'COURRIER SORTANT';
        $lines[] = '';
        $lines[] = 'Aucun identifiant, aucun mot de passe, aucun destinataire ne figure dans ce fichier.';
        $lines[] = '';

        $lines[] = '── Fournisseurs ────────────────────────────────────────────';
        foreach ($providers as $provider) {
            $lines[] = sprintf(
                '%s%s',
                $provider->name,
                $provider->isLocal() ? ' (envoi local, entrée permanente)' : ''
            );
            $lines[] = '  hôte        : ' . ($provider->isLocal() ? '(aucun relais)' : ($provider->host ?: '(vide)'));
            $lines[] = '  port        : ' . ($provider->isLocal() ? '-' : (string) $provider->port);
            $lines[] = '  quota/jour  : '
                . ($provider->dailyQuota === null ? '(aucun connu)' : (string) $provider->dailyQuota);
            $lines[] = sprintf(
                '  cadence     : %d message(s) toutes les %d minute(s) — voie « Masse » uniquement',
                $provider->batchSize,
                $provider->batchIntervalMinutes
            );
            $lines[] = '  identifiant : ' . ($provider->username !== '' ? 'configuré' : 'aucun');
            $lines[] = '  voies       : ' . ($this->lanesOf($provider->id) ?: '(aucune)');
            $lines[] = '';
        }

        $lines[] = '── Chaînes, dans leur ordre ────────────────────────────────';
        foreach (MailLane::ordered() as $lane) {
            $lines[] = $lane->label() . ' — ' . $lane->detail();
            $position = 0;
            foreach ($this->chains->forLane($lane) as $entry) {
                $provider = $providers[$entry->providerId] ?? null;
                $position++;
                $lines[] = sprintf(
                    '  %d. %s [%s]',
                    $position,
                    $provider === null
                    ? 'fournisseur #' . $entry->providerId . ' (introuvable)'
                    : $provider->name,
                    $entry->enabled ? 'actif' : 'désactivé'
                );
            }
            if ($position === 0) {
                $lines[] = '  (vide — aucun message ne peut partir sur cette voie)';
            }
            $lines[] = '';
        }

        foreach ($this->circuitLines($providers, $context) as $line) {
            $lines[] = $line;
        }

        foreach ($this->reserveLines($providers) as $line) {
            $lines[] = $line;
        }

        foreach ($this->queueLines() as $line) {
            $lines[] = $line;
        }

        foreach ($this->probeLines() as $line) {
            $lines[] = $line;
        }

        foreach ($this->bounceLines() as $line) {
            $lines[] = $line;
        }

        foreach ($this->authenticationLines() as $line) {
            $lines[] = $line;
        }

        $lines[] = sprintf('── Compteurs, %d derniers jours ────────────────────────────', self::COUNTER_DAYS);
        $lines[] = 'date        fournisseur                         voie             envoyés';
        foreach ($this->counterRows($context, $providers) as $row) {
            $lines[] = $row;
        }

        $context->addFileFromContent('outbound-mail.txt', implode("\n", $lines) . "\n");
    }

    /**
     * What the breaker has been doing (D15).
     *
     * The interesting column is `ouvertures` rather than the current
     * state: a provider closed right now that has opened eleven times
     * this month is a relay on its way out, and that is exactly the
     * pattern nobody can see from a screenshot taken between two
     * outages.
     *
     * @param array<int, MailProvider> $providers
     * @return array<int, string>
     */
    private function circuitLines(array $providers, SupportCollectorContext $context): array
    {
        if ($this->health === null) {
            return [];
        }

        try {
            $records = $this->health->all();
        } catch (\Throwable) {
            return [];
        }

        $lines = ['── Coupe-circuit ───────────────────────────────────────────'];

        if ($records === []) {
            $lines[] = '(aucun fournisseur n\'a jamais échoué)';
            $lines[] = '';

            return $lines;
        }

        $lines[] = 'fournisseur                         état     échecs  ouvertures  dernière ouverture';
        foreach ($records as $record) {
            $lines[] = sprintf(
                '%-35s %-8s %-7d %-11d %s',
                $providers[$record->providerId]->name ?? ('#' . $record->providerId),
                $record->isOpen() ? 'ouvert' : 'fermé',
                $record->consecutiveFailures,
                $record->openCount,
                $record->openedAt ?? '-'
            );
            if ($record->lastReason !== '') {
                $lines[] = '    dernier échec : ' . $context->redact($record->lastReason, 120);
            }
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * What each provider is holding back for the authentication lane, and
     * the month that produced the number (D14).
     *
     * Printed with its provenance because the reserve is the one figure
     * on this page that looks arbitrary: « 74 » means nothing, « votre
     * pointe hors publipostage des 30 derniers jours (54), plus une marge
     * de 20 » can be checked against the counters two sections below.
     *
     * @param array<int, MailProvider> $providers
     * @return array<int, string>
     */
    private function reserveLines(array $providers): array
    {
        if ($this->reserve === null) {
            return [];
        }

        $lines = ['── Réserve pour les liens de connexion ─────────────────────'];

        foreach ($providers as $provider) {
            try {
                $reserve = $this->reserve->forProvider($provider);
            } catch (\Throwable) {
                continue;
            }

            $lines[] = $provider->name;
            $lines[] = '  ' . $reserve->provenance();
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * The domain's own authentication, and whether what comes back
     * arrives (roadmap IT-03).
     *
     * **Domains, states and dates — never an address.** A domain name is
     * a server and is exactly what a remote diagnosis needs; an address
     * is a person, and this file goes to a third party. So the return
     * verification is reported per ROLE (« Expédition », « Réponses »)
     * with its state and its date, and the address that role holds stays
     * out of the archive entirely.
     *
     * The DNS verdicts are the remembered ones, with the date they were
     * taken: a support package must not make a DNS query of its own, and
     * a reading that says how old it is beats a reading that pretends to
     * be current.
     *
     * @return array<int, string>
     */
    private function authenticationLines(): array
    {
        if ($this->settings === null) {
            return [];
        }

        $identity = MailIdentity::fromSettings($this->settings);

        $lines = ['── Authentification du domaine ─────────────────────────────'];
        $lines[] = 'domaine d\'enveloppe (SPF) : ' . ($identity->spfDomain() ?: '(aucune adresse d\'expédition)');
        $lines[] = 'domaine de signature (DKIM) : ' . ($identity->dkimDomain() ?: '-');
        $selector = (string) ($this->settings->get('dkim_selector') ?? '');
        $lines[] = 'sélecteur DKIM : ' . ($selector ?: '(vide)');
        $lines[] = 'adresse de réponse distincte : ' . ($identity->configuredReplyAddress() !== '' ? 'oui' : 'non');
        $lines[] = 'rapports DMARC demandés : '
            . ($identity->configuredDmarcReportAddress() !== '' ? 'oui' : 'non');

        $verdicts = DnsCheckMemory::read($this->settings);
        if ($verdicts === null) {
            $lines[] = 'vérification DNS : jamais lancée depuis la page Authentification';
        } else {
            // Said in as many words, and not left to whoever reads the
            // archive to compare this domain against the one printed
            // fifteen lines up. A reading taken on the previous domain
            // still lists three verdicts, and they answer a question
            // nobody is asking any more.
            $applies = $verdicts->describes($identity, $selector);
            $lines[] = 'vérification DNS du ' . $verdicts->takenAt->format('Y-m-d H:i')
                . ' sur ' . ($verdicts->spfDomain ?: '(inconnu)')
                . ($applies ? '' : ' — PÉRIMÉE : les adresses ont changé depuis');
            $lines[] = '  SPF   : ' . DnsCheckMemory::label($verdicts->state(DnsCheckMemory::SPF));
            $lines[] = '  DKIM  : ' . DnsCheckMemory::label($verdicts->state(DnsCheckMemory::DKIM));
            $lines[] = '  DMARC : ' . DnsCheckMemory::label($verdicts->state(DnsCheckMemory::DMARC));
        }

        $lines[] = '';

        if ($this->returns === null) {
            return $lines;
        }

        $lines[] = '── Vérification des retours ────────────────────────────────';

        // « Jamais vérifié » and « vérification impossible » send a reader
        // to opposite places — one is a button nobody pressed, the other
        // is a module that is off — so the archive has to tell them apart
        // exactly as the screen does. The rule itself lives on
        // ReturnPathVerifier, in one copy.
        if (!ReturnPathVerifier::possibleWith($this->inboundMail)) {
            $lines[] = ReturnState::IMPOSSIBLE->label()
                . ' : aucune boîte du courrier entrant ne relève pour « Courrier sortant ».';
            $lines[] = '';

            return $lines;
        }

        $now = new \DateTimeImmutable();
        $roles = [
            'Expédition (rebonds)' => $identity->bounceAddress(),
            'Réponses' => $identity->replyAddress(),
        ];

        foreach ($roles as $label => $address) {
            if ($address === '') {
                $lines[] = sprintf('%-22s : (aucune adresse)', $label);
                continue;
            }

            try {
                $probe = $this->returns->findByAddress($address);
            } catch (\Throwable) {
                continue;
            }

            $lines[] = sprintf(
                '%-22s : %s%s%s',
                $label,
                ReturnState::forProbe($probe, $now)->label(),
                $probe !== null ? ', envoyé le ' . $probe->sentAt->format('Y-m-d H:i') : '',
                $probe?->receivedAt !== null ? ', revenu le ' . $probe->receivedAt->format('Y-m-d H:i') : ''
            );
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * The manual probes, by road and by verdict — never by destination
     * (roadmap IT-04).
     *
     * **The address stays out, and the screen shows it.** Those are not
     * in tension: the page is read by the person who typed the address in
     * one minute ago and needs the comparison « même destinataire, deux
     * relais, deux verdicts »; this file goes to a third party and is
     * kept for a long time, and the question it has to answer — « par
     * quels chemins cette unité a-t-elle testé, et qu'est-ce que ça a
     * donné » — needs the road and the verdict, not who was written to.
     *
     * A total as well as the last few, because « deux sondes en six
     * mois » and « quarante » say different things about how much the
     * lines below are worth.
     *
     * @return array<int, string>
     */
    /**
     * How many addresses have stopped being written to, and at which
     * providers — never WHICH addresses (roadmap IT-05).
     *
     * **The domain and the count, and that is the whole section.** This
     * file goes to a third party and is kept far longer than the screen
     * it mirrors, so an address has no business in it — while « douze
     * adresses suspendues, onze chez le même fournisseur » is exactly the
     * shape somebody helping needs, and says nothing about any one
     * family.
     *
     * @return list<string>
     */
    private function bounceLines(): array
    {
        if ($this->bounces === null) {
            return [];
        }

        try {
            $blocked = $this->bounces->blocked();
            $total = $this->bounces->countBlocked();
        } catch (\Throwable) {
            return [];
        }

        $lines = ['── Adresses suspendues sur rebond ──────────────────────────'];

        if ($blocked === []) {
            $lines[] = 'aucune adresse suspendue';
            $lines[] = '';

            return $lines;
        }

        $byDomain = [];
        foreach ($blocked as $state) {
            $at = strrpos($state->email, '@');
            $domain = $at === false ? '(inconnu)' : substr($state->email, $at + 1);
            $byDomain[$domain] = ($byDomain[$domain] ?? 0) + 1;
        }
        arsort($byDomain);

        $lines[] = sprintf('%d adresse%s suspendue%s, par fournisseur :', $total, $total > 1 ? 's' : '', $total > 1 ? 's' : '');
        foreach ($byDomain as $domain => $count) {
            $lines[] = sprintf('%-40s  %d', mb_substr((string) $domain, 0, 40), $count);
        }

        $lines[] = '';

        return $lines;
    }

    private function probeLines(): array
    {
        if ($this->probes === null) {
            return [];
        }

        try {
            $recent = $this->probes->recent(self::PROBES_IN_ARCHIVE);
            $total = $this->probes->count();
        } catch (\Throwable) {
            return [];
        }

        $lines = ['── Sondes de délivrabilité ─────────────────────────────────'];

        if ($recent === []) {
            $lines[] = 'aucune sonde envoyée';
            $lines[] = '';

            return $lines;
        }

        $lines[] = sprintf('%d sonde%s au total, les %d dernières :', $total, $total > 1 ? 's' : '', count($recent));
        $lines[] = sprintf('%-17s  %-24s  %-16s  %s', 'date', 'fournisseur', 'voie', 'verdict');

        foreach ($recent as $probe) {
            $lines[] = sprintf(
                '%-17s  %-24s  %-16s  %s',
                $probe->sentAt->format('Y-m-d H:i'),
                mb_substr($probe->providerName, 0, 24),
                $probe->lane->label(),
                $probe->verdict?->label() ?? 'en attente'
            );
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * The queue, in counts (D9, D17).
     *
     * Counts and ages only — never a subject, never a recipient. What
     * this section answers is « a-t-elle cessé de se vider », and a
     * depth per lane plus an age spread answers it without a single
     * message being decrypted.
     *
     * @return array<int, string>
     */
    private function queueLines(): array
    {
        if ($this->deferred === null) {
            return [];
        }

        try {
            $pending = $this->deferred->pendingCountByLane();
            $buckets = $this->queue?->abandonedByAge();
        } catch (\Throwable) {
            return [];
        }

        $lines = ['── Messages différés ───────────────────────────────────────'];

        foreach (MailLane::ordered() as $lane) {
            $waiting = $pending[$lane->value] ?? 0;
            $lines[] = sprintf(
                '  %-16s %d en attente%s',
                $lane->label(),
                $waiting,
                $lane === MailLane::Authentication ? ' (cette voie ne diffère jamais)' : ''
            );
        }

        if ($buckets !== null) {
            $lines[] = sprintf(
                // Disjoint bands, named as such: « de moins de 24 h » for
                // a count that leaves out the last six hours would be read
                // as a total, and the relaunch window of the same name IS
                // a total.
                '  abandonnés     : %d — dont %d de moins de 6 h, %d de 6 à 24 h, '
                    . '%d de 1 à 7 jours, %d au-delà',
                $buckets['total'],
                $buckets['recent'],
                $buckets['day'],
                $buckets['week'],
                $buckets['older']
            );
        }

        $lines[] = '';

        return $lines;
    }

    private function lanesOf(int $providerId): string
    {
        $labels = [];
        foreach (MailLane::ordered() as $lane) {
            foreach ($this->chains->forLane($lane) as $entry) {
                if ($entry->providerId === $providerId && $entry->enabled) {
                    $labels[] = $lane->label();
                }
            }
        }

        return implode(', ', $labels);
    }

    /**
     * @param array<int, MailProvider> $providers
     * @return array<int, string>
     */
    private function counterRows(SupportCollectorContext $context, array $providers): array
    {
        // `COUNTER_DAYS - 1`, because the filter below is `>=` and today
        // counts: thirty days back from today is thirty-one dates, and the
        // heading above this table says thirty.
        $from = (new \DateTimeImmutable('-' . (self::COUNTER_DAYS - 1) . ' days'))->format('Y-m-d');

        $statement = $context->pdo()->prepare(
            'SELECT count_date, provider_id, lane, sent_count
             FROM mail_send_counters WHERE count_date >= ?
             ORDER BY count_date DESC, provider_id, lane'
        );
        $statement->execute([$from]);

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $providerId = (int) $row['provider_id'];
            $rows[] = sprintf(
                '%-11s %-35s %-16s %d',
                (string) $row['count_date'],
                $providers[$providerId]->name ?? ('#' . $providerId),
                (string) $row['lane'],
                (int) $row['sent_count']
            );
        }

        return $rows === [] ? ['(aucun envoi enregistré sur la période)'] : $rows;
    }
}
