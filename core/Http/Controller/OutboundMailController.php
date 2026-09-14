<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Journal\JournalService;
use Core\Http\Response;
use Core\Mail\DkimManager;
use Core\Mail\DnsCheckMemory;
use Core\Mail\Feedback\ReturnPathVerifier;
use Core\Mail\Feedback\ReturnState;
use Core\Mail\DnsVerifier;
use Core\Mail\MailIdentity;
use Core\Mail\Probe\MailProbeException;
use Core\Mail\Probe\MailProbeNotRecordedException;
use Core\Mail\Probe\MailProbeRepository;
use Core\Mail\Probe\MailProbeSender;
use Core\Mail\Probe\MailProbeVerdict;
use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailReserve;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\ProviderHealthRepository;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\TransportException;
use Core\Mail\Transport\TransportService;
use Core\Security\AuthSession;
use Twig\Environment;

/**
 * Configuration > Courrier sortant (ARCHITECTURE.md §8.106).
 *
 * The pendant of `/config/courrier-entrant`, which `inbound_mail`
 * exposes — with one asymmetry that is assumed rather than accidental
 * (D1): receiving mail is optional and lives in a module, sending is not
 * and lives in the core. The chain that carries the sign-in links cannot
 * depend on a module somebody may disable, or signing in to the site
 * becomes a thing an administrator can switch off by accident.
 *
 * Two sub-pages in this iteration — Fournisseurs and Acheminement — on
 * the shared `partials/page_picker.html.twig` rail. They arrive together
 * with the transport itself rather than later, for a simple reason: a
 * chain nobody can configure is a chain nobody can test.
 */
class OutboundMailController extends AbstractController
{
    public const PAGE_URL = '/config/courrier-sortant';
    public const ROUTING_URL = '/config/courrier-sortant/acheminement';
    public const RELAUNCH_URL = '/config/courrier-sortant/relance';
    public const AUTHENTICATION_URL = '/config/courrier-sortant/authentification';
    public const PROVIDERS_URL = '/config/courrier-sortant/fournisseurs';

    public const PROBE_URL = '/config/courrier-sortant/sonde';
    public const PROBE_SEND_URL = '/config/courrier-sortant/sonde/envoi';
    public const PROBE_VERDICT_URL = '/config/courrier-sortant/sonde/verdict';

    /** Said the same way wherever the probe cannot run at all. */
    private const PROBE_UNAVAILABLE = 'La sonde n’est pas disponible sur cette installation.';

    public const RETURN_CHECK_URL = '/config/courrier-sortant/authentification/verification';
    public const DNS_CHECK_URL = '/config/courrier-sortant/authentification/dns';

    public function __construct(
        protected Environment $twig,
        private MailProviderDirectory $directory,
        private LaneChainRepository $chains,
        private SendCounterRepository $counters,
        private TransportService $transport,
        private SettingService $settings,
        private MailReserve $reserve,
        private ProviderHealthRepository $health,
        private DeferredMailRepository $deferred,
        private DeferredMailQueue $queue,
        private DkimManager $dkim,
        private DnsVerifier $dns,
        private ReturnPathVerifier $returns,
        private JournalService $journal,
        /**
         * The manual probe (roadmap IT-04).
         *
         * Nullable for the one reason the others are not: it needs a
         * Twig environment to render a message with, and the composition
         * roots that build this controller without one — a narrow test,
         * a boot path with no view layer — would otherwise be unable to
         * build it at all. With null the sub-page says the probe is
         * unavailable rather than erroring, which is the same posture
         * `$returns` takes towards a missing `inbound_mail`.
         */
        private ?MailProbeSender $probes = null,
        private ?MailProbeRepository $probeHistory = null
    ) {
    }


    /**
     * GET /config/courrier-sortant — the dashboard (roadmap IT-03).
     *
     * **Three lines, and a sentence saying the rest is optional.** That
     * separation is the point of the screen: a volunteer opening it needs
     * to know whether mail works, and a page that lists twelve equally
     * weighted settings answers that question for nobody. The advanced
     * options are listed underneath with their state and are never
     * hidden — hiding them would make them unfindable, and levelling them
     * with the three would drown the message.
     *
     * @param array<string, string> $params
     */
    public function dashboard(Request $request, array $params): Response
    {
        return $this->render('config/outbound_mail/dashboard.html.twig', [
            'essentials' => [$this->domainLine(), $this->providerLine(), $this->returnLine()],
            'advanced' => $this->advancedLines(),
            'page_url' => self::PROVIDERS_URL,
            'authentication_url' => self::AUTHENTICATION_URL,
            'providers_url' => self::PROVIDERS_URL,
            'routing_url' => self::ROUTING_URL,
        ]);
    }

    /**
     * « Authentification du domaine » — SPF, DKIM and DMARC as of the
     * last live lookup.
     *
     * It reports a remembered reading rather than taking a fresh one, and
     * says when it was taken. A `dns_get_record()` on every load of this
     * page would put a resolver on the critical path of the one page
     * somebody opens when mail is already broken.
     *
     * @return array{key: string, label: string, state: string, detail: string,
     *     action_label: string, action_url: string}
     */
    private function domainLine(): array
    {
        $identity = MailIdentity::fromSettings($this->settings);
        $line = [
            'key' => 'domain',
            'label' => 'Authentification du domaine',
            'action_label' => 'Ouvrir Authentification',
            'action_url' => self::AUTHENTICATION_URL,
        ];

        if ($identity->fromAddress === '') {
            return $line + [
                'state' => 'missing',
                'detail' => 'Aucune adresse d’expédition : le site ne peut envoyer aucun message.',
            ];
        }

        $stale = $this->staleDnsReading($identity);
        if ($stale !== null) {
            return $line + [
                'state' => 'unknown',
                'detail' => sprintf(
                    'Les adresses ont changé depuis la dernière vérification DNS, faite le %s sur %s : ce relevé '
                        . 'ne dit plus rien de la configuration actuelle.',
                    $stale->takenAt->format('d/m/Y à H:i'),
                    $stale->spfDomain === '' ? 'un autre domaine' : $stale->spfDomain
                ),
            ];
        }

        $last = $this->lastDnsReading($identity);
        if ($last === null) {
            return $line + [
                'state' => 'unknown',
                'detail' => 'Les enregistrements DNS n’ont jamais été vérifiés depuis cette page.',
            ];
        }

        $spf = $last->state(DnsCheckMemory::SPF);
        $dkim = $last->state(DnsCheckMemory::DKIM);
        $published = array_filter(
            [$spf, $dkim, $last->state(DnsCheckMemory::DMARC)],
            static fn(?bool $v) => $v === true
        );
        $missing = array_filter([$spf, $dkim], static fn(?bool $v) => $v === false);
        $when = $last->takenAt->format('d/m/Y à H:i');
        $domain = $last->spfDomain === '' ? 'votre domaine' : $last->spfDomain;

        if ($missing !== []) {
            return $line + [
                'state' => 'missing',
                'detail' => sprintf(
                    'Au %s, %s manquait dans la zone DNS de %s. Les messages partent quand même, et beaucoup '
                        . 'de destinataires les classeront en indésirables.',
                    $when,
                    $spf === false && $dkim === false ? 'ni le SPF ni le DKIM ne figurait'
                        : ($spf === false ? 'le SPF ne figurait pas' : 'le DKIM ne figurait pas'),
                    $domain
                ),
            ];
        }

        // A DKIM record that could not even be READ is not a record in
        // place. `state()` answers null for « no key pair yet », which is
        // deliberately not `false` — publishing nothing is not the same
        // as publishing something wrong — but it is not « ok » either:
        // without a key the site signs nothing at all, and this line
        // would have shown a green tick over it while the Authentification
        // sub-page showed « Clé DKIM requise » for the same reading.
        if ($last->record(DnsCheckMemory::DKIM)['key_missing']) {
            return $line + [
                'state' => 'missing',
                'detail' => sprintf(
                    'Aucune clé DKIM n’a été générée, donc les messages ne sont pas signés — beaucoup de '
                        . 'destinataires les classeront en indésirables. Le reste de la zone DNS de %s a été '
                        . 'relevé au %s.',
                    $domain,
                    $when
                ),
            ];
        }

        // An SPF record nobody could check against is not a record in
        // place either — the same shape of mistake as the DKIM one above,
        // and the one that used to turn this line green on a site with no
        // relay at all. {@see DnsVerifier::checkSpfForHosts()} explains
        // why the question is unanswerable rather than merely unasked.
        if ($last->record(DnsCheckMemory::SPF)['unverifiable']) {
            return $line + [
                'state' => 'unknown',
                'detail' => sprintf(
                    'La zone DNS de %s publie un SPF, mais %s — impossible donc de dire s’il autorise ce '
                        . 'site à envoyer. Relevé du %s.',
                    $domain,
                    $this->sendingHosts() === null
                        ? 'la liste des relais n’a pas pu être lue'
                        : 'aucun relais n’est actif, et les messages partent du serveur lui-même',
                    $when
                ),
            ];
        }

        return $line + [
            'state' => 'ok',
            'detail' => sprintf(
                '%d enregistrement%s en place au %s, sur %s.',
                count($published),
                count($published) > 1 ? 's' : '',
                $when,
                $domain
            ),
        ];
    }

