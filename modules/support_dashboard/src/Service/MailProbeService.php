<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Journal\JournalService;
use Core\Service\DateInput;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\SupportDashboard\Mail\SupportMessageConsumer;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportMailProbeRepository;

/**
 * Diagnostic mail probes: which addresses an installation should write
 * to, and what arrived (roadmap IT-27).
 *
 * **The receiver issues the key.** It is the side that has to recognise
 * the message when it lands, so a key it did not choose is a key it
 * cannot expect. One press of the button on an instance is one key and
 * one row per address, which is also what makes « jamais reçu » a state
 * rather than a silence.
 *
 * **Nothing is hard-coded.** The addresses are this receiver's own
 * mailboxes, as `inbound_mail` currently has them: adding or removing a
 * box is a configuration act here and asks nothing of any instance. With
 * `inbound_mail` absent the whole feature answers « aucune boîte », which
 * is the truth and not an error.
 *
 * **A key expires.** A probe nobody ever claims would otherwise sit in
 * the table for ever and keep the consumer looking for a message that is
 * not coming.
 */
class MailProbeService
{
    /** Long enough for a slow relay chain, short enough to mean something. */
    public const VALIDITY_HOURS = 48;

    /** The caller could not prove it is the installation it claims to be. */
    public const STATUS_REJECTED = 'rejected';
    /** This receiver synchronises no mailbox at all — a complete answer. */
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * **No longer issued, and kept for the older senders that still read
     * it.** This receiver held each installation to one probe run an
     * hour; the limit is gone, on purpose (see `issueFor()`). An
     * installation running a version from before the change still has a
     * branch for this answer, and removing the constant would only mean
     * spelling the string somewhere else.
     */
    public const STATUS_RATE_LIMITED = 'rate_limited';

    /** A key and its addresses travel back. */
    public const STATUS_ISSUED = 'issued';

    public function __construct(
        private SupportMailProbeRepository $probes,
        private SupportInstallationRepository $installations,
        private JournalService $journal,
        private ?InboundMailInterface $inboundMail = null
    ) {
    }

    /**
     * The whole receiver side of `POST /api/support/mail-probes`:
     * authenticate the caller and hand back a key with the addresses to
     * write to.
     *
     * **No rate limit, deliberately.** There used to be one run an hour
     * per installation, and its cost was the case the probe exists for: a
     * unit reports « mes e-mails ne partent pas », and the probe that
     * would answer it is refused because one went out earlier that
     * afternoon. A ticket now always carries a probe, and a limit that
     * silently drops the evidence attached to a bug report is worse than
     * the mail it saves. What remains as a guard is the bearer identity —
     * a caller has to prove which installation it is before this route
     * answers anything at all.
     *
     * **Authenticated, and it has to be.** The answer is a list of this
     * receiver's own mailbox addresses; an open route here would hand
     * them to anybody who asked. Same bearer identity as every other
     * machine call of this module — the secret is compared against the
     * `password_hash()` the statistics intake stored, and every failure
     * answers the same `rejected`, so a caller cannot learn which
     * installation ids exist by watching which refusal comes back.
     *
     * @return array{status: string, correlation_key?: string, addresses?: list<string>, expires_at?: string}
     */
    public function issueFor(
        string $installationId,
        string $authorizationHeader,
        bool $isSecureTransport,
        \DateTimeImmutable $now
    ): array {
        if (!$isSecureTransport) {
            return ['status' => self::STATUS_REJECTED];
        }

        $secret = StatisticsIntakeService::extractBearerToken($authorizationHeader);
        if ($secret === null || trim($installationId) === '') {
            return ['status' => self::STATUS_REJECTED];
        }

        $installation = $this->installations->findByInstallationId(trim($installationId));
        if ($installation === null || !password_verify($secret, (string) $installation['secret_hash'])) {
            return ['status' => self::STATUS_REJECTED];
        }

        $issued = $this->issue((int) $installation['id'], $now);
        if ($issued === null) {
            return ['status' => self::STATUS_UNAVAILABLE, 'addresses' => []];
        }

        return [
            'status' => self::STATUS_ISSUED,
            'correlation_key' => $issued['correlation_key'],
            'addresses' => $issued['addresses'],
            'expires_at' => $issued['expires_at'],
        ];
    }

    /**
     * The addresses a probe should be sent to — the mailboxes this
     * receiver actually synchronises for the support consumer.
     *
     * @return list<string>
     */
    public function probeAddresses(): array
    {
        return $this->inboundMail?->probeAddressesFor(SupportMessageConsumer::CONSUMER_ID) ?? [];
    }

