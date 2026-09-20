<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed;

use Core\Config\SettingService;
use Core\Mail\Transport\DomainPreferences;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;

/**
 * What the seed results suggest about which relay should carry which
 * provider's mail — **a recommendation, not an automatism** (D13,
 * roadmap IT-07).
 *
 * **Why it is a recommendation, spelled out because the code reads as if
 * it should just act.** With three to five seed boxes and a few mailings
 * a year, routing on two observations is routing on noise: one campaign
 * whose wording happened to trip a filter would move a whole provider's
 * traffic to another relay on the strength of a single data point.
 *
 * **And splitting a sender's volume has a cost of its own.** A relay's
 * reputation is built on regular, predictable traffic; sending half of it
 * elsewhere gives each relay less of exactly what its standing depends
 * on. So the remedy can be worse than the fault it answers, which is why
 * a person decides.
 *
 * The automatism therefore exists behind **two** locks: an explicit
 * switch, and a minimum sample. Neither alone is enough, and with the
 * switch off — which is how every installation starts — this class only
 * ever answers a question the screen asked.
 */
final class DomainRouting
{
    /** Registered `editable: false`: the switch is a page, not a text field. */
    public const SETTING_AUTOMATIC = 'mail_seed_routing_automatic';

    /**
     * How many measured mailings a provider needs before its figures mean
     * anything.
     *
     * **Five, and the number is the whole guard.** Below it, one unlucky
     * campaign is a majority of the evidence. It is deliberately counted
     * in MAILINGS rather than in copies: five copies of one mailing to
     * five boxes say one thing five times, and a sample that counts them
     * as five would be the noise this constant exists to refuse.
     */
    public const MINIMUM_RUNS = 5;

    /**
     * The share of copies that must miss the inbox before a provider is
     * worth mentioning at all.
     *
     * A third: below that, a provider filing the occasional mailing as
     * spam is ordinary, and a screen flagging it would train its reader
     * to ignore the flag.
     */
    public const TROUBLE_RATIO = 0.34;

    /**
     * The mailing chain, read once. Null is « not read yet », which is
     * why it is not simply an empty array: a unit with no chain at all is
     * a real state and must not be re-read on every row.
     *
     * @var list<MailProvider>|null
     */
    private ?array $bulkChain = null;

    public function __construct(
        private SeedCopyRepository $copies,
        private SettingService $settings,
        /**
         * Where a decision is written down. Null is « this reading is
         * only being displayed » — the screen's first job, and the one
         * that needs no transport at all.
         */
        private ?DomainPreferences $preferences = null,
        private ?LaneChainRepository $chains = null,
        private ?MailProviderDirectory $directory = null
    ) {
    }

    /**
     * Whether the unit has asked for the recommendation to be applied by
     * itself. **False everywhere until somebody says otherwise.**
     */
    public function isAutomatic(): bool
    {
        return (string) ($this->settings->get(self::SETTING_AUTOMATIC) ?? '0') === '1';
    }

    /**
     * What the results say, provider by provider.
     *
     * Every provider measured is returned, including the ones that are
     * fine: a screen that only listed problems would leave a reader unable
     * to tell « nothing wrong » from « nothing measured », which are very
     * different answers and the second is the one that needs acting on.
     *
     * @return list<array{provider: string, runs: int, inbox: int, spam: int, missing: int,
     *     enough: bool, troubled: bool, routed_to: ?string, alternative: ?string, verdict: string}>
     */
    public function readings(\DateTimeImmutable $since): array
    {
        $readings = [];

        foreach ($this->copies->tallyByProviderSince($since) as $row) {
            // Answered copies only: a `pending` one is not evidence yet,
            // and counting it either way would make the ratio move as the
            // sweep runs rather than as delivery changes.
            $answered = $row['inbox'] + $row['spam'] + $row['missing'];
            // **The sample is the number of MAILINGS**, never the number
            // of copies: five boxes at one provider on one mailing are one
            // observation repeated five times, and counting them as five
            // would cross this threshold on a single campaign. A test
            // caught the first version doing exactly that.
            $enough = $row['runs'] >= self::MINIMUM_RUNS;
            $offInbox = $row['spam'] + $row['missing'];
            // `$enough` means at least MINIMUM_RUNS distinct mailings were
            // answered, so there is at least one answered copy and the
            // division is safe.
            $troubled = $enough && $answered > 0 && ($offInbox / $answered) >= self::TROUBLE_RATIO;

            $current = $this->preferences?->forDomain($row['provider']);
            $alternative = $this->alternativeFor($row['provider']);

            $readings[] = [
                'provider' => $row['provider'],
                'runs' => $row['runs'],
                'inbox' => $row['inbox'],
                'spam' => $row['spam'],
                'missing' => $row['missing'],
                'enough' => $enough,
                'troubled' => $troubled,
                // What is decided today, and what applying would decide
                // instead. Both are names rather than ids: the screen
                // shows them, and an id on a screen is a number nobody
                // can check against anything.
                'routed_to' => $current === null ? null : $this->nameOf($current),
                'alternative' => $alternative?->name,
                'verdict' => match (true) {
                    !$enough => 'Pas assez d’envois mesurés pour conclure',
                    $troubled => 'Ce fournisseur écarte une part notable de vos envois',
                    default => 'Rien à signaler',
                },
            ];
        }

        return $readings;
    }