    /**
     * « Un fournisseur » — is there a relay, and does it serve the three
     * lanes.
     *
     * The local send is always there and is never the answer to this
     * line: a site that hands everything to its own `mail()` is the site
     * whose sign-in links end up in a spam folder, which is the whole
     * reason the chain exists (D5).
     *
     * @return array{key: string, label: string, state: string, detail: string,
     *     action_label: string, action_url: string}
     */
    private function providerLine(): array
    {
        $line = [
            'key' => 'provider',
            'label' => 'Un fournisseur d’envoi',
            'action_label' => 'Ouvrir Fournisseurs',
            'action_url' => self::PROVIDERS_URL,
        ];

        try {
            $chains = $this->chains->all();
            $providers = $this->directory->all();
        } catch (\Throwable) {
            return $line + ['state' => 'unknown', 'detail' => 'La configuration des fournisseurs est illisible.'];
        }

        $relays = [];
        foreach ($providers as $provider) {
            if (!$provider->isLocal() && $provider->isUsable()) {
                $relays[] = $provider;
            }
        }

        if ($relays === []) {
            return $line + [
                'state' => 'missing',
                'detail' => 'Aucun relais : tout part du serveur lui-même, ce que beaucoup de destinataires '
                    . 'classent en indésirables.',
            ];
        }

        $uncovered = [];
        foreach (MailLane::ordered() as $lane) {
            $covered = false;
            foreach ($chains[$lane->value] ?? [] as $entry) {
                $provider = $providers[$entry->providerId] ?? null;
                if ($entry->enabled && $provider !== null && !$provider->isLocal() && $provider->isUsable()) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $uncovered[] = $lane->label();
            }
        }

        if ($uncovered !== []) {
            return $line + [
                'state' => 'warning',
                'detail' => sprintf(
                    '%d relais configuré%s, mais %s part encore du serveur lui-même.',
                    count($relays),
                    count($relays) > 1 ? 's' : '',
                    implode(' et ', array_map(static fn(string $l) => mb_strtolower($l), $uncovered))
                ),
            ];
        }

        return $line + [
            'state' => 'ok',
            'detail' => sprintf(
                '%d relais actif%s, sur les trois voies.',
                count($relays),
                count($relays) > 1 ? 's' : ''
            ),
        ];
    }

    /**
     * « Retours relevés » — does what comes back reach anybody.
     *
     * @return array{key: string, label: string, state: string, detail: string,
     *     action_label: string, action_url: string}
     */
    private function returnLine(): array
    {
        $line = [
            'key' => 'returns',
            'label' => 'Retours relevés',
            'action_label' => 'Ouvrir Authentification',
            'action_url' => self::AUTHENTICATION_URL,
        ];

        return $line + match ($this->worstReturnState()) {
            ReturnState::VERIFIED => [
                'state' => 'ok',
                'detail' => 'Un message envoyé à vos adresses de retour est bien revenu dans une boîte relevée.',
            ],
            ReturnState::WAITING => [
                'state' => 'unknown',
                'detail' => 'Une vérification est en cours : le message est parti, son retour n’a pas encore '
                    . 'été relevé.',
            ],
            ReturnState::NEVER_ARRIVED => [
                'state' => 'missing',
                'detail' => 'Un message envoyé à vos adresses de retour n’est jamais revenu. Les réponses et '
                    . 'les rebonds se perdent.',
            ],
            ReturnState::IMPOSSIBLE => [
                'state' => 'unknown',
                'detail' => 'Rien ne relève le courrier qui revient : le module « Courrier entrant » est '
                    . 'désactivé, ou aucune boîte ne lui est ouverte. Envoyer fonctionne sans cela.',
            ],
            ReturnState::NEVER_VERIFIED => [
                'state' => 'unknown',
                'detail' => 'Les retours n’ont jamais été vérifiés.',
            ],
        };
    }

    /**
     * Everything else, with its state — listed, never hidden.
     *
     * @return list<array{label: string, state_label: string, url: string, detail: string}>
     */
    private function advancedLines(): array
    {
        $identity = MailIdentity::fromSettings($this->settings);
        $queue = $this->queueSummary();

        try {
            $chains = $this->chains->all();
            $providers = $this->directory->all();
            $fallbacks = 0;
            foreach (MailLane::ordered() as $lane) {
                $active = 0;
                foreach ($chains[$lane->value] ?? [] as $entry) {
                    if ($entry->enabled && isset($providers[$entry->providerId])) {
                        $active++;
                    }
                }
                $fallbacks = max($fallbacks, $active - 1);
            }
        } catch (\Throwable) {
            $fallbacks = 0;
        }

        return [
            [
                'label' => 'Adresse de réponse distincte',
                'state_label' => $identity->configuredReplyAddress() === ''
                    ? 'Non — les réponses reviennent à l’adresse d’expédition'
                    : 'Oui',
                'url' => self::AUTHENTICATION_URL,
                'detail' => 'Utile quand personne ne relève l’adresse d’expédition.',
            ],
            [
                'label' => 'Rapports DMARC',
                'state_label' => $identity->configuredDmarcReportAddress() === ''
                    ? 'Aucune adresse renseignée'
                    : 'Demandés',
                'url' => self::AUTHENTICATION_URL,
                'detail' => 'Le résumé périodique que les autres opérateurs envoient sur ce que votre domaine '
                    . 'expédie.',
            ],
            [
                'label' => 'Chaîne de repli',
                'state_label' => $fallbacks > 0
                    ? sprintf('Oui — jusqu’à %d fournisseur%s de secours', $fallbacks, $fallbacks > 1 ? 's' : '')
                    : 'Aucun repli : une seule entrée active par voie',
                'url' => self::ROUTING_URL,
                'detail' => 'Ce qui évite que tout s’arrête quand un relais tombe ou atteint son quota.',
            ],
            [
                'label' => 'Messages en attente d’envoi',
                'state_label' => $queue['waiting'] > 0
                    ? sprintf('%d en file', $queue['waiting'])
                    : 'File vide',
                'url' => self::PROVIDERS_URL,
                'detail' => 'Les messages différés parce qu’une voie était épuisée ou en panne.',
            ],
        ];
    }