    /**
     * Issue a run: one key, one row per address.
     *
     * @return array{correlation_key: string, addresses: list<string>, expires_at: string}|null
     *         null when this receiver has no mailbox to probe at all
     */
    public function issue(int $installationId, \DateTimeImmutable $now): ?array
    {
        $addresses = $this->probeAddresses();
        if ($addresses === []) {
            return null;
        }

        $key = self::generateKey();
        $expiresAt = $now->modify('+' . self::VALIDITY_HOURS . ' hours');

        $this->probes->issue($installationId, $key, $addresses, $now, $expiresAt);

        $this->journal->log(
            'support_dashboard',
            'support_mail_probe_issued',
            'info',
            'Sonde e-mail de diagnostic émise',
            ['correlation_key' => $key, 'mailboxes' => count($addresses)]
        );

        return [
            'correlation_key' => $key,
            'addresses' => $addresses,
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Attach an arriving message to the probe it answers.
     *
     * @param string $subject the message's subject, prefix and all
     * @param list<string> $recipientAddresses every address the message
     *   names, so the probe row of the box it actually reached is the one
     *   that records it
     * @return bool whether it matched a probe still being waited for
     */
    public function claim(
        string $subject,
        ?string $rawHeaders,
        \DateTimeImmutable $receivedAt,
        \DateTimeImmutable $now,
        array $recipientAddresses = []
    ): bool {
        $key = self::keyIn($subject);
        if ($key === null) {
            return false;
        }

        $pending = $this->probes->findPending($key, $now);
        if ($pending === []) {
            return false;
        }

        // One message answers one address — the one it was sent to. A run
        // writes one message per box, so the row is the one whose address
        // the message names; taking the first pending row instead wrote
        // box B's headers onto box A's line whenever A's copy was the one
        // that got lost, which is precisely the diagnosis this exists to
        // give. A message naming none of them (an alias, a Bcc) falls
        // back to the first, as before.
        $probe = self::probeForRecipients($pending, $recipientAddresses);
        // The column is NOT NULL and this receiver wrote it itself, so an
        // unreadable value is a corrupted row rather than a missing one —
        // but the delay is the whole point of the probe, and reporting a
        // delay measured from *now* (which the raw constructor would do
        // for an empty string) would be worse than reporting none.
        $issuedAt = DateInput::fromStorage(is_string($probe['issued_at'] ?? null) ? $probe['issued_at'] : null);
        if ($issuedAt === null) {
            return false;
        }

        $this->probes->markReceived(
            (int) $probe['id'],
            $receivedAt,
            $receivedAt->getTimestamp() - $issuedAt->getTimestamp(),
            MailAuthenticationResults::parse($rawHeaders),
            // The block the reading was made from, kept alongside it. A
            // reading is not evidence: « SPF absent » is a claim about
            // what a server wrote down, and telling that apart from a bug
            // in the reading takes the header block itself — which is how
            // « tout absent » turned out to be an IMAP client that never
            // passed one.
            $rawHeaders
        );

        return true;
    }

    /**
     * The pending row whose address the message was sent to, or the first
     * one when the message names none of them.
     *
     * @param list<array<string, mixed>> $pending
     * @param list<string> $recipientAddresses
     * @return array<string, mixed>
     */
    private static function probeForRecipients(array $pending, array $recipientAddresses): array
    {
        $recipients = array_map(
            static fn(string $address): string => strtolower(trim($address)),
            $recipientAddresses
        );

        foreach ($pending as $probe) {
            $address = $probe['mailbox_address'] ?? null;
            if (is_string($address) && in_array(strtolower(trim($address)), $recipients, true)) {
                return $probe;
            }
        }

        return $pending[0];
    }

    /**
     * What every probe of one installation came to.
     *
     * @return list<array<string, mixed>>
     */
    public function resultsFor(int $installationId): array
    {
        return $this->probes->findForInstallation($installationId);
    }

    /**
     * The correlation key inside a subject, **wherever it sits**.
     *
     * `Core\Mail\MailService` prefixes every subject with
     * `[{short_name}] `, so a key anchored at the start would never be
     * found again — the one pitfall this whole mechanism has.
     */
    public static function keyIn(string $subject): ?string
    {
        return preg_match('/\b(SMP-[A-Z0-9]{10})\b/', $subject, $m) === 1 ? $m[1] : null;
    }

    /**
     * Upper case and unambiguous, because it travels through a subject
     * line that people read and retype.
     */
    private static function generateKey(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $key = 'SMP-';
        for ($i = 0; $i < 10; $i++) {
            $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $key;
    }
}
