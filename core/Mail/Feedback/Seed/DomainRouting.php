<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed;

use Core\Config\SettingService;

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

    public function __construct(private SeedCopyRepository $copies, private SettingService $settings)
    {
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
     *     enough: bool, troubled: bool, verdict: string}>
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

            $readings[] = [
                'provider' => $row['provider'],
                'runs' => $row['runs'],
                'inbox' => $row['inbox'],
                'spam' => $row['spam'],
                'missing' => $row['missing'],
                'enough' => $enough,
                'troubled' => $troubled,
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