    /**
     * GET /config/courrier-sortant — the providers.
     *
     * @param array<string, string> $params
     */
    public function providers(Request $request, array $params): Response
    {
        $usedToday = $this->counters->totalsForDay();
        $chains = $this->chains->all();

        $cards = [];
        foreach ($this->directory->all() as $provider) {
            $cards[] = [
                'id' => $provider->id,
                'name' => $provider->name,
                'host' => $provider->hostSummary(),
                'is_local' => $provider->isLocal(),
                'daily_quota' => $provider->dailyQuota,
                'used_today' => $usedToday[$provider->id] ?? 0,
                'batch_size' => $provider->batchSize,
                'batch_interval_minutes' => $provider->batchIntervalMinutes,
                'lanes' => $this->enabledLaneLabels($chains, $provider->id),
                'configured' => $provider->isUsable(),
                // In small on the card, because the reserve is subtracted
                // from THIS provider's quota and the person reading the
                // « 412 / 1000 aujourd'hui » two lines above needs to know
                // that the mailing lane stopped at 930 on purpose (D14).
                'reserve' => $this->reserveOf($provider),
                'circuit' => $this->circuitOf($provider),
            ];
        }

        return $this->render('config/outbound_mail/providers.html.twig', [
            'providers' => $cards,
            'queue' => $this->queueSummary(),
            'page_url' => self::PROVIDERS_URL,
            'routing_url' => self::ROUTING_URL,
            'relaunch_url' => self::RELAUNCH_URL,
        ]);
    }

    /**
     * GET /config/courrier-sortant/fournisseurs/nouveau
     *
     * @param array<string, string> $params
     */
    public function createForm(Request $request, array $params): Response
    {
        return $this->render('config/outbound_mail/provider_form.html.twig', [
            'provider' => null,
            'page_url' => self::PROVIDERS_URL,
        ]);
    }

