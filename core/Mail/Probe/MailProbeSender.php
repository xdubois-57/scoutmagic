<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Probe;

use Core\Journal\JournalService;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\TransportConfigurator;
use Twig\Environment;

/**
 * « Est-ce que nos messages arrivent, et par quel chemin ? », answered by
 * sending one and going to look (roadmap IT-04).
 *
 * **A message built exactly like a real one.** Same frame
 * (`email/base.html.twig`), same visible sender, same DKIM signature,
 * both halves of the multipart body — because a stripped-down message is
 * classified differently from a real one, and a probe that is easier to
 * accept than the mail it stands for answers a question nobody asked. It
 * therefore goes out through `MailService` like everything else; only the
 * transport underneath is swapped, so the assembled message is the
 * message the site sends.
 *
 * **One relay, chosen by hand, no fallback** — see
 * {@see PinnedProviderTransport} for why that is the point rather than a
 * shortcut.
 *
 * **And no periodic cadence, deliberately.** A test message that lands in
 * the spam folder and stays there teaches the receiver something about
 * the next one: sent weekly and unattended, the instrument becomes the
 * cause of what it measures. Nothing here schedules anything; there is no
 * task handler, and the roadmap's optional weekly cadence is not part of
 * this iteration precisely so that the default cannot be « on » by
 * accident before somebody has written the warning that belongs with it.
 */
final class MailProbeSender
{
    /**
     * The lane a probe takes when nobody picks.
     *
     * Bulk, because that is the one that has trouble. Testing the
     * authentication lane would measure what never fails: a sign-in link
     * is one message to one person who is waiting for it, and no receiver
     * treats it the way it treats a mailing to two hundred families.
     */
    public const DEFAULT_LANE = MailLane::Bulk;

    /** The lanes worth choosing between, in the order the screen offers them. */
    public const OFFERED_LANES = [MailLane::Bulk, MailLane::Transactional, MailLane::Authentication];

    public function __construct(
        private MailService $mail,
        private MailProviderDirectory $directory,
        private TransportConfigurator $configurator,
        /**
         * The transport UNDERNEATH the chain — the one this installation
         * actually delivers with.
         *
         * Required, and not defaulted to `PhpMailerTransport`. A default
         * here would be the composition root's decision made silently in
         * the wrong place: on a site running `test_tools`' capture
         * transport, every message is assembled and stored instead of
         * being sent, and a probe that quietly instantiated its own real
         * transport would put mail on the wire from the one screen whose
         * whole job is to be honest about what leaves.
         * `Core\Mail\Transport\MailTransportFactory` hands this back so
         * there is one answer rather than two spellings of it.
         */
        private MailTransportInterface $delivery,
        private MailProbeRepository $probes,
        private Environment $twig,
        /**
         * Where « the site wrote to this address » is written down
         * (roadmap IT-05).
         *
         * **Required, not defaulted**, for §8.17's reason and #419's: a
         * probe that cannot stamp its own receipt looks exactly like a
         * probe that works, right up until its bounce is silently thrown
         * away — which is what the review of #562 found. A fatal at boot
         * costs less than a diagnostic that diagnoses nothing.
         *
         * **Before the two optional parameters below**, because a required
         * parameter cannot follow an optional one — and every site that
         * builds this class does so positionally, so inserting it here
         * moves `$journal` and `$counters` by one.
         *
         * There are three such sites, not the two I first wrote down:
         * `public/index.php`, this feature's own test, and
         * `OutboundMailControllerTest`, which builds a real probe sender
         * rather than a null one. The full suite is what named the third —
         * after I had already written a note about the hazard, in the same
         * change that walked into it.
         */
        private \Core\Mail\Feedback\Bounce\BounceStateRepository $sendReceipts,
        private ?JournalService $journal = null,
        private ?SendCounterRepository $counters = null
    ) {
    }

