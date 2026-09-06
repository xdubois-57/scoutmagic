<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Journal\JournalService;
use Core\Net\DomainName;
use Core\Net\WhoisClient;
use Core\Net\WhoisLookup;
use Core\Net\WhoisRegistration;
use Core\Service\DateInput;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;

/**
 * Keeps the receiver's idea of who registered each installation's domain
 * up to date, from the daily report.
 *
 * **Why the daily report is the trigger.** It is the one moment the
 * receiver is already thinking about that installation, and it is exactly
 * as often as anybody needs: a registration changes at most once a year.
 * There is no task to seed, no queue to drain, and an installation that
 * stops reporting stops being asked about — which is the right answer,
 * since it is also the moment its row starts ageing out.
 *
 * **A report is not a query.** A WHOIS lookup on every report would be one
 * per installation per day, at registries that rate-limit by source
 * address and answer a blocked caller with silence — so the diagnostic
 * would stop working precisely on the fleet large enough to need it. What
 * happens on every report is a date comparison; what happens once a month
 * is a query.
 *
 * **Three states, and the third is not a softer failure.** `found`, the
 * registry answered about the name. `not_found`, the registry answered and
 * the name is registered nowhere — a real and interesting answer, since a
 * site answering on a domain nobody has registered is a hijack or a lapse.
 * `unavailable`, nobody answered: port 43 closed on this host, a TLD with
 * no WHOIS service, a registry that was down. Reading the third as the
 * second would put « domaine non enregistré » on a page about a unit whose
 * domain is fine.
 */
class DomainRegistrationRefresher
{
    /**
     * How long a registration is believed.
     *
     * Thirty days. A registration changes when somebody renews or
     * transfers it, which is a yearly act, and the field that moves
     * fastest — the expiry date — is interesting weeks before it matters,
     * not hours.
     */
    public const TTL_DAYS = 30;

    /**
     * How long a failure is believed.
     *
     * One day, not thirty. A registry that was down this morning is a
     * different thing from a registration that has not changed, and
     * freezing « indisponible » for a month over one bad afternoon is how
     * a diagnostic quietly stops being one.
     */
    public const RETRY_AFTER_FAILURE_DAYS = 1;

    /**
     * The stored status is the lookup's own outcome, verbatim. Aliased
     * here so a caller reading this class does not have to go and find out
     * where the vocabulary is defined.
     */
    public const STATUS_FOUND = WhoisLookup::FOUND;
    public const STATUS_NOT_FOUND = WhoisLookup::NOT_FOUND;
    public const STATUS_UNAVAILABLE = WhoisLookup::UNAVAILABLE;

    public function __construct(
        private SupportInstallationRepository $installations,
        private WhoisClient $whois,
        private JournalService $journal
    ) {
    }

    /**
     * Refresh the registration of one installation, if it is due.
     *
     * Never throws: the caller is an intake endpoint that has already
     * accepted and stored a report, and a registry having a bad day is not
     * a reason to answer a sender with an error it would only retry.
     *
     * @param array<string, mixed> $installation the row as it stands
     */
    public function refresh(array $installation, \DateTimeImmutable $now): void
    {
        try {
            $this->refreshOrThrow($installation, $now);
        } catch (\Throwable $e) {
            $this->journal->log(
                'support_dashboard',
                'support_whois_failed',
                'warning',
                'La consultation WHOIS de cette installation a échoué',
                [
                    'installation_id' => (string) ($installation['installation_id'] ?? ''),
                    'exception' => $e::class,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $installation
     */
    private function refreshOrThrow(array $installation, \DateTimeImmutable $now): void
    {
        $id = (int) ($installation['id'] ?? 0);
        if ($id === 0) {
            return;
        }

        $host = DomainName::hostOf((string) ($installation['instance_url'] ?? ''));
        if ($host === null) {
            // Nothing to ask about, and nothing to record: an installation
            // with no usable URL is not one whose registration went
            // missing. Silent on purpose — this is every row that has
            // never reported a URL, on every report it ever sends.
            return;
        }

        if (!$this->isDue($installation, $host, $now)) {
            return;
        }

        $lookup = $this->whois->lookup($host);
        $response = $lookup->response;

        if ($response === null) {
            // A miss is written down rather than left as an untouched row:
            // without the timestamp, every subsequent report would try
            // again, which is the query storm this class exists to avoid.
            //
            // The outcome travels verbatim, because « le registre a dit
            // que ce nom n'est enregistré nulle part » and « personne n'a
            // répondu » are opposite diagnoses: the first, on a domain a
            // site is currently answering on, is a hijack or a lapse.
            $this->installations->recordWhois($id, null, null, $lookup->outcome, null, null, $now);

            return;
        }

        $registration = WhoisRegistration::parse($response->raw);

        $this->installations->recordWhois(
            $id,
            $response->domain,
            $response->server,
            $lookup->outcome,
            // A response this alias table did not understand is not a
            // registration to claim — but it IS the one somebody needs to
            // open by hand, which is why the raw goes in either way.
            WhoisRegistration::isEmpty($registration) ? null : $registration,
            $response->raw,
            $now
        );
    }

    /**
     * Whether this installation is due a query.
     *
     * @param array<string, mixed> $installation
     */
    private function isDue(array $installation, string $host, \DateTimeImmutable $now): bool
    {
        $checkedAt = self::dateOf($installation['whois_checked_at'] ?? null);
        if ($checkedAt === null) {
            return true;
        }

        // A unit that moved to a new domain is a new question, and waiting
        // out the TTL would show the previous owner's registrar next to
        // the new URL for a month.
        $asked = (string) ($installation['whois_domain'] ?? '');
        if ($asked !== '' && !in_array($asked, DomainName::whoisCandidates($host), true)) {
            return true;
        }

        $days = (string) ($installation['whois_status'] ?? '') === self::STATUS_FOUND
            ? self::TTL_DAYS
            : self::RETRY_AFTER_FAILURE_DAYS;

        return $checkedAt <= $now->modify('-' . $days . ' days');
    }

    /**
     * `whois_checked_at`, read the way every stored moment is read.
     *
     * `new DateTimeImmutable($v)` answers **now** for an empty string, and
     * now is the one answer that must never come out of here: it reads as
     * « consulté à l'instant », so the row would never be due again and
     * the registration would freeze for the life of the installation.
     * Tests\Security\StoredDateReadingRatchetTest exists for exactly this,
     * and caught exactly this.
     */
    private static function dateOf(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? DateInput::fromStorage($value) : null;
    }
}