    /**
     * GET /config/courrier-sortant/fournisseurs/{id}
     *
     * @param array<string, string> $params
     */
    public function editForm(Request $request, array $params): Response
    {
        $provider = $this->directory->find((int) ($params['id'] ?? -1));
        if ($provider === null) {
            return $this->notFound();
        }

        return $this->render('config/outbound_mail/provider_form.html.twig', [
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'host' => $provider->host,
                'port' => $provider->port,
                'username' => $provider->username,
                'daily_quota' => $provider->dailyQuota,
                'batch_size' => $provider->batchSize,
                'batch_interval_minutes' => $provider->batchIntervalMinutes,
                'is_local' => $provider->isLocal(),
            ],
            'page_url' => self::PROVIDERS_URL,
        ]);
    }

    /**
     * POST /config/courrier-sortant/fournisseurs
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PROVIDERS_URL . '/nouveau')) !== null) {
            return $guard;
        }

        try {
            $this->transport->addProvider(
                (string) $request->getBody('name', ''),
                (string) $request->getBody('host', ''),
                (int) $request->getBody('port', 587),
                (string) $request->getBody('username', ''),
                (string) $request->getBody('password', ''),
                $this->optionalInt($request->getBody('daily_quota', '')),
                (int) $request->getBody('batch_size', 50),
                (int) $request->getBody('batch_interval_minutes', 10),
                AuthSession::getUserAccountId()
            );
        } catch (TransportException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PROVIDERS_URL . '/nouveau');
        }

        FlashMessage::set(
            'success',
            'Fournisseur ajouté. Il est désactivé dans les trois voies : activez-le depuis « Acheminement » '
                . 'pour qu’il serve à quelque chose.'
        );

        return $this->redirect(self::ROUTING_URL);
    }

    /**
     * POST /config/courrier-sortant/fournisseurs/{id}
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? -1);
        $back = self::PROVIDERS_URL . '/' . $id;

        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        if ($id === MailProvider::LOCAL_ID) {
            return $this->saveLocalCadence($request);
        }

        try {
            $this->transport->updateProvider(
                $id,
                (string) $request->getBody('name', ''),
                (string) $request->getBody('host', ''),
                (int) $request->getBody('port', 587),
                (string) $request->getBody('username', ''),
                // An empty password field means « keep the stored one »,
                // never « clear it »: the form cannot show an operator
                // what they would be retyping.
                ($password = (string) $request->getBody('password', '')) === '' ? null : $password,
                $this->optionalInt($request->getBody('daily_quota', '')),
                (int) $request->getBody('batch_size', 50),
                (int) $request->getBody('batch_interval_minutes', 10),
                AuthSession::getUserAccountId()
            );
        } catch (TransportException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect($back);
        }

        FlashMessage::set('success', 'Fournisseur enregistré.');

        return $this->redirect(self::PROVIDERS_URL);
    }

    /**
     * POST /config/courrier-sortant/fournisseurs/{id}/suppression
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PROVIDERS_URL)) !== null) {
            return $guard;
        }

        try {
            $this->transport->deleteProvider((int) ($params['id'] ?? -1), AuthSession::getUserAccountId());
        } catch (TransportException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PROVIDERS_URL);
        }

        FlashMessage::set('success', 'Fournisseur supprimé.');

        return $this->redirect(self::PROVIDERS_URL);
    }

    /**
     * POST /config/courrier-sortant/relance — put abandoned messages back
     * in the queue (D17).
     *
     * The window and the lanes both come from the form because they are
     * both decisions: « the ones from this morning, and only the
     * receipts » is a sentence a volunteer can mean, and one button that
     * relaunched everything would be used by nobody twice.
     *
     * @param array<string, string> $params
     */
    public function relaunch(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PROVIDERS_URL)) !== null) {
            return $guard;
        }

        $lanes = [];
        foreach ((array) $request->getBody('lanes', []) as $value) {
            $lane = is_scalar($value) ? MailLane::tryFrom((string) $value) : null;
            if ($lane !== null) {
                $lanes[] = $lane;
            }
        }

        if ($lanes === []) {
            FlashMessage::set('error', 'Choisissez au moins une voie à relancer.');

            return $this->redirect(self::PROVIDERS_URL);
        }

        try {
            $revived = $this->queue->relaunch($lanes, (string) $request->getBody('window', DeferredMailQueue::DEFAULT_WINDOW));
        } catch (\Throwable) {
            FlashMessage::set('error', 'La relance n’a pas pu être effectuée.');

            return $this->redirect(self::PROVIDERS_URL);
        }

        FlashMessage::set(
            $revived > 0 ? 'success' : 'info',
            $revived > 0
                ? sprintf(
                    '%d message%s remis en file. Ils repartiront à la prochaine passe, dans quelques minutes.',
                    $revived,
                    $revived > 1 ? 's ont été' : ' a été'
                )
                : 'Aucun message abandonné dans cette fenêtre.'
        );

        return $this->redirect(self::PROVIDERS_URL);
    }

    /**
     * GET /config/courrier-sortant/acheminement — the three chains.
     *
     * @param array<string, string> $params
     */
    public function routing(Request $request, array $params): Response
    {
        $providers = $this->directory->all();
        $chains = $this->chains->all();

        $lanes = [];
        foreach (MailLane::ordered() as $lane) {
            $items = [];
            $rank = 0;
            foreach ($chains[$lane->value] ?? [] as $entry) {
                $provider = $providers[$entry->providerId] ?? null;
                if ($provider === null) {
                    continue;
                }

                if ($entry->enabled) {
                    $rank++;
                }

                $items[] = [
                    'id' => $provider->id,
                    'is_active' => $entry->enabled,
                    'name' => $provider->name,
                    'host' => $provider->hostSummary(),
                    'rank' => $entry->enabled ? $rank : null,
                    'is_local' => $provider->isLocal(),
                    'configured' => $provider->isUsable(),
                    // The local send in the mailing lane is legal and
                    // discouraged, which is a different sentence from
                    // forbidden: a unit with no relay at all has nothing
                    // else, and the screen says why it is a bad idea
                    // rather than refusing.
                    'discouraged' => $provider->isLocal() && $lane === MailLane::Bulk && $entry->enabled,
                ];
            }

            $lanes[] = [
                'key' => $lane->value,
                'label' => $lane->label(),
                'detail' => $lane->detail(),
                'items' => $items,
                'honours_cadence' => $lane->honoursCadence(),
                // Under the authentication lane and nowhere else: the
                // reserve is subtracted from the MAILING lane's ceiling,
                // but it exists FOR this one, and it is here that a
                // volunteer asking « will people still be able to log in
                // during the newsletter » is looking (D14).
                'reserves' => $lane === MailLane::Authentication ? $this->reservesForLane($items) : [],
            ];
        }

        return $this->render('config/outbound_mail/routing.html.twig', [
            'lanes' => $lanes,
            'page_url' => self::PAGE_URL,
            'routing_url' => self::ROUTING_URL,
        ]);
    }

    /**
     * POST /config/courrier-sortant/acheminement/{lane}/ordre
     *
     * @param array<string, string> $params
     */
    public function reorder(Request $request, array $params): Response
    {
        $payload = $this->jsonPayload($request);
        if ($payload === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($payload['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $lane = MailLane::tryFrom((string) ($params['lane'] ?? ''));
        if ($lane === null) {
            return $this->json(['success' => false, 'error' => 'Voie inconnue.'], 404);
        }

        $ids = $payload['ids'] ?? null;
        if (!is_array($ids)) {
            return $this->json(['success' => false, 'error' => 'Ordre invalide.'], 400);
        }

        $this->transport->reorderLane(
            $lane,
            array_map(
                static fn(mixed $id): int => is_scalar($id) ? (int) $id : -1,
                array_values($ids)
            ),
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/courrier-sortant/acheminement/{lane}/activation
     *
     * @param array<string, string> $params
     */
    public function toggle(Request $request, array $params): Response
    {
        $payload = $this->jsonPayload($request);
        if ($payload === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($payload['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $lane = MailLane::tryFrom((string) ($params['lane'] ?? ''));
        if ($lane === null) {
            return $this->json(['success' => false, 'error' => 'Voie inconnue.'], 404);
        }

        try {
            $this->transport->setEntryEnabled(
                $lane,
                (int) ($payload['id'] ?? -1),
                (bool) ($payload['active'] ?? false),
                AuthSession::getUserAccountId()
            );
        } catch (TransportException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 409);
        }

        return $this->json(['success' => true]);
    }

    /**
     * The body of a request this screen's JavaScript sent, decoded.
     *
     * **`Request::getBody()` cannot be used here, and the reason is not
     * obvious from the call site.** `public/assets/js/list-editor.js`
     * posts through `ScoutMagicApi.postJson()`, which sends
     * `Content-Type: application/json` — and PHP never populates `$_POST`
     * for a JSON body, so `Request::fromGlobals()` builds its `body` from
     * an empty array. Read that way, every field silently comes back as
     * its default: a reorder would apply an empty order and answer
     * `success`, and a toggle would look up entry `-1` and refuse every
     * provider anybody tried to enable.
     *
     * So the raw body is decoded explicitly, the way every other
     * list-editor endpoint in this codebase already does
     * (`ConfigModulesController`, `SectionDocumentController`). Null means
     * « this is not a JSON object », which is a 400 rather than a set of
     * defaults quietly standing in for what the caller meant.
     *
     * @return array<string, mixed>|null
     */
    private function jsonPayload(Request $request): ?array
    {
        $decoded = json_decode($request->getRawBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The local send has no row, so « saving » it writes the two settings
     * that carry its cadence — and nothing else: it has no host, no
     * credentials and, deliberately, no quota (D6).
     */
    private function saveLocalCadence(Request $request): Response
    {
        $batchSize = max(1, (int) $request->getBody('batch_size', MailProviderDirectory::DEFAULT_LOCAL_BATCH_SIZE));
        $interval = max(
            1,
            (int) $request->getBody('batch_interval_minutes', MailProviderDirectory::DEFAULT_LOCAL_BATCH_INTERVAL)
        );

        try {
            $this->settings->set(MailProviderDirectory::SETTING_LOCAL_BATCH_SIZE, (string) $batchSize);
            $this->settings->set(MailProviderDirectory::SETTING_LOCAL_BATCH_INTERVAL, (string) $interval);
        } catch (\Core\Config\SettingException $e) {
            // SettingException is user-facing by construction, so its own
            // sentence is the one to show — a setting that vanished under
            // the form is something the person can act on by reloading.
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PROVIDERS_URL . '/' . MailProvider::LOCAL_ID);
        }

        $this->directory->refresh();

        FlashMessage::set('success', 'Cadence de l’envoi local enregistrée.');

        return $this->redirect(self::PROVIDERS_URL);
    }

    /**
     * The reserve each entry of a lane carries, with its provenance.
     *
     * Printed as sentences rather than a number, because the number is
     * the part nobody can check: « 74 » is arbitrary until it says « votre
     * pointe hors publipostage des 30 derniers jours (54), plus une marge
     * de 20 », and then it is arithmetic somebody can disagree with.
     *
     * @param array<int, array{id: int, name: string}> $items
     * @return array<int, array{name: string, messages: int, provenance: string}>
     */
    private function reservesForLane(array $items): array
    {
        $providers = $this->directory->all();

        $reserves = [];
        foreach ($items as $item) {
            $provider = $providers[$item['id']] ?? null;
            if ($provider === null) {
                continue;
            }

            $reserve = $this->reserveOf($provider);
            if ($reserve !== null) {
                $reserves[] = [
                    'name' => $provider->name,
                    'messages' => $reserve['messages'],
                    'provenance' => $reserve['provenance'],
                ];
            }
        }

        return $reserves;
    }

    /**
     * What this provider is holding back for the sign-in links, in one
     * sentence (D14).
     *
     * Null when the reserve does not apply — a provider that is not
     * shared between the mailing lane and another one has nothing to
     * protect anybody from, and a card saying « réserve : aucune » would
     * be three lines explaining a number that is not there.
     *
     * @return array{messages: int, provenance: string}|null
     */
    private function reserveOf(MailProvider $provider): ?array
    {
        try {
            $reserve = $this->reserve->forProvider($provider);
        } catch (\Throwable) {
            return null;
        }

        return $reserve->applies()
            ? ['messages' => $reserve->messages, 'provenance' => $reserve->provenance()]
            : null;
    }

    /**
     * Whether the breaker is currently holding this provider out (D15).
     *
     * Shown because the alternative is a screen that says a provider is
     * active and configured while nothing goes through it — and the
     * person looking at that screen concludes their configuration is
     * wrong and starts changing it.
     *
     * @return array{open: bool, until: ?string, failures: int, openings: int}|null
     */
    private function circuitOf(MailProvider $provider): ?array
    {
        try {
            $health = $this->health->forProvider($provider->id);
        } catch (\Throwable) {
            return null;
        }

        if (
            $health->consecutiveFailures === 0
            && $health->openedUntil === null
            && $health->openCount === 0
        ) {
            // Nothing has ever gone wrong with this provider. `openCount`
            // has to be in the test: a provider that recovered has its
            // consecutive count cleared and its lockout lifted, and
            // returning null there would hide the one figure worth
            // showing — how often it has come back broken.
            return null;
        }

        return [
            'open' => $health->isOpen(),
            'until' => $health->openedUntil,
            'failures' => $health->consecutiveFailures,
            'openings' => $health->openCount,
        ];
    }

    /**
     * The queue, as the page shows it — « un report n'est pas un
     * silence » (D9).
     *
     * The authentication lane is in the list with a permanent zero rather
     * than left out, because its absence would read as « nothing is
     * waiting there » when the truth is « nothing can ever wait there »,
     * and those are the two halves of the decision this page exists to
     * make legible.
     *
     * @return array{lanes: array<int, array{key: string, label: string, waiting: int, defers: bool}>,
     *     waiting: int, abandoned: array{recent: int, day: int, week: int, older: int, total: int},
     *     lifetime_hours: int, retention_days: int, windows: array<int, array{key: string, label: string}>,
     *     default_window: string}
     */
    private function queueSummary(): array
    {
        try {
            $pending = $this->deferred->pendingCountByLane();
            $abandoned = $this->queue->abandonedByAge();
        } catch (\Throwable) {
            $pending = [];
            $abandoned = ['recent' => 0, 'day' => 0, 'week' => 0, 'older' => 0, 'total' => 0];
        }

        $lanes = [];
        foreach (MailLane::ordered() as $lane) {
            $lanes[] = [
                'key' => $lane->value,
                'label' => $lane->label(),
                'waiting' => $pending[$lane->value] ?? 0,
                'defers' => $this->queue->defers($lane),
            ];
        }

        return [
            'lanes' => $lanes,
            'waiting' => array_sum($pending),
            'abandoned' => $abandoned,
            'lifetime_hours' => $this->queue->lifetimeHours(),
            'retention_days' => $this->queue->abandonedRetentionDays(),
            'windows' => [
                ['key' => 'recent', 'label' => 'Les 6 dernières heures'],
                ['key' => 'day', 'label' => 'Les 24 dernières heures'],
                ['key' => 'week', 'label' => 'La semaine écoulée'],
            ],
            'default_window' => DeferredMailQueue::DEFAULT_WINDOW,
        ];
    }


    /**
     * GET /config/courrier-sortant/authentification — the addresses, the
     * four roles they play, and the three DNS records that let other
     * operators believe them (roadmap IT-03).
     *
     * **The DNS check lives here now, and nowhere else.** It used to be a
     * panel of the installation wizard, which is the one place it could
     * be before this page existed: a site has to be able to send before
     * anybody opens a configuration screen. The wizard keeps that initial
     * entry and now points here for everything after it — the same field
     * editable in two places is what guarantees the two will disagree.
     *
     * **The lookup is behind an explicit button**, not on page load: a
     * `dns_get_record()` on a resolver that is not answering takes as
     * long as it takes, and a configuration page that sometimes hangs for
     * ten seconds is a page people stop opening. `?dns=1` is what asks
     * for it.
     *
     * @param array<string, string> $params
     */
    public function authentication(Request $request, array $params): Response
    {
        return $this->renderAuthentication();
    }

    /**
     * POST /config/courrier-sortant/authentification/dns — take the
     * lookup, keep what it found, come back.
     *
     * **A POST and a redirect**, like `/config/maintenance/update/
     * check-now` and for the same two reasons: it reaches out to the
     * network and it writes down what came back, neither of which belongs
     * on a GET. The page then renders the remembered reading with its
     * date, so reopening it does not lose the records somebody is halfway
     * through copying into their registrar's form.
     *
     * @param array<string, string> $params
     */
    public function checkDns(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::AUTHENTICATION_URL)) !== null) {
            return $guard;
        }

        $identity = MailIdentity::fromSettings($this->settings);
        $spfDomain = $identity->spfDomain();
        $selector = $this->dkimSelector();

        if ($spfDomain === '' || $selector === '') {
            // A reading nobody could take clears the memory rather than
            // overwriting it with a false negative nobody could tell from
            // a real one.
            DnsCheckMemory::forget($this->settings);
            FlashMessage::set(
                'warning',
                'Renseignez d’abord l’adresse d’expédition et le sélecteur DKIM : sans eux, il n’y a ni domaine '
                    . 'à interroger ni enregistrement à proposer.'
            );

            return $this->redirect(self::AUTHENTICATION_URL);
        }

        $dkimDomain = $identity->dkimDomain();
        $hasKey = $this->dkim->hasKey();

        DnsCheckMemory::remember(
            $this->settings,
            $spfDomain,
            $dkimDomain,
            $selector,
            $identity->configuredDmarcReportAddress(),
            [
                // `?? []` deliberately: a relay list that could not be
                // read and a site with no relay reach the same verdict,
                // « je ne peux pas répondre ». Which of the two it was is
                // a live reading, said on the screen, not a stale one
                // frozen into the stored record.
                DnsCheckMemory::SPF => $this->dns->checkSpfForHosts($spfDomain, $this->sendingHosts() ?? []),
                // Nothing can be proposed before a key pair exists: there
                // is no value to publish, not even a placeholder.
                DnsCheckMemory::DKIM => $hasKey
                    ? $this->dns->checkDkim($dkimDomain, $selector, $this->dkim->getPublicKey())
                    : ['key_missing' => true],
                // Only when an address was actually asked for: a site that
                // wants no reports needs no record, and proposing one
                // would be pushing an edit nobody asked for (D12 — `rua`
                // only, never `ruf`).
                DnsCheckMemory::DMARC => $identity->configuredDmarcReportAddress() === ''
                    ? ['not_requested' => true]
                    : $this->dns->checkDmarc($dkimDomain, $identity->dmarcReportAddress()),
            ]
        );

        return $this->redirect(self::AUTHENTICATION_URL);
    }

    /**
     * POST /config/courrier-sortant/authentification — save the addresses.
     *
     * @param array<string, string> $params
     */
    public function saveAuthentication(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::AUTHENTICATION_URL)) !== null) {
            return $guard;
        }

        $fromAddress = trim((string) $request->getBody('mail_from_address', ''));
        $fromName = trim((string) $request->getBody('mail_from_name', ''));
        $replyAddress = trim((string) $request->getBody('mail_reply_address', ''));
        $dmarcAddress = trim((string) $request->getBody('dmarc_report_email', ''));
        $selector = trim((string) $request->getBody('dkim_selector', ''));

        $error = $this->addressError($fromAddress, $fromName, $replyAddress, $dmarcAddress, $selector);
        if ($error !== null) {
            FlashMessage::set('error', $error);

            return $this->redirect(self::AUTHENTICATION_URL);
        }

        $before = MailIdentity::fromSettings($this->settings);

        try {
            // One decision, one write. Five separate `set()` calls would
            // each be their own UPDATE: a failure on the fourth would
            // leave the first three written while the page says nothing
            // was saved, and the next reader could not tell that
            // half-state from a configuration somebody meant.
            $this->settings->setMany([
                MailIdentity::SETTING_FROM_ADDRESS => $fromAddress,
                MailIdentity::SETTING_FROM_NAME => $fromName,
                MailIdentity::SETTING_REPLY_ADDRESS => $replyAddress,
                MailIdentity::SETTING_DMARC_REPORT => $dmarcAddress,
                'dkim_selector' => $selector,
            ]);
        } catch (\Throwable) {
            FlashMessage::set('error', 'Les adresses n’ont pas pu être enregistrées.');

            return $this->redirect(self::AUTHENTICATION_URL);
        }

        // An address that is no longer in use takes its verification with
        // it — « modifier une adresse remet l'état à jamais vérifié ». The
        // state is looked up BY the address, so a new one already has no
        // answer; this is the other half, which stops the site keeping a
        // copy of an address it no longer sends from.
        $this->returns->forgetAllExcept($this->verifiableAddresses());

        $this->journalAddressChange($before, MailIdentity::fromSettings($this->settings));

        FlashMessage::set(
            'success',
            'Adresses enregistrées. Les messages déjà en file partiront avec les nouvelles.'
        );

        return $this->redirect(self::AUTHENTICATION_URL);
    }

    /**
     * Write down that an address changed — `security`, and never the
     * address itself.
     *
     * **`security` because it decides where the site's mail comes from
     * and goes back to**, which is a security decision even when it is
     * made in perfect good faith: an expédition address pointing
     * somewhere else is every sign-in link pointing somewhere else.
     *
     * **And no address text, ever.** The journal is read on a screen and
     * kept for a long time; what it needs is « which role changed », not
     * the value — the same rule `member_email_added` already follows with
     * `member_id` alone. Nothing is written when nothing changed, so a
     * page saved twice does not read as two decisions.
     */
    private function journalAddressChange(MailIdentity $before, MailIdentity $after): void
    {
        // The role CONSTANTS, not the French labels the screen shows for
        // them. This payload is stored data, not interface: the journal
        // page prints it as a raw JSON block, and AGENTS.md keeps stored
        // literals in English for the same reason it keeps column names
        // there. `MailIdentity` already names the four roles; a second,
        // French vocabulary for the same three of them would be a second
        // thing to keep in step.
        $changed = [];
        if ($before->fromAddress !== $after->fromAddress) {
            $changed[] = MailIdentity::ROLE_FROM;
        }
        if ($before->configuredReplyAddress() !== $after->configuredReplyAddress()) {
            $changed[] = MailIdentity::ROLE_REPLY;
        }
        if ($before->configuredDmarcReportAddress() !== $after->configuredDmarcReportAddress()) {
            $changed[] = MailIdentity::ROLE_DMARC;
        }

        if ($changed === []) {
            return;
        }

        $this->journal->log(
            'core',
            'mail_identity_changed',
            'security',
            'Adresse du courrier sortant modifiée',
            ['roles' => implode(', ', $changed)],
            AuthSession::getUserAccountId()
        );
    }

    /**
     * GET /config/courrier-sortant/sonde — the manual probe (roadmap
     * IT-04).
     *
     * **One message, sent by hand, and a verdict typed in by the person
     * who went and looked.** No site can observe another provider's spam
     * folder, so the instrument here is a human being with a mailbox
     * open; everything this page does is make that cheap to do and
     * impossible to forget the result of.
     *
     * @param array<string, string> $params
     */
    public function probe(Request $request, array $params): Response
    {
        return $this->renderProbe();
    }

    /**
     * POST /config/courrier-sortant/sonde/envoi — send one.
     *
     * @param array<string, string> $params
     */
    public function sendProbe(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PROBE_URL)) !== null) {
            return $guard;
        }

        if ($this->probes === null) {
            FlashMessage::set('error', self::PROBE_UNAVAILABLE);

            return $this->redirect(self::PROBE_URL);
        }

        $lane = MailLane::tryFrom($request->getBody('lane') ?? '') ?? MailProbeSender::DEFAULT_LANE;

        try {
            $probe = $this->probes->send(
                (string) ($request->getBody('destination') ?? ''),
                (int) ($request->getBody('provider_id') ?? 0),
                $lane
            );
        } catch (MailProbeNotRecordedException $e) {
            // `warning` and not `error`: the message DID leave. Calling
            // it an error is what makes somebody press the button again
            // and send a duplicate — which is precisely what the sentence
            // in the exception asks them not to do.
            FlashMessage::set('warning', $e->getMessage());

            return $this->redirect(self::PROBE_URL);
        } catch (MailProbeException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PROBE_URL);
        }

        FlashMessage::set(
            'success',
            sprintf(
                'Sonde envoyée par « %s ». Cherchez %s dans le sujet — y compris dans les indésirables — '
                    . 'puis dites ci-dessous où vous l’avez trouvée.',
                $probe->providerName,
                $probe->code
            )
        );

        return $this->redirect(self::PROBE_URL);
    }

    /**
     * POST /config/courrier-sortant/sonde/verdict — record where it
     * landed.
     *
     * @param array<string, string> $params
     */
    public function recordProbeVerdict(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PROBE_URL)) !== null) {
            return $guard;
        }

        if ($this->probes === null) {
            FlashMessage::set('error', self::PROBE_UNAVAILABLE);

            return $this->redirect(self::PROBE_URL);
        }

        $verdict = MailProbeVerdict::tryFromInput((string) ($request->getBody('verdict') ?? ''));
        if ($verdict === null) {
            FlashMessage::set('error', 'Ce verdict n’existe pas.');

            return $this->redirect(self::PROBE_URL);
        }

        try {
            $written = $this->probes->recordVerdict((int) ($request->getBody('probe_id') ?? 0), $verdict);
        } catch (MailProbeException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PROBE_URL);
        }

        // A verdict already on file is not an error and not a silence
        // either: a double click, or a second tab, and the operator needs
        // to know which answer stood.
        FlashMessage::set(
            $written ? 'success' : 'warning',
            $written
                ? 'Verdict consigné : ' . $verdict->label() . '. ' . $verdict->guidance()
                : 'Cette sonde avait déjà un verdict ; il n’a pas été remplacé.'
        );

        return $this->redirect(self::PROBE_URL);
    }

    private function renderProbe(): Response
    {
        $verdicts = [];
        foreach (MailProbeVerdict::ordered() as $verdict) {
            $verdicts[] = ['value' => $verdict->value, 'label' => $verdict->label()];
        }

        // Shaped for partials/form_field.html.twig, which is what the
        // section's other form already uses.
        $laneOptions = [];
        foreach (MailProbeSender::OFFERED_LANES as $lane) {
            $laneOptions[] = [
                'value' => $lane->value,
                'label' => $lane->label(),
                'selected' => $lane === MailProbeSender::DEFAULT_LANE,
            ];
        }

        $providerOptions = [];
        foreach ($this->probeProviders() as $provider) {
            $providerOptions[] = ['value' => (string) $provider['id'], 'label' => $provider['name']];
        }

        return $this->render('config/outbound_mail/probe.html.twig', [
            'available' => $this->probes !== null,
            'unavailable_reason' => self::PROBE_UNAVAILABLE,
            'providers' => $this->probeProviders(),
            'provider_options' => $providerOptions,
            'lane_options' => $laneOptions,
            'verdicts' => $verdicts,
            'pending' => $this->probeLines($this->probeHistory?->pending() ?? []),
            'history' => $this->probeLines($this->probeHistory?->recent() ?? []),
            'send_url' => self::PROBE_SEND_URL,
            'verdict_url' => self::PROBE_VERDICT_URL,
            'providers_url' => self::PROVIDERS_URL,
            'current_path' => self::PROBE_URL,
        ]);
    }

    /**
     * The relays the form offers.
     *
     * @return list<array{id: int, name: string, host: string}>
     */
    private function probeProviders(): array
    {
        $lines = [];
        foreach ($this->probes?->availableProviders() ?? [] as $provider) {
            $lines[] = ['id' => $provider->id, 'name' => $provider->name, 'host' => $provider->hostSummary()];
        }

        return $lines;
    }

    /**
     * The history, shaped for the template.
     *
     * **The destination IS shown here**, and it is the one screen where
     * that is right: it is the address the operator typed themselves, one
     * line ago, and a history that hid it could not answer « même
     * destinataire, deux relais, deux verdicts » — which is the entire
     * reason the table exists. It stays out of the journal and out of the
     * support package all the same, because those are read elsewhere and
     * kept far longer.
     *
     * @param list<\Core\Mail\Probe\MailProbe> $probes
     * @return list<array{id: int, code: string, destination: string, provider: string, lane: string,
     *     sent_at: string, verdict: ?string, verdict_label: ?string, verdict_badge: ?string,
     *     guidance: ?string}>
     */
    private function probeLines(array $probes): array
    {
        $lines = [];
        foreach ($probes as $probe) {
            $lines[] = [
                'id' => $probe->id,
                'code' => $probe->code,
                'destination' => $probe->destination,
                'provider' => $probe->providerName,
                'lane' => $probe->lane->label(),
                'sent_at' => $probe->sentAt->format('d/m/Y à H:i'),
                'verdict' => $probe->verdict?->value,
                'verdict_label' => $probe->verdict?->label(),
                'verdict_badge' => $probe->verdict?->badge(),
                'guidance' => $probe->verdict?->guidance(),
            ];
        }

        return $lines;
    }

    /**
     * POST /config/courrier-sortant/authentification/verification — send
     * the site a message at each of its own return addresses (IT-03).
     *
     * @param array<string, string> $params
     */
    public function verifyReturns(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::AUTHENTICATION_URL)) !== null) {
            return $guard;
        }

        // Wrapped like every other write path on this controller
        // (`saveAuthentication()`, `create()`, `update()`, `delete()`).
        // `launch()` already absorbs a per-address failure into its
        // `failed` count, so what reaches here is the rest — the journal
        // write, a broken encryption key — and a diagnostic page that
        // answers with the generic error screen is a diagnostic page
        // that has stopped diagnosing.
        try {
            $result = $this->returns->launch($this->verifiableAddresses());
        } catch (\Throwable) {
            FlashMessage::set(
                'error',
                'La vérification n’a pas pu être lancée. Le détail est dans le journal technique.'
            );

            return $this->redirect(self::AUTHENTICATION_URL);
        }

        if ($result['impossible']) {
            FlashMessage::set(
                'warning',
                'La vérification est impossible pour le moment : elle a besoin du module « Courrier entrant » '
                    . 'et d’au moins une boîte ouverte à « Courrier sortant ».'
            );
        } elseif ($result['sent'] === 0) {
            FlashMessage::set('error', 'Aucun message de vérification n’a pu partir. Regardez la page « Fournisseurs ».');
        } else {
            FlashMessage::set(
                'success',
                sprintf(
                    '%d message%s de vérification envoyé%s. L’état passera à « vérifié » dès que le relevé des '
                        . 'boîtes l’aura vu revenir — cela peut prendre quelques minutes.',
                    $result['sent'],
                    $result['sent'] > 1 ? 's' : '',
                    $result['sent'] > 1 ? 's' : ''
                )
            );
        }

        return $this->redirect(self::AUTHENTICATION_URL);
    }

    /**
     * The addresses worth a round trip: the one bounces come back to, and
     * the one replies come back to.
     *
     * Not the DMARC report address: nothing a human ever writes goes
     * there, what lands in it is a machine report, and IT-06 answers
     * « are the reports arriving » by looking at the reports themselves
     * rather than by writing to the box.
     *
     * @return list<string>
     */
    private function verifiableAddresses(): array
    {
        $identity = MailIdentity::fromSettings($this->settings);

        return array_values(array_filter(
            [$identity->bounceAddress(), $identity->replyAddress()],
            static fn(string $address) => $address !== ''
        ));
    }

    /**
     * The one sentence that comes back when a field is refused — the
     * first problem only, because a volunteer fixing one field at a time
     * is what actually happens.
     */
    private function addressError(
        string $fromAddress,
        string $fromName,
        string $replyAddress,
        string $dmarcAddress,
        string $selector
    ): ?string {
        if ($fromAddress === '') {
            return 'L’adresse d’expédition est obligatoire : sans elle, le site ne peut plus envoyer un seul '
                . 'message, liens de connexion compris.';
        }
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            return 'L’adresse d’expédition n’est pas une adresse valide.';
        }
        if ($fromName === '') {
            return 'Le nom d’expédition est obligatoire — c’est ce que la personne lit avant l’adresse.';
        }
        if ($replyAddress !== '' && filter_var($replyAddress, FILTER_VALIDATE_EMAIL) === false) {
            return 'L’adresse de réponse n’est pas une adresse valide. Laissez le champ vide pour que les '
                . 'réponses reviennent à l’adresse d’expédition.';
        }
        if ($dmarcAddress !== '' && filter_var($dmarcAddress, FILTER_VALIDATE_EMAIL) === false) {
            return 'L’adresse des rapports DMARC n’est pas une adresse valide.';
        }
        if ($selector === '' || preg_match('/^[a-z0-9]+$/', $selector) !== 1) {
            return 'Le sélecteur DKIM ne peut contenir que des lettres minuscules et des chiffres.';
        }

        return null;
    }

    private function renderAuthentication(): Response
    {
        $identity = MailIdentity::fromSettings($this->settings);
        $hosts = $this->sendingHosts();

        return $this->render('config/outbound_mail/authentication.html.twig', [
            'returns' => $this->returnStates($identity),
            'return_check_url' => self::RETURN_CHECK_URL,
            // A COUNT, never the list: what would come back from the
            // module's scope-aware query are mailbox addresses, and this
            // screen has no business printing one.
            'watched_mailboxes' => $this->returns->scopedMailboxCount(),
            'returns_possible' => $this->returns->isPossible(),
            'returns_need_scope' => $this->returns->isCollectingWithoutScope(),
            'identity' => [
                'from_address' => $identity->fromAddress,
                'from_name' => $identity->fromName,
                'reply_address' => $identity->configuredReplyAddress(),
                'dmarc_report_email' => $identity->configuredDmarcReportAddress(),
                'dkim_selector' => (string) ($this->settings->get('dkim_selector') ?? ''),
            ],
            'roles' => $identity->roles(),
            'spf_domain' => $identity->spfDomain(),
            'dkim_domain' => $identity->dkimDomain(),
            'sending_hosts' => $hosts ?? [],
            'sending_hosts_unreadable' => $hosts === null,
            'has_dkim_key' => $this->dkim->hasKey(),
            'dkim_public_key' => $this->dkim->hasKey() ? $this->dkim->getPublicKey() : '',
            'dns' => $this->rememberedDns(),
            'dns_check_url' => self::DNS_CHECK_URL,
            'authentication_url' => self::AUTHENTICATION_URL,
        ]);
    }

    /**
     * What the round trip says about each address worth one — keyed by
     * the address, because one address answering several roles is the
     * ordinary case and must produce one row, not three.
     *
     * @return array<string, array{label: string, badge: string, address: string,
     *     sent_at: ?string, received_at: ?string, mailbox: ?string}>
     */
    private function returnStates(MailIdentity $identity): array
    {
        $format = static fn(?\DateTimeImmutable $at): ?string => $at?->format('d/m/Y à H:i');

        $states = [];
        foreach ($this->verifiableAddresses() as $address) {
            if (isset($states[$address])) {
                continue;
            }

            $state = $this->returns->stateFor($address);
            $states[$address] = [
                'label' => $state['state']->label(),
                'badge' => $state['state']->badge(),
                'address' => $address,
                'sent_at' => $format($state['sent_at']),
                'received_at' => $format($state['received_at']),
                'mailbox' => $state['mailbox'],
            ];
        }

        return $states;
    }

    /**
     * The single worst state among the addresses — what the dashboard's
     * « retours relevés » line reads.
     *
     * Worst rather than best, and rather than a count: one address that
     * never comes back is a broken return path, and a green line next to
     * it would be the dashboard lying about exactly what it exists to
     * report.
     */
    private function worstReturnState(): ReturnState
    {
        // Asked before the addresses, and not after: a site with no
        // address at all would otherwise read « jamais vérifié » here,
        // which invites somebody to press a button that cannot work. The
        // dashboard's first line already says the address is missing;
        // this one says the other half of the truth.
        if (!$this->returns->isPossible()) {
            return ReturnState::IMPOSSIBLE;
        }

        $order = [
            ReturnState::IMPOSSIBLE,
            ReturnState::NEVER_ARRIVED,
            ReturnState::NEVER_VERIFIED,
            ReturnState::WAITING,
            ReturnState::VERIFIED,
        ];

        $worst = null;
        foreach ($this->verifiableAddresses() as $address) {
            $state = $this->returns->stateFor($address)['state'];
            if ($worst === null
                || array_search($state, $order, true) < array_search($worst, $order, true)
            ) {
                $worst = $state;
            }
        }

        return $worst ?? ReturnState::NEVER_VERIFIED;
    }

    /**
     * The last lookup, or null when there has never been one *or* when
     * the one on file is about another domain or another selector.
     *
     * Every screen goes through here rather than through
     * {@see DnsCheckMemory::read()} directly, so that « the addresses
     * moved » cannot be read anywhere as « the zone is in order ».
     */
    private function lastDnsReading(MailIdentity $identity): ?DnsCheckMemory
    {
        $memory = DnsCheckMemory::read($this->settings);
        if ($memory === null || !$memory->describes($identity, $this->dkimSelector())) {
            return null;
        }

        return $memory;
    }

    /**
     * The reading that exists but no longer applies — what the dashboard
     * needs to say « ce relevé ne dit plus rien » rather than « jamais
     * vérifié », which are two different instructions to the reader.
     */
    private function staleDnsReading(MailIdentity $identity): ?DnsCheckMemory
    {
        $memory = DnsCheckMemory::read($this->settings);

        return $memory !== null && !$memory->describes($identity, $this->dkimSelector()) ? $memory : null;
    }

    private function dkimSelector(): string
    {
        return (string) ($this->settings->get('dkim_selector') ?? '');
    }

    /**
     * The last lookup, shaped for the template — null when there has
     * never been one.
     *
     * @return array{taken_at: string, spf_domain: string, dkim_domain: string, selector: string,
     *     records: list<array{key: string, label: string, name: string, host: string,
     *         exists: bool, expected: ?string, actual: ?string, key_missing: bool, not_requested: bool}>}|null
     */
    private function rememberedDns(): ?array
    {
        $memory = $this->lastDnsReading(MailIdentity::fromSettings($this->settings));
        if ($memory === null) {
            return null;
        }

        $names = [
            DnsCheckMemory::SPF => ['SPF', '@', $memory->spfDomain],
            DnsCheckMemory::DKIM => [
                'DKIM',
                $memory->selector . '._domainkey',
                $memory->selector . '._domainkey.' . $memory->dkimDomain,
            ],
            DnsCheckMemory::DMARC => ['DMARC', '_dmarc', '_dmarc.' . $memory->dkimDomain],
        ];

        $records = [];
        foreach (DnsCheckMemory::RECORDS as $key) {
            [$label, $name, $host] = $names[$key];
            $records[] = ['key' => $key, 'label' => $label, 'name' => $name, 'host' => $host]
                + $memory->record($key);
        }

        return [
            'taken_at' => $memory->takenAt->format('d/m/Y à H:i'),
            'spf_domain' => $memory->spfDomain,
            'dkim_domain' => $memory->dkimDomain,
            'selector' => $memory->selector,
            'records' => $records,
        ];
    }

    /**
     * Every relay a message may actually leave through — what the SPF
     * record has to authorise.
     *
     * Enabled in at least one lane, because a provider nobody routes
     * anything to sends nothing and putting its host in the record would
     * be authorising a host for no reason. The local send contributes no
     * host: it leaves from the web server itself, and no `a:` mechanism
     * names that.
     *
     * **Null when the list could not be read, and an empty array when
     * there is genuinely no relay — never the same answer.** They used to
     * be, and it was the expensive kind of confusion: an empty list makes
     * `checkSpfForHosts()` unable to falsify anything, so a provider
     * table that failed to load turned the dashboard's SPF line green.
     * A failure that reads as a success is worse than a failure.
     *
     * @return list<string>|null
     */
    private function sendingHosts(): ?array
    {
        try {
            $chains = $this->chains->all();
            $providers = $this->directory->all();
        } catch (\Throwable) {
            return null;
        }

        $hosts = [];
        foreach ($providers as $provider) {
            if ($provider->isLocal() || $provider->host === '') {
                continue;
            }
            if ($this->enabledLaneLabels($chains, $provider->id) === []) {
                continue;
            }
            if (!in_array($provider->host, $hosts, true)) {
                $hosts[] = $provider->host;
            }
        }

        return $hosts;
    }

    /**
     * Which lanes currently use this provider, by their French names.
     *
     * @param array<string, array<int, \Core\Mail\Transport\LaneEntry>> $chains
     * @return array<int, string>
     */
    private function enabledLaneLabels(array $chains, int $providerId): array
    {
        $labels = [];
        foreach (MailLane::ordered() as $lane) {
            foreach ($chains[$lane->value] ?? [] as $entry) {
                if ($entry->providerId === $providerId && $entry->enabled) {
                    $labels[] = $lane->label();
                }
            }
        }

        return $labels;
    }

    /**
     * An empty quota field is « no known daily ceiling », which is null —
     * never zero, which a form would otherwise store as « this provider
     * accepts nothing ».
     */
    private function optionalInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' ? null : (int) $value;
    }
}
