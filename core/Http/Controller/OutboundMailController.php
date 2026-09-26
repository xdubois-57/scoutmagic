<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\Exception\UserFacingMessage;
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

    public const BOUNCES_URL = '/config/courrier-sortant/rebonds';
    public const BOUNCE_UNBLOCK_URL = '/config/courrier-sortant/rebonds/{id}/reprise';

    /** Said the same way wherever the bounce page cannot run at all. */
    private const BOUNCES_UNAVAILABLE = 'Le suivi des rebonds demande le module « Courrier entrant ».';

    public const DMARC_URL = '/config/courrier-sortant/dmarc';

    public const SEEDS_URL = '/config/courrier-sortant/temoins';

    private const SEEDS_UNAVAILABLE = 'Les boîtes témoins demandent le module « Courrier entrant ».';

    /**
     * The window the results screen reports on.
     *
     * Thirty days like the DMARC page, and for the same reason: a unit
     * sends a few mailings a month, so thirty days is « the recent ones »
     * without being « all of them ».
     */
    private const SEEDS_WINDOW = 'P30D';

    private const DMARC_UNAVAILABLE = 'La lecture des rapports DMARC demande le module « Courrier entrant ».';

    /**
     * The window the screen reports on.
     *
     * Thirty days rather than « everything »: a source that stopped
     * sending three months ago is not a question anybody still has, and a
     * page that keeps answering it makes the one source that started
     * yesterday harder to see.
     */
    private const DMARC_WINDOW = 'P30D';

    /**
     * How many rows each table draws.
     *
     * A cap on the DRAWING, and on nothing else: a table of ten thousand
     * rows is not a screen, while a total or a verdict built on the rows
     * that fit is simply wrong. Both come from uncapped queries instead
     * ({@see \Core\Mail\Feedback\Dmarc\DmarcReportRepository::totalsSince()}),
     * and the page says when it is showing fewer than there are.
     */
    private const DMARC_SOURCES_SHOWN = 200;
    private const DMARC_REPORTS_SHOWN = 50;

    public const PROBE_URL = '/config/courrier-sortant/sonde';
    public const PROBE_SEND_URL = '/config/courrier-sortant/sonde/envoi';
    public const PROBE_VERDICT_URL = '/config/courrier-sortant/sonde/verdict';

    /** Said the same way wherever the probe cannot run at all. */
    private const PROBE_UNAVAILABLE = 'La sonde n’est pas disponible sur cette installation.';

    public const RETURN_CHECK_URL = '/config/courrier-sortant/authentification/verification';
    public const DNS_CHECK_URL = '/config/courrier-sortant/authentification/dns';
    public const DKIM_REGENERATE_URL = '/config/courrier-sortant/authentification/cle-dkim';

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
        private ?MailProbeRepository $probeHistory = null,
        /**
         * The bounce state (roadmap IT-05).
         *
         * Nullable like the probe above, and for a related but distinct
         * reason: the table is core and always readable, but a site whose
         * `inbound_mail` module is off never records a bounce, so a page
         * listing them would be a permanently empty screen promising a
         * feature that cannot work. Null makes the sub-page say so.
         */
        private ?\Core\Mail\Feedback\Bounce\BounceService $bounces = null,
        private ?\Core\Mail\Feedback\Bounce\BounceStateRepository $bounceHistory = null,
        /**
         * The DMARC reports (roadmap IT-06).
         *
         * Nullable on the same grounds as the bounce state: the tables are
         * core and always readable, but a site whose `inbound_mail` module
         * is off never receives a report, and a permanently empty page
         * saying « personne n'envoie en votre nom » would be the most
         * reassuring lie this section could tell.
         */
        private ?\Core\Mail\Feedback\Dmarc\DmarcReportRepository $dmarc = null,
        private ?\Core\Mail\Feedback\Dmarc\KnownSenders $knownSenders = null,
        /**
         * The seed mailboxes and their results (roadmap IT-07).
         *
         * Nullable on the same grounds as the bounce and DMARC pairs
         * above: the table is core and always readable, but a site whose
         * `inbound_mail` module is off has no boxes at all, and a page
         * offering to measure with nothing to measure into would be a
         * promise it cannot keep.
         */
        private ?\Core\Mail\Feedback\Seed\SeedMailboxes $seedMailboxes = null,
        private ?\Core\Mail\Feedback\Seed\SeedCopyRepository $seedCopies = null,
        /** What the results recommend, and whether it is applied (D13). */
        private ?\Core\Mail\Feedback\Seed\DomainRouting $routing = null,
        /**
         * What this unit's own SPF record authorises, as the last DNS check
         * read it (roadmap IT-06, issue #421).
         *
         * Last in the list rather than beside `$knownSenders`, where it
         * belongs by subject: every parameter here is positional, and
         * slipping one into the middle would silently hand each of the
         * five below somebody else's dependency.
         */
        private ?\Core\Mail\Feedback\Dmarc\SpfCoverage $spfCoverage = null
    ) {
    }

    /**
     * GET /config/courrier-sortant/dmarc — who sends in this unit's name,
     * and whether it authenticates (roadmap IT-06).
     *
     * @param array<string, string> $params
     */
    public function dmarc(Request $request, array $params): Response
    {
        // **One clock for the whole page.** The staleness of the SPF
        // reading is asked once per source line, and a page reading the
        // wall clock each time could place a source just inside the
        // boundary and refuse the next one just outside it — a table
        // disagreeing with itself about what it said one row earlier. No
        // test here can show that: it needs the thirty-day boundary to
        // fall between two rows of the same render. It is written down
        // because it is a reason, not because it is guarded.
        $now = new \DateTimeImmutable();
        $since = $now->sub(new \DateInterval(self::DMARC_WINDOW));
        // **The reading has to be about the domain the site signs for
        // today** (found in review on #571): changing the sending address
        // leaves the stored ranges belonging to the old domain, and for up to
        // thirty days the page would keep calling them « déclarée dans votre
        // SPF ». Asked once here and handed to both the rows and the page's
        // own sentence, so the two cannot disagree.
        $spfDomain = MailIdentity::fromSettings($this->settings)->spfDomain();
        $spfApplies = $this->spfCoverage !== null
            && !$this->spfCoverage->isEmpty()
            && $this->spfCoverage->isFor($spfDomain);
        $totals = $this->dmarc?->totalsSince($since) ?? [];

        return $this->render('config/outbound_mail/dmarc.html.twig', [
            'available' => $this->dmarc !== null,
            'unavailable_reason' => self::DMARC_UNAVAILABLE,
            'sources' => $this->dmarcSources($since, $now, $spfApplies),
            'reports' => $this->dmarc?->reportsSince($since, self::DMARC_REPORTS_SHOWN) ?? [],
            // **The counts come from the database, never from the rows
            // above**, which are capped for the table's sake. A page that
            // said « 200 sources » because it had drawn 200 rows would be
            // wrong in the one direction nobody checks.
            'total_sources' => $totals['sources'] ?? 0,
            'total_reports' => $totals['reports'] ?? 0,
            'shown_sources' => self::DMARC_SOURCES_SHOWN,
            'shown_reports' => self::DMARC_REPORTS_SHOWN,
            // Likewise: the warning is computed over every authenticating
            // source, not over the ones that fit.
            'unknown_authenticating' => $this->unknownAuthenticatingCount($since),
            'relays_resolved' => $this->knownSenders !== null && !$this->knownSenders->isEmpty(),
            // The SPF reading says three separate things, and the page owes
            // the operator all three (issue #421): never taken, taken but
            // too old to name anything, and taken but incomplete — with
            // which ceiling it ran into, because « votre chaîne dépasse la
            // limite du protocole » and « nous n'en gardons pas tant » are
            // different actions.
            'spf_resolved' => $this->spfCoverage !== null && !$this->spfCoverage->isEmpty(),
            // Named apart from « never taken », because the reading exists
            // and says so — it is simply about another domain, and the
            // operator's next step is the same button for a different reason.
            'spf_other_domain' => $this->spfCoverage !== null
                && !$this->spfCoverage->isEmpty()
                && !$this->spfCoverage->isFor($spfDomain)
                    ? $this->spfCoverage->domain
                    : null,
            'spf_stale' => $this->spfCoverage?->isStale($now) ?? false,
            // `->` and not `?->`: `$spfApplies` is only true when the
            // reading exists, which PHPStan reads off its definition above.
            'spf_partial' => $spfApplies ? $this->spfCoverage->partial : null,
            'spf_partial_lookups' => \Core\Mail\Feedback\Dmarc\SpfCoverage::PARTIAL_LOOKUPS,
            'spf_partial_unreadable' => \Core\Mail\Feedback\Dmarc\SpfCoverage::PARTIAL_UNREADABLE,
            'spf_max_lookups' => \Core\Mail\Feedback\Dmarc\SpfCoverage::MAX_LOOKUPS,
            'spf_max_age_days' => \Core\Mail\Feedback\Dmarc\SpfCoverage::MAX_AGE_DAYS,
            'window_days' => 30,
            'current_path' => self::DMARC_URL,
        ]);
    }

    /**
     * How many addresses the site cannot place are getting mail through.
     *
     * **Counted over every authenticating source**, which is the whole
     * reason this is not read off the table: the sentence it raises is
     * worth saying only if it cannot be missed, and one forgotten tool
     * sending forty messages sorts below two hundred noisier senders and
     * would vanish from the verdict along with its row.
     *
     * The repository streams them, so this counts without ever holding the
     * list — a ceiling here would put the same failure back, one order of
     * magnitude further away.
     */
    private function unknownAuthenticatingCount(\DateTimeImmutable $since): int
    {
        $unknown = 0;

        foreach ($this->dmarc?->authenticatingSourcesSince($since) ?? [] as $sourceIp) {
            if ($this->knownSenders?->nameFor($sourceIp) === null) {
                $unknown++;
            }
        }

        return $unknown;
    }

    /**
     * One line per sending address, with the two things a volunteer can
     * act on: is it ours, and did it authenticate.
     *
     * @return list<array<string, mixed>>
     */
    private function dmarcSources(\DateTimeImmutable $since, \DateTimeImmutable $now, bool $spfApplies): array
    {
        $lines = [];

        foreach ($this->dmarc?->sourcesSince($since, self::DMARC_SOURCES_SHOWN) ?? [] as $source) {
            $messages = $source['messages'];
            $authenticated = $source['authenticated'];
            $provider = $this->knownSenders?->nameFor($source['source_ip']);
            // **Only asked when no declared relay placed it**, which is
            // not a saving: a relay of the unit's own is also in its SPF,
            // so asking both would put « votre relais Brevo » and
            // « déclarée dans votre SPF » on the same row and leave the
            // volunteer to work out that they are the same fact.
            $spfVia = null;
            $spfIsOwnRecord = false;
            if ($provider === null && $spfApplies && $this->spfCoverage !== null) {
                $spfVia = $this->spfCoverage->viaFor($source['source_ip'], $now);
                $spfIsOwnRecord = $spfVia !== null && $this->spfCoverage->isOwnDomain($spfVia);
            }

            $lines[] = [
                // The sending SERVER's address, which is infrastructure
                // and names nobody — the whole reason an aggregate report
                // settles the RGPD question (D12).
                'source_ip' => $source['source_ip'],
                'provider' => $provider,
                'is_own' => $provider !== null,
                // The token the operator will find in their own zone, and
                // whether it is their own record stating a range itself
                // rather than something it delegates (issue #421).
                'spf_via' => $spfVia,
                'spf_is_own_record' => $spfIsOwnRecord,
                'messages' => $messages,
                'authenticated' => $authenticated,
                'failed' => $messages - $authenticated,
                'reporters' => $source['reporters'],
                // **The warning that avoids the classic trap.** An unknown
                // source that authenticates is almost always a forgotten
                // tool of the unit's own — an old registration platform, a
                // newsletter service, somebody's mailbox configured with
                // the unit's address — far more often than a spoof. It has
                // to be identified BEFORE moving to `p=reject`, or those
                // messages are rejected too.
                //
                // **An SPF match does not clear it** (issue #421). A range
                // in the unit's own record is a range somebody authorised,
                // which says nothing about whether they still want to —
                // and « je l'ai mis dans le SPF il y a trois ans » is the
                // commonest way a forgotten tool got there. Naming it
                // tells the volunteer where to look, not that the answer
                // is fine.
                'needs_attention' => $provider === null && $authenticated > 0,
            ];
        }

        return $lines;
    }

    /**
     * GET /config/courrier-sortant/rebonds — which addresses refuse this
     * unit's mail, and what the unit can do about it (roadmap IT-05).
     *
     * @param array<string, string> $params
     */
    public function bounces(Request $request, array $params): Response
    {
        return $this->renderBounces();
    }

    // ── Boîtes témoins (roadmap IT-07) ────────────────────────────────

    /**
     * GET .../temoins — where the mailings landed, per provider.
     *
     * @param array<string, string> $params
     */
    public function seeds(Request $request, array $params): Response
    {
        $since = (new \DateTimeImmutable())->sub(new \DateInterval(self::SEEDS_WINDOW));
        $addresses = $this->seedMailboxes?->addresses() ?? [];
        $runs = $this->seedRuns($since);

        return $this->render('config/outbound_mail/seeds.html.twig', [
            'available' => $this->seedMailboxes !== null && $this->seedCopies !== null,
            'unavailable_reason' => self::SEEDS_UNAVAILABLE,
            'enabled' => $this->seedMailboxes?->isEnabled() ?? false,
            // The COUNT and not the addresses. A seed box is the unit's
            // own, so naming one would tell a reader nothing they need and
            // put a mailbox address on a screen a screenshot can carry
            // (SECURITY.md §11). What the operator acts on is « combien »,
            // and the providers already show up as the result columns.
            'box_count' => count($addresses),
            // The blind spot the default configuration has, counted so
            // the screen can name it before the reader trusts a figure it
            // makes wrong.
            'blind_boxes' => $this->seedMailboxes?->boxesBlindToSpam() ?? 0,
            'suggested_maximum' => \Core\Mail\Feedback\Seed\SeedMailboxes::SUGGESTED_MAXIMUM,
            'providers' => $this->seedProviders($addresses),
            // **The columns come from the measurements, not from the
            // boxes.** A box removed from the scope — or a module that
            // cannot answer right now — must not make thirty days of
            // results vanish from the page while the routing below goes
            // on acting on them.
            'columns' => $this->seedColumns($addresses, $runs),
            'runs' => $runs,
            'window_days' => 30,
            // **The recommendation, and the fact that it is one** (D13).
            // Shown beside the results rather than acted on: with three to
            // five boxes and a few mailings a year, routing on two
            // observations is routing on noise, and splitting a sender's
            // volume costs each relay the regular traffic its standing
            // rests on. The automatism exists, behind a switch and a
            // minimum sample, and the screen says which of the two it is
            // looking at.
            'readings' => $this->routing?->readings($since) ?? [],
            'routing_automatic' => $this->routing?->isAutomatic() ?? false,
            'minimum_runs' => \Core\Mail\Feedback\Seed\DomainRouting::MINIMUM_RUNS,
            // Two relays on the mailing lane is what makes « appliquer »
            // mean anything. Below that the screen says so rather than
            // drawing a button that would explain nothing when it did
            // nothing: a unit with one relay answers a provider filtering
            // its mail by changing what it sends, not where from.
            'bulk_relays' => count($this->routing?->bulkChain() ?? []),
            'current_path' => self::SEEDS_URL,
            'inbound_url' => '/config/courrier-entrant',
        ]);
    }

    /**
     * POST .../temoins/activation — the super-admin turns the copies on
     * or off.
     *
     * **Journalled at `security`, which the roadmap asks for by name.**
     * It is not a cosmetic setting: switching it on starts putting a copy
     * of every mailing — real members' data — into every declared box, and
     * switching it off stops a measurement somebody may be relying on.
     * Both directions are decisions worth being able to date afterwards.
     *
     * @param array<string, string> $params
     */
    public function toggleSeeds(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::SEEDS_URL)) !== null) {
            return $guard;
        }

        if ($this->seedMailboxes === null) {
            FlashMessage::set('error', self::SEEDS_UNAVAILABLE);

            return $this->redirect(self::SEEDS_URL);
        }

        $wanted = (string) $request->getBody('enabled', '0') === '1';
        $this->settings->setInternal(
            \Core\Mail\Feedback\Seed\SeedMailboxes::SETTING_ENABLED,
            $wanted ? '1' : '0'
        );

        $this->journal->log(
            'core',
            'mail_seed_boxes_toggled',
            'security',
            $wanted ? 'Copies vers les boîtes témoins activées' : 'Copies vers les boîtes témoins désactivées',
            // The count, never the addresses — the same rule the screen
            // itself follows.
            ['enabled' => $wanted, 'boxes' => count($this->seedMailboxes->addresses())]
        );

        FlashMessage::set(
            'success',
            $wanted
                ? 'Les prochains publipostages seront aussi envoyés à vos boîtes témoins.'
                : 'Les publipostages ne sont plus copiés vers les boîtes témoins.'
        );

        return $this->redirect(self::SEEDS_URL);
    }

    /**
     * POST .../temoins/routage — apply, or undo, the recommendation for
     * one recipient domain (D13).
     *
     * **A button, not an automatism**, which is what D13 turns on: the
     * page shows the finding and a person decides, because splitting a
     * sender's volume costs each relay the regular traffic its reputation
     * rests on and that price is not the site's to pay unasked.
     *
     * Journalled at `security` like every other change to how mail leaves
     * this site. A recipient domain is a mail provider, not a person —
     * « gmail.com » names a company the way « Brevo » does — so both ends
     * of the decision are named and the line is worth reading afterwards.
     *
     * @param array<string, string> $params
     */
    public function routeSeeds(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::SEEDS_URL)) !== null) {
            return $guard;
        }

        if ($this->routing === null) {
            FlashMessage::set('error', self::SEEDS_UNAVAILABLE);

            return $this->redirect(self::SEEDS_URL);
        }

        $domain = strtolower(trim((string) $request->getBody('domain', '')));
        $undo = (string) $request->getBody('undo', '0') === '1';

        // Checked here as well as at the writer so the two failures do
        // not share one message: « aucun autre relais » and « ceci n'est
        // pas un domaine » send somebody looking in different places, and
        // a form posting the second means the page was tampered with
        // rather than misconfigured.
        if (!\Core\Mail\Transport\DomainPreferences::isPlausibleDomain($domain)) {
            FlashMessage::set('error', 'Ce n\'est pas un nom de domaine.');

            return $this->redirect(self::SEEDS_URL);
        }

        if ($undo) {
            if ($this->routing->clear($domain)) {
                $this->journalRouting($domain, null);
                FlashMessage::set('success', 'Ce fournisseur repasse par l\'ordre habituel de la voie masse.');
            }

            return $this->redirect(self::SEEDS_URL);
        }

        $moved = $this->routing->apply($domain);
        if ($moved === null) {
            FlashMessage::set(
                'error',
                'Aucun autre relais disponible sur la voie masse : il n\'y a nulle part où router ces envois.'
            );

            return $this->redirect(self::SEEDS_URL);
        }

        $this->journalRouting($domain, $moved->name);
        FlashMessage::set(
            'success',
            'Les publipostages vers ce fournisseur partiront d\'abord par « ' . $moved->name . ' ».'
        );

        return $this->redirect(self::SEEDS_URL);
    }

    /**
     * POST .../temoins/routage-automatique — the second lock of D13.
     *
     * **The switch is explicit and it stays off until somebody says
     * otherwise.** With it on, the daily sweep applies the recommendation
     * for a provider that has crossed the minimum sample — once per
     * domain, never undoing — so the two locks the roadmap asks for are
     * both in force: this switch, and
     * `DomainRouting::MINIMUM_RUNS`.
     *
     * @param array<string, string> $params
     */
    public function toggleRouting(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::SEEDS_URL)) !== null) {
            return $guard;
        }

        // **Unavailable like its two siblings**, rather than falling
        // through to a nullsafe chain that would read « no module » as
        // « nothing wrong ». `toggleSeeds()` and `routeSeeds()` refuse
        // here; this one did not, so on an installation without
        // `inbound_mail` the switch armed itself silently.
        if ($this->seedMailboxes === null || $this->routing === null) {
            FlashMessage::set('error', self::SEEDS_UNAVAILABLE);

            return $this->redirect(self::SEEDS_URL);
        }

        $wanted = (string) $request->getBody('enabled', '0') === '1';

        // **An automatism may not be armed on a measurement that cannot
        // support it.** A seed box watching only its inbox cannot tell
        // « indésirables » from « jamais arrivé » — it reports the second
        // for both — and that is precisely the difference this switch
        // would have the site act on, unattended, by moving a whole
        // provider's mail to another relay. No box at all is the same
        // answer for a simpler reason. Refused rather than warned about:
        // the screen already warns, and a switch that takes a decision no
        // one will re-read afterwards is the one place where a warning is
        // not enough. Turning it OFF is always allowed.
        if ($wanted && !$this->seedMailboxes->measuresSpamReliably()) {
            $blind = $this->seedMailboxes->boxesBlindToSpam();
            FlashMessage::set(
                'error',
                $blind > 0
                    ? $blind . ' boîte(s) témoin(s) ne surveille(nt) pas leur dossier « Indésirables » : '
                        . 'un message classé en indésirables y est compté « jamais arrivé ». '
                        . 'Ajoutez ce dossier dans « Courrier entrant » avant d\'automatiser le routage.'
                    : 'Aucune boîte témoin ne peut mesurer quoi que ce soit pour l\'instant : '
                        . 'déclarez-en dans « Courrier entrant », avec leur dossier « Indésirables », '
                        . 'avant d\'automatiser le routage.'
            );

            return $this->redirect(self::SEEDS_URL);
        }

        $this->settings->setInternal(
            \Core\Mail\Feedback\Seed\DomainRouting::SETTING_AUTOMATIC,
            $wanted ? '1' : '0'
        );

        $this->journal->log(
            'core',
            'mail_seed_routing_automatic_toggled',
            'security',
            $wanted ? 'Routage par domaine automatique activé' : 'Routage par domaine automatique désactivé',
            ['enabled' => $wanted]
        );

        FlashMessage::set(
            'success',
            $wanted
                ? 'Le site appliquera de lui-même ce que les boîtes témoins recommandent.'
                : 'Le site se contente désormais d\'afficher la recommandation.'
        );

        return $this->redirect(self::SEEDS_URL);
    }

    /** One shape for both directions, so neither can drift from the other. */
    private function journalRouting(string $domain, ?string $relay): void
    {
        $this->journal->log(
            'core',
            'mail_seed_routing_changed',
            'security',
            $relay === null ? 'Routage par domaine retiré' : 'Routage par domaine appliqué',
            ['domain' => $domain, 'relay' => $relay]
        );
    }

    /**
     * The providers the unit is measuring with, deduplicated.
     *
     * The columns of the results table, and the one thing about a seed
     * box that belongs on a screen: « gmail.com » names a company, an
     * address names a mailbox.
     *
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    private function seedProviders(array $addresses): array
    {
        $providers = [];
        foreach ($addresses as $address) {
            $providers[\Core\Mail\Feedback\Seed\SeedCopy::providerOf($address)] = true;
        }

        $names = array_keys($providers);
        sort($names);

        return $names;
    }

    /**
     * The columns of the results table: every provider the unit measures
     * with **and** every provider it has measured.
     *
     * The two are usually the same list, and the one case where they
     * differ is the one that matters. `addresses()` answers about the
     * boxes as they are *now*, and it answers `[]` rather than throwing
     * when the module cannot be reached — so deriving the columns from it
     * alone meant that removing one box, or a momentary failure to
     * resolve `inbound_mail`, silently emptied a table whose rows were
     * still there. The page would show « aucun résultat » for a period it
     * had results for, while {@see DomainRouting} below went on
     * recommending from those very rows.
     *
     * @param list<string> $addresses
     * @param list<array{reference: string, sent_at: string, cells: array<string, mixed>}> $runs
     *
     * @return list<string>
     */
    private function seedColumns(array $addresses, array $runs): array
    {
        $columns = [];
        foreach ($this->seedProviders($addresses) as $provider) {
            $columns[$provider] = true;
        }

        foreach ($runs as $run) {
            foreach (array_keys($run['cells']) as $provider) {
                $columns[(string) $provider] = true;
            }
        }

        $names = array_keys($columns);
        sort($names);

        return $names;
    }

    /**
     * One row per mailing, one cell per provider — the shape the roadmap
     * asks for.
     *
     * @return list<array{
     *     reference: string,
     *     sent_at: string,
     *     cells: array<string, array{verdict: string, label: string, badge: string, folder: ?string}>
     * }>
     */
    private function seedRuns(\DateTimeImmutable $since): array
    {
        $rows = [];

        foreach ($this->seedCopies?->runsSince($since) ?? [] as $reference => $copies) {
            $cells = [];
            $sentAt = null;
            foreach ($copies as $copy) {
                $sentAt ??= $copy->sentAt;
                $cells[$copy->provider] = [
                    'verdict' => $copy->verdict->value,
                    'label' => $copy->verdict->label(),
                    'badge' => $copy->verdict->badge(),
                    'folder' => $copy->landedFolder,
                ];
            }

            $rows[] = [
                'reference' => (string) $reference,
                'sent_at' => ($sentAt ?? new \DateTimeImmutable())->format('d/m/Y à H:i'),
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * POST .../rebonds/{id}/reprise — the super-admin lifts a block.
     *
     * **Legitimate for exactly the reason D19 gives**: the site placed
     * this block, so the site may lift it. It is emphatically NOT a way
     * round `MemberEmailService::isOwnMember()` — a super-admin still
     * cannot reactivate an address a parent switched off, which is the
     * parent's decision and stays theirs. Necessary in practice, because
     * a great many parents never sign in.
     *
     * @param array<string, string> $params
     */
    public function unblockBounce(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::BOUNCES_URL)) !== null) {
            return $guard;
        }

        if ($this->bounces === null) {
            FlashMessage::set('error', self::BOUNCES_UNAVAILABLE);

            return $this->redirect(self::BOUNCES_URL);
        }

        if (!$this->bounces->unblock((int) ($params['id'] ?? 0), false)) {
            FlashMessage::set('error', 'Cette adresse n’est plus dans la liste.');

            return $this->redirect(self::BOUNCES_URL);
        }

        FlashMessage::set(
            'success',
            'Adresse remise en service. Si elle refuse à nouveau nos messages, elle sera suspendue de nouveau.'
        );

        return $this->redirect(self::BOUNCES_URL);
    }

    private function renderBounces(): Response
    {
        return $this->render('config/outbound_mail/bounces.html.twig', [
            'available' => $this->bounces !== null,
            'unavailable_reason' => self::BOUNCES_UNAVAILABLE,
            'blocked' => $this->bounceLines($this->bounceHistory?->blocked() ?? []),
            'domains' => $this->bounceDomains(),
            'unblock_url' => self::BOUNCE_UNBLOCK_URL,
            'current_path' => self::BOUNCES_URL,
        ]);
    }

    /**
     * @param list<\Core\Mail\Feedback\Bounce\BounceState> $states
     * @return list<array<string, mixed>>
     */
    private function bounceLines(array $states): array
    {
        $lines = [];
        foreach ($states as $state) {
            $lines[] = [
                'id' => $state->id,
                // The address IS shown here, and only here: a super-admin
                // lifting a block has to know which one they are lifting,
                // and this screen is behind the highest role the site has.
                // It stays out of the journal, the notifications and the
                // support archive all the same (SECURITY.md §11).
                'email' => $state->email,
                'category' => $state->category->label(),
                'guidance' => $state->category->guidance(),
                'failures' => $state->failures,
                'since' => $state->blockedAt?->format('d/m/Y') ?? $state->lastSeenAt->format('d/m/Y'),
            ];
        }

        return $lines;
    }

    /**
     * Refusals per recipient domain, which is the shape a pattern shows
     * up in: one address failing is a family's mailbox, ten at the same
     * provider is that provider refusing this unit.
     *
     * @return list<array{domain: string, refused: int}>
     */
    private function bounceDomains(): array
    {
        $counts = [];
        foreach ($this->bounceHistory?->blocked() ?? [] as $state) {
            $at = strrpos($state->email, '@');
            if ($at === false) {
                continue;
            }

            $domain = substr($state->email, $at + 1);
            $counts[$domain] = ($counts[$domain] ?? 0) + 1;
        }

        arsort($counts);

        $rows = [];
        foreach ($counts as $domain => $refused) {
            $rows[] = ['domain' => (string) $domain, 'refused' => $refused];
        }

        return $rows;
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
            $revived = $this->queue->relaunch(
                $lanes,
                (string) $request->getBody('window', DeferredMailQueue::DEFAULT_WINDOW)
            );
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
     * Mints a fresh DKIM key pair, and forgets what the DNS last said.
     *
     * **Here rather than on « Installation & serveur »** (issue #336). That
     * page carried a checkbox to do it, and it is the wrong page for the
     * one reason that matters: it cannot tell the operator whether the DNS
     * record has caught up, so a key rotated from there left the site
     * failing DKIM with nothing on screen to say why. This page shows the
     * public key, proposes the record, and checks it live — so it is the
     * page where rotating is a decision rather than a leap.
     *
     * Two screens for one action was the other half of the problem, so the
     * checkbox went in the same change: there is now exactly one place.
     *
     * Forgetting the remembered reading is not optional and not cosmetic:
     * a stored reading holds the key it was taken against, and nothing in
     * it can name the key in use NOW — so a rotation leaves a reading that
     * looks current and describes a key that is gone. See
     * `Tests\Architecture\DkimKeyChangeForgetsDnsTest`, which holds every
     * place that touches the key to doing this.
     *
     * @param array<string, string> $params
     */
    public function regenerateDkimKey(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::AUTHENTICATION_URL)) !== null) {
            return $guard;
        }

        try {
            // Replaced, not deleted-then-generated. The old shape left the
            // site with NO key between the two calls and after any failure
            // of the second (issue #547); this one either swaps the pair or
            // changes nothing.
            $this->dkim->replaceKey();
        } catch (\Throwable $e) {
            // The old key is STILL IN PLACE and still signing, which is the
            // whole difference from the previous shape and is why the
            // sentence below reassures instead of warning. Whatever OpenSSL
            // says here is English and technical, so it goes through
            // UserFacingMessage::from() like its sibling in
            // SetupController::generateDkimKey().
            $this->journal->log(
                'core',
                'dkim_key_regeneration_failed',
                'security',
                'Échec de la régénération de la clé DKIM',
                ['selector' => $this->dkimSelector(), 'error' => $e->getMessage()],
                AuthSession::getUserAccountId()
            );

            $message = UserFacingMessage::from(
                $e,
                'La nouvelle clé DKIM n’a pas pu être générée. L’ancienne reste en service et les messages '
                    . 'du site sont toujours signés : rien n’est à republier dans le DNS. Vérifiez que '
                    . 'l’extension OpenSSL est active et que le dossier storage/ est accessible en écriture, '
                    . 'puis relancez la génération.'
            );
            FlashMessage::set('error', $message);

            return $this->redirect(self::AUTHENTICATION_URL);
        }

        // Forgotten HERE, AFTER the swap, and the order is the reverse of
        // what it was — deliberately. While the replacement had not landed,
        // the old key was still in service and its remembered DNS reading
        // was still exact; throwing it away on the failing path would have
        // discarded a true reading and told the operator to republish a
        // record that never changed. Only a swap that succeeded makes the
        // reading describe a key that has left service.
        //
        // `Tests\Architecture\DkimKeyChangeForgetsDnsTest` reads the whole
        // method and therefore accepts either order: it holds « somebody
        // forgets the reading here », never « at the right moment ». The
        // order is pinned instead by the two tests in
        // OutboundMailControllerTest that bracket it — a failed rotation
        // keeps the reading, a successful one loses it.
        DnsCheckMemory::forget($this->settings);

        $this->journal->log(
            'core',
            'dkim_key_regenerated',
            'security',
            'Clé DKIM régénérée',
            // No key material, no fingerprint: the public half is on the
            // page and the private half is never written anywhere a reader
            // of the journal can reach.
            ['selector' => $this->dkimSelector()],
            AuthSession::getUserAccountId()
        );

        FlashMessage::set(
            'success',
            'Nouvelle clé DKIM générée. Publiez l’enregistrement DNS ci-dessous : tant qu’il porte l’ancienne '
                . 'valeur, les messages du site échouent à DKIM.'
        );

        return $this->redirect(self::AUTHENTICATION_URL);
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

        // **The relays are resolved here and nowhere else** (roadmap
        // IT-06). This action is where the site is allowed to block on a
        // resolver — behind a button, behind a CSRF token, with somebody
        // watching — and the « Rapports DMARC » page then renders the
        // answer instead of taking it. Taking it on that page's render was
        // the first version, and it put two blocking lookups per relay in
        // front of a screen people open when mail is already broken.
        try {
            \Core\Mail\Feedback\Dmarc\KnownSenders::refresh(
                $this->settings,
                array_values($this->directory->relays())
            );
        } catch (\Throwable) {
            // Silent, and deliberately not a `forget()`: the reading this
            // would drop still places the relays correctly, and losing it
            // turns « votre relais » into « autre » on the next page — the
            // mislabelling that matters. The SPF/DKIM/DMARC reading above
            // is what the operator pressed the button for; this rides
            // along with it and must not be able to spoil it.
        }

        // **The unit's own SPF chain, in a try of its own** (issue #421).
        // Sharing the one above would let a resolver that fails on a relay
        // hostname cost the include: reading as well, and the two answer
        // different questions from different records — one would be lost
        // for the other's bad minute.
        try {
            \Core\Mail\Feedback\Dmarc\SpfCoverage::refresh(
                $this->settings,
                $spfDomain,
                // **Through the verifier, which is this screen's one
                // resolver** (found in review on #571). Reading TXT records
                // from inside `SpfCoverage` gave the outbound-mail screens a
                // second way to reach the network, and left this action's own
                // test asking a real resolver for a domain its canned zone
                // already answers for.
                //
                // **`?array`, and the question mark is load-bearing.** The
                // walk distinguishes « this host publishes nothing » from
                // « nobody answered » and marks the reading incomplete for the
                // second; a closure declared `: array` could never hand back
                // the null that says so, which made that branch — and the
                // warning the page had just gained — dead in production while
                // the unit test that injects its own closure kept passing.
                fn(string $host): ?array => $this->dns->txtRecordsFor($host)
            );
        } catch (\Throwable) {
            // As above: the previous reading still places what it placed,
            // and dropping it would turn « déclarée dans votre SPF » into
            // « Autre » on the next page.
        }

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

        // **`0` is not a safe default here**, and it is the one field on
        // this controller where that is true: `MailProvider::LOCAL_ID` IS
        // zero, and the local send is unconditionally usable, so
        // `(int) (… ?? 0)` on a missing or non-numeric value would resolve
        // to a real relay instead of being refused — and the probe would
        // quietly go out through the server's own `mail()` while the
        // recorded row named it. That is the opposite of pinning the
        // relay the operator chose, which is the feature.
        $providerId = $request->getBody('provider_id');
        if (!is_string($providerId) || !ctype_digit($providerId)) {
            FlashMessage::set('error', 'Choisissez le fournisseur par lequel envoyer la sonde.');

            return $this->redirect(self::PROBE_URL);
        }

        try {
            $probe = $this->probes->send(
                (string) ($request->getBody('destination') ?? ''),
                (int) $providerId,
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
     * `bounce` is the one field that can be filled while `verdict` is not,
     * and the page shows both (issue #419). They answer different questions:
     * the verdict is what a person saw in the mailbox, the bounce is what the
     * far end said before there was anything to see. « Jamais reçu » next to
     * « Adresse inexistante (5.1.1) » is not a contradiction — it is the
     * operator's observation and its reason, and a page that showed only one
     * would drop the half somebody came for.
     *
     * @param list<\Core\Mail\Probe\MailProbe> $probes
     * @return list<array{id: int, code: string, destination: string, provider: string, lane: string,
     *     sent_at: string, verdict: ?string, verdict_label: ?string, verdict_badge: ?string,
     *     guidance: ?string, bounce: ?string, bounce_at: ?string}>
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
                'bounce' => $probe->bounce?->label(),
                'bounce_at' => $probe->bounce?->at->format('d/m/Y à H:i'),
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
            FlashMessage::set(
                'error',
                'Aucun message de vérification n’a pu partir. Regardez la page « Fournisseurs ».'
            );
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
            'dkim_regenerate_url' => self::DKIM_REGENERATE_URL,
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
