<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Mail;

use Core\Audit\AuditSource;
use Core\Config\SettingService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\DuplicatePlaceDetector;
use Modules\Camps\Service\PlaceService;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;

/**
 * A stay, read out of a message nobody could attribute
 * (`camps_auto_create_from_mail`).
 *
 * **Two modes, one reading.** With the setting ON, a message in a dedicated
 * mailbox that states its dates and comes from a place the module can name
 * becomes a stay by itself, and the message is filed under it. With the
 * setting OFF, nothing happens on its own and the unsorted screen offers
 * « Créer un camp depuis ce message », which opens the ordinary creation
 * form with the very same reading already filled in. The same
 * `readValues()` answers both, so the automatic stay and the pre-filled
 * form can never disagree about what a message says.
 *
 * **Nothing here parses anything new.** The dates and the price come from
 * `MessageReader` — the reader the field proposals already use, with its
 * deliberately high bar (a RANGE, never a lone date; exactly one amount,
 * never two). The place comes from the sender's own display name, which
 * `inbound_mail` split out of the `From:` header before this module ever
 * saw the message.
 *
 * **A place is matched before it is created**, through
 * `Service\DuplicatePlaceDetector` and only at `certain` — the same
 * detector that guards the creation form, asked the same question. A unit
 * that has camped at « Domaine de Mozet » four times must not acquire a
 * fifth row for it because a farmer changed their e-mail signature.
 *
 * **It never creates the same stay twice.** A place already carrying a stay
 * over exactly those dates takes the message rather than growing a
 * duplicate: two messages about one booking are the normal case, and only
 * the first of them is a reply in a thread the consumer already recognises.
 */
class StayFromMailService
{
    /**
     * Below this, a sender's display name is not a place name — it is an
     * initial, a first name, or whatever a mail client made of an address
     * with no display name at all. Creating a camp site called « Luc »
     * would be worse than creating nothing.
     */
    public const MIN_PLACE_NAME_LENGTH = 4;

    public function __construct(
        private CampRepository $camps,
        private CampService $campService,
        private PlaceService $placeService,
        private DuplicatePlaceDetector $duplicates,
        private MessageReader $reader,
        private SettingService $settings,
        private ?InboundMailInterface $inboundMail = null
    ) {
    }

    /**
     * Whether a message may become a stay on its own. Off means the
     * unsorted screen offers the action instead — see the setting's own
     * description in module.json.
     */
    public function isAutomatic(): bool
    {
        return (string) ($this->settings->get('camps_auto_create_from_mail', 'camps', '1') ?? '1') === '1';
    }

    /**
     * What one message says about a stay, in the shape the creation form
     * posts — so the pre-filled form and the automatic stay are the same
     * reading, not two.
     *
     * @return array{place_name: string, start_date: string, end_date: string, price: string}
     */
    public function readValues(InboundMessage $message): array
    {
        $text = trim($message->subject . "\n" . $message->bodyText);
        $range = $this->reader->readDateRange($text);
        $priceCents = $this->reader->readPriceCents($text);

        return [
            'place_name' => $this->placeNameOf($message),
            'start_date' => $range['start'] ?? '',
            'end_date' => $range['end'] ?? '',
            'price' => $priceCents !== null ? number_format($priceCents / 100, 2, ',', ' ') : '',
        ];
    }

    /**
     * Turns an unsorted message into a stay, or answers null and leaves it
     * exactly where it was.
     *
     * Null is the normal answer and is never an error: a message with no
     * usable dates, or from a sender whose name says nothing about a place,
     * is a message a human has to look at. Silence is what this module does
     * with ambiguity everywhere else (ARCHITECTURE.md §8.67).
     *
     * @return int|null the stay's id when one was created or reused
     */
    public function createFrom(InboundMessage $message): ?int
    {
        if (!$this->isAutomatic()) {
            return null;
        }

        $values = $this->readValues($message);
        if (!$this->isUsable($values)) {
            return null;
        }

        try {
            $placeId = $this->resolvePlaceId($values['place_name']);
            $campId = $this->existingStayId($placeId, $values['start_date'], $values['end_date'])
                ?? $this->campService->create(
                    $placeId,
                    [
                        'stay_type' => Camp::STAY_GRAND_CAMP,
                        'start_date' => $values['start_date'],
                        'end_date' => $values['end_date'],
                        'status' => Camp::STATUS_TO_CONFIRM,
                        'price' => $values['price'],
                        'section_ids' => [],
                    ],
                    // No actor: nobody pressed anything. The source says
                    // « courrier » and the timeline shows it as such.
                    null,
                    // No sections: a message never says which ones, and
                    // inventing them would be a claim about a stay nobody
                    // has confirmed yet.
                    static fn(array $ids): string => '',
                    AuditSource::Email
                );
        } catch (CampsException) {
            // A refusal here is not a failure of the synchronisation: the
            // message stays unsorted and a human decides. Throwing would
            // sink the whole sync pass over one badly-worded e-mail.
            return null;
        }

        $this->inboundMail?->move(
            CampsMessageConsumer::CONSUMER_ID,
            CampsMessageConsumer::UNSORTED_REFERENCE,
            CampsMessageConsumer::referenceFor($campId),
            $message->id
        );

        return $campId;
    }

    /**
     * The place the message names: an existing one when the detector is
     * CERTAIN, a new one otherwise.
     *
     * Only 'certain' counts. A 'possible' match is the detector saying "a
     * human should look at this", and attaching a stay to a place on that
     * basis would put a booking on somebody else's field with nothing on
     * the page to say so.
     */
    private function resolvePlaceId(string $placeName): int
    {
        return $this->matchExistingPlaceId($placeName)
            ?? $this->placeService->create(['name' => $placeName], null, AuditSource::Email);
    }

    /**
     * The known place this name certainly designates, or null.
     *
     * Public because the pre-filled creation form asks the same question:
     * a chief opening « Créer un camp depuis ce message » should land on
     * the place already selected rather than on a second row for it.
     */
    public function matchExistingPlaceId(string $placeName): ?int
    {
        if (mb_strlen(trim($placeName)) < self::MIN_PLACE_NAME_LENGTH) {
            return null;
        }

        foreach ($this->duplicates->findCandidates(['name' => $placeName]) as $candidate) {
            if ($candidate['certainty'] === 'certain') {
                return $candidate['place']->id;
            }
        }

        return null;
    }

    /**
     * A stay already covering exactly those dates at that place.
     *
     * Two messages about one booking are the normal case — a confirmation
     * and an invoice, sent days apart, neither of them a reply the thread
     * rule recognises.
     */
    private function existingStayId(int $placeId, string $start, string $end): ?int
    {
        foreach ($this->camps->findByPlace($placeId) as $camp) {
            if ($camp->startDate === $start && $camp->endDate === $end) {
                return $camp->id;
            }
        }

        return null;
    }

    /**
     * @param array{place_name: string, start_date: string, end_date: string, price: string} $values
     */
    private function isUsable(array $values): bool
    {
        return $values['start_date'] !== ''
            && $values['end_date'] !== ''
            && mb_strlen($values['place_name']) >= self::MIN_PLACE_NAME_LENGTH;
    }

    /**
     * The sender's display name, or nothing.
     *
     * Deliberately never the address's local part: « info », « contact »
     * and « reservations » are not places, and a site full of camp sites
     * called « info » would be worse than a site with none.
     */
    private function placeNameOf(InboundMessage $message): string
    {
        $name = trim($message->fromName ?? '');

        return str_contains($name, '@') ? '' : $name;
    }
}