    /**
     * The relay applying would move this domain's mailings to, or null
     * when there is nowhere to move them.
     *
     * **Null is a real and common answer**, and the screen has to say so
     * rather than offer a button that does nothing: a unit with one relay
     * on the mailing lane — which is most units — has no alternative at
     * all, and its answer to a provider filtering its mail is to fix what
     * it sends, not where it sends it from.
     *
     * The alternative is the next enabled entry of the mailing chain
     * after whichever relay this domain would use today. Lane order is
     * the operator's own stated preference order, so « the next one » is
     * the choice they have already expressed rather than a second ranking
     * invented here.
     */
    public function alternativeFor(string $domain): ?MailProvider
    {
        $chain = $this->bulkChain();
        if (count($chain) < 2) {
            return null;
        }

        $currentId = $this->preferences?->forDomain($domain);
        $index = 0;
        if ($currentId !== null) {
            foreach ($chain as $position => $provider) {
                if ($provider->id === $currentId) {
                    $index = $position;
                    break;
                }
            }
        }

        // Wrapping rather than stopping at the end: a unit with two
        // relays that applied once must be able to apply again and come
        // back, otherwise the button is a one-way door.
        return $chain[($index + 1) % count($chain)];
    }

    /**
     * Route this domain's mailings to the alternative, and say which one
     * it was.
     *
     * Null when nothing happened — no alternative, or no place to write
     * the decision — so a caller journals a change and stays silent about
     * a no-op.
     */
    public function apply(string $domain): ?MailProvider
    {
        $alternative = $this->alternativeFor($domain);
        if ($alternative === null || $this->preferences === null) {
            return null;
        }

        return $this->preferences->prefer($domain, $alternative->id) ? $alternative : null;
    }

    /** Back to the mailing lane's own order for this domain. */
    public function clear(string $domain): bool
    {
        return $this->preferences?->forget($domain) ?? false;
    }

    /**
     * Every enabled relay of the mailing lane, in the operator's order.
     *
     * Configuration, not runtime: a relay out of quota or behind an open
     * breaker is still in this list, because this answers « where could
     * this domain's mail go » and not « where can this message go right
     * now ». The second question belongs to `MailTransportChain`, which
     * asks it per message and would give a different answer ten minutes
     * later — an unstable answer is not something to put on a screen or
     * to write into a setting.
     *
     * @return list<MailProvider>
     */
    public function bulkChain(): array
    {
        // Read once per instance: `readings()` asks for it on every row,
        // and a lane chain that changed between two rows of one table
        // would make the table disagree with itself.
        if ($this->bulkChain !== null) {
            return $this->bulkChain;
        }

        if ($this->chains === null || $this->directory === null) {
            return $this->bulkChain = [];
        }

        try {
            $entries = $this->chains->forLane(MailLane::Bulk);
            $providers = $this->directory->all();
        } catch (\Throwable) {
            return $this->bulkChain = [];
        }

        $chain = [];
        foreach ($entries as $entry) {
            if (!$entry->enabled) {
                continue;
            }

            $provider = $providers[$entry->providerId] ?? null;
            if ($provider !== null) {
                $chain[] = $provider;
            }
        }

        return $this->bulkChain = $chain;
    }

    /**
     * What a preferred relay is called, or the id when the relay it names
     * has since been deleted.
     *
     * A stale preference is left readable rather than quietly dropped:
     * « relais nº 7 (supprimé) » on the screen is what tells somebody to
     * clear it, and a blank cell is what makes them believe there is
     * nothing to clear. The send path ignores it anyway — a relay absent
     * from the lane is absent from the candidates.
     */
    private function nameOf(int $providerId): string
    {
        foreach ($this->bulkChain() as $provider) {
            if ($provider->id === $providerId) {
                return $provider->name;
            }
        }

        return 'relais nº ' . $providerId . ' (supprimé)';
    }

    /**
     * Whether anything at all should be proposed to the operator.
     *
     * Deliberately narrow: a recommendation shown on a page where nothing
     * is wrong is a recommendation nobody reads on the day something is.
     */
    public function hasRecommendation(\DateTimeImmutable $since): bool
    {
        foreach ($this->readings($since) as $reading) {
            if ($reading['troubled']) {
                return true;
            }
        }

        return false;
    }
}