    /**
     * Send one probe and write it down.
     *
     * @param string $destination where to send it — the operator's own
     *   address, or the witness address of an outside analysis service.
     * @param int $providerId the relay to send it through, by id.
     * @throws MailProbeException when the relay is unknown or unusable,
     *   or when the message does not leave. A probe that failed to send
     *   is NOT recorded: a row with no verdict would sit in the history
     *   for ever waiting for an answer nobody can give, and « jamais
     *   reçu » would then mean two different things in one column.
     * @throws MailProbeNotRecordedException when the message DID leave and
     *   the history could not be told — neither a success nor a failure,
     *   and the one outcome a retry would make worse.
     */
    public function send(
        string $destination,
        int $providerId,
        MailLane $lane,
        ?\DateTimeImmutable $now = null
    ): MailProbe {
        $now ??= new \DateTimeImmutable();
        $destination = trim($destination);

        if ($destination === '' || filter_var($destination, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailProbeException('L’adresse de destination n’est pas une adresse valide.');
        }

        $provider = $this->providerFor($providerId);
        $code = self::generateCode();

        // **Through a queue-less clone, and through this one relay.**
        // A probe the deferral queue accepts has told nobody anything: it
        // would be recorded as sent, read « jamais reçu » a day later, and
        // send the operator looking at the receiving side when the message
        // had not left at all. Refused now is a usable answer.
        $sender = $this->mail->withoutDeferral()->throughTransport(new PinnedProviderTransport(
            $provider,
            $lane,
            $this->configurator,
            $this->delivery,
            $this->counters
        ));

        $context = [
            'code' => $code,
            'sent_at' => $now->format('d/m/Y à H:i'),
            'lane_label' => $lane->label(),
            'site_name' => $this->mail->getDefaultSender()['name'],
        ];

        try {
            $sender->send(
                to: $destination,
                subject: self::subjectFor($code),
                bodyHtml: $this->twig->render('email/deliverability_probe.html.twig', $context),
                bodyText: $this->twig->render('email/deliverability_probe.text.twig', $context),
                extraHeaders: $this->headersFor($lane),
                purpose: self::purposeFor($lane)
            );
        } catch (\Throwable $e) {
            throw new MailProbeException(
                'La sonde n’a pas pu partir par ce fournisseur. Regardez la page « Fournisseurs ».',
                previous: $e
            );
        }

        // **The receipt, and the probe stamps it itself.**
        //
        // `BounceStateRepository::record()` refuses a report for an address
        // the site cannot show it wrote to, and `recordSend()` mints that
        // proof only for an address the site already holds — which a probe
        // destination is not: the RGPD page's own section on this probe
        // says it is the administrator's own address, or the witness
        // address of an outside analysis service. (Said without a `§`
        // deliberately: that page is numbered for its readers, not with
        // this repository's sections, and `CrossReferenceResolutionRatchet`
        // is right to refuse a sigil it cannot resolve.)
        // So nothing stamped it, `record()` answered null for the probe's
        // own bounce, and #419's tracing could only fire for a destination
        // that happened to be a member's with a recent unrelated send. The
        // feature was dead for exactly the addresses probes exist for, and
        // the review of #562 is what said so.
        //
        // **`vouchesForRecipient` on `MailService::send()` does not do it**,
        // and that is deliberate on its part: it decides whether to CALL
        // `recordSend()`, which then applies its own « is this address on
        // file » test — two independent conditions, both of which must
        // hold, so that aiming a send AT somebody else's on-file address
        // cannot mint a receipt for them.
        //
        // This is the second case `$vouchedFor` was written for, the same
        // shape as the mailing-list address its docblock names: an address
        // living in this feature's OWN table, entered by a super-admin on a
        // protected page, which this sender vouches for at its own send.
        // The send is never automatic, so there is no path by which a
        // visitor reaches it.
        //
        // After the send and never before, for `MailService`'s reason: a
        // relay that refused the message wrote nothing, and a receipt for
        // it would be a receipt for a message nobody sent.
        try {
            $this->sendReceipts->recordSend($destination, $now, vouchedFor: true);
        } catch (\Throwable) {
            // Best effort, like the journal below: the message is gone, and
            // a receipt that could not be written must not turn a probe
            // that left into a probe the operator is told failed. The cost
            // is that this one probe's bounce will not be traced.
        }

        // **The message is gone from here on**, so nothing below may
        // report it as unsent. `record()` runs after the send and has to
        // — a row for a message that never left would wait for ever for a
        // verdict nobody can give — which leaves this narrow window where
        // the relay accepted the probe and the database refuses the
        // insert. Saying « elle n'a pas pu partir » there would be false
        // twice: the message is on its way, and the operator would press
        // the button again and send a duplicate.
        try {
            $id = $this->probes->record($code, $destination, $provider->id, $provider->name, $lane, $now);
        } catch (\Throwable $e) {
            // Tried anyway: the journal has its own storage and its own
            // swallowed failure, so on a partial outage this may be the
            // only durable trace that the message left.
            $this->journalSent($code, $provider, $lane);

            throw new MailProbeNotRecordedException($code, $e);
        }

        $this->journalSent($code, $provider, $lane);

        return new MailProbe($id, $code, $destination, $provider->id, $provider->name, $lane, $now);
    }

    /**
     * Record where a probe landed.
     *
     * @return bool whether this call is the one that wrote the verdict —
     *   false when it had one already, which is what a double click looks
     *   like from here.
     */
    public function recordVerdict(int $id, MailProbeVerdict $verdict, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        $probe = $this->probes->find($id);
        if ($probe === null) {
            throw new MailProbeException('Cette sonde n’existe plus.');
        }

        if (!$this->probes->recordVerdict($id, $verdict, $now)) {
            return false;
        }

        $this->journalVerdict($probe, $verdict);

        return true;
    }

    /**
     * The relays a probe may be sent through — every configured one,
     * usable or not filtered out here.
     *
     * Every one, and not only those in some lane's chain: the question
     * « est-ce que ce relais-là fait passer mes messages » is worth
     * asking about a relay somebody is considering putting in a chain,
     * and refusing to test it until it is already carrying real mail is
     * the wrong way round.
     *
     * @return list<MailProvider>
     */
    public function availableProviders(): array
    {
        try {
            $providers = array_values($this->directory->all());
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter($providers, static fn(MailProvider $p) => $p->isUsable()));
    }

    private function providerFor(int $providerId): MailProvider
    {
        foreach ($this->availableProviders() as $provider) {
            if ($provider->id === $providerId) {
                return $provider;
            }
        }

        throw new MailProbeException('Ce fournisseur n’existe plus, ou n’est pas utilisable.');
    }

    /**
     * The purpose, which is what decides the lane everything downstream
     * sees — the deferral queue's bookkeeping included.
     *
     * The transport is pinned regardless, so this does not choose the
     * relay. It is here so that a probe on the bulk lane is a bulk
     * message in every sense the rest of the code can observe, rather
     * than one wearing a bulk label on one screen.
     */
    private static function purposeFor(MailLane $lane): MailPurpose
    {
        return match ($lane) {
            MailLane::Bulk => MailPurpose::Bulk,
            MailLane::Authentication => MailPurpose::MagicLink,
            MailLane::Transactional => MailPurpose::Ordinary,
        };
    }

    /**
     * The headers a real message of this kind carries.
     *
     * **`List-Unsubscribe` on the bulk lane, and in the `mailto:` form.**
     * A real mailing carries the one-click URL form, built from the
     * recipient's own token — which a probe has no recipient row to build
     * from, and a fabricated link that answers 404 would be worse than
     * none at all. Omitting the header entirely is not neutral either:
     * receivers weigh it, so a probe without one is measurably lighter
     * than the mailing it stands for, on exactly the signal being
     * measured. The `mailto:` form is a real, working unsubscribe channel
     * — it reaches the unit — and is the closest honest approximation.
     * The gap is written down in the chantier journal rather than papered
     * over: this is « presque identique », not « identique ».
     *
     * @return array<string, string>
     */
    private function headersFor(MailLane $lane): array
    {
        if ($lane !== MailLane::Bulk) {
            return [];
        }

        // No guard on an empty address here, and that is deliberate:
        // `getDefaultSender()` returns the very address the message is
        // `From`, so an installation without one cannot get this far —
        // PHPMailer refuses « Invalid address (From) » while the message
        // is still being assembled, and `send()` reports that refusal.
        // A branch for it would be a branch no request can enter.
        $replyTo = $this->mail->getDefaultSender()['address'];

        return ['List-Unsubscribe' => '<mailto:' . $replyTo . '?subject=Desinscription>'];
    }

    /**
     * The subject a real send would carry, plus the code.
     *
     * The code is there to be searched for: an operator hunting one
     * message in a crowded mailbox — spam folder included, where the
     * search is the only way through — needs a string nothing else
     * contains. It is also what will attach a bounce to this precise
     * probe when IT-05 reads them.
     */
    public static function subjectFor(string $code): string
    {
        return 'Vérification de délivrabilité ' . $code;
    }

    /**
     * The code as it appears in a subject line, or null.
     *
     * Distinct prefix from `ReturnPathVerifier`'s `RET-`, so that a
     * message coming back can be attributed to the right diagnostic
     * rather than to whichever one looked first.
     */
    public static function codeIn(string $subject): ?string
    {
        return preg_match('/\b(SM-[A-Z0-9]{6})\b/', $subject, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Short, upper case and unambiguous.
     *
     * Short because somebody types it into a mailbox search box; upper
     * case and without `I`, `O`, `0` or `1` because a code read off a
     * screen and typed back in is where those four cost somebody ten
     * minutes.
     */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'SM-' . $code;
    }

    /**
     * Written down, and never with the destination.
     *
     * The journal is read on a screen and kept for a long time; what it
     * needs is which road was tested and what came of it, not who was
     * written to — the same rule `mail_identity_changed` follows. The
     * code is safe: it names the probe, and the probe's own row holds the
     * address encrypted.
     */
    private function journalSent(string $code, MailProvider $provider, MailLane $lane): void
    {
        try {
            $this->journal?->log(
                'core',
                'mail_probe_sent',
                'info',
                'Sonde de délivrabilité envoyée',
                ['code' => $code, 'provider' => $provider->name, 'lane' => $lane->value]
            );
        } catch (\Throwable) {
            // A probe that left is a probe that left. The journal entry
            // is the record of it, never a condition of it.
        }
    }

    private function journalVerdict(MailProbe $probe, MailProbeVerdict $verdict): void
    {
        try {
            $this->journal?->log(
                'core',
                'mail_probe_verdict',
                'info',
                'Verdict de sonde consigné',
                [
                    'code' => $probe->code,
                    'provider' => $probe->providerName,
                    'lane' => $probe->lane->value,
                    'verdict' => $verdict->value,
                ]
            );
        } catch (\Throwable) {
            // As above: the verdict is in the table either way.
        }
    }
}
