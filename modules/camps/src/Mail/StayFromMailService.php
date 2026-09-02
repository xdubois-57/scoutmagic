<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Mail;

use Core\Audit\AuditSource;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Service\DateInput;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\DuplicatePlaceDetector;
use Modules\Camps\Service\PlaceService;
use Modules\InboundMail\Api\InboundMessage;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\LlmConnector\Api\LlmTier;

/**
 * A stay, read out of a message nobody could attribute
 * (`camps_auto_create_from_mail`).
 *
 * **Two modes, one reading.** With the setting ON, a message in a dedicated
 * mailbox that states its dates and names a place the module can resolve
 * becomes a stay by itself, and the message is filed under it. With the
 * setting OFF, nothing happens on its own and the unsorted screen offers
 * « Créer un camp depuis ce message », which opens the ordinary creation
 * form with the very same reading already filled in. The same
 * `readValues()` answers both, so the automatic stay and the pre-filled
 * form can never disagree about what a message says.
 *
 * **The dates and the price are patterns**, from `MessageReader` — the
 * reader the field proposals already use, with its deliberately high bar
 * (a RANGE, never a lone date; exactly one amount, never two).
 *
 * **A place NAME is only ever read out of the message body, by the model.**
 * The sender's display name is not a source: a farmer signs their own
 * e-mails, so naming a new place after the `From:` header put a natural
 * person's name into `camp_places.name` — a clear-text column whose whole
 * justification is that "a place is not a natural person" (ARCHITECTURE.md
 * §8.67). The model is asked for the venue the message is ABOUT, is told
 * in as many words never to answer a person's name nor the sender's, and
 * is told to answer nothing when it hesitates; whatever comes back still
 * has to pass the same guards as before (long enough, not an address).
 *
 * **Without the connector, this matches and never creates.** No model, no
 * name, no new row: a message whose place resolves to nothing stays in the
 * unsorted screen, where « Créer un camp depuis ce message » lets a human
 * validate a name before it enters the database. Attaching to a place the
 * module ALREADY knows needs no model and still happens — the sender's
 * display name is a fine hint to recognise a farmer the unit has camped
 * with, it is only a bad name to write down.
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
     * Below this, a string is not a place name — it is an initial, a first
     * name, or whatever a mail client made of an address with no display
     * name at all. Creating a camp site called « Luc » would be worse than
     * creating nothing.
     */
    public const MIN_PLACE_NAME_LENGTH = 4;

    /**
     * How much of one message the model is shown. A booking states its
     * venue in its first lines; the rest is a signature, a quoted thread
     * and three legal footers, none of which name anything.
     */
    private const MAX_PROMPT_CHARS = 4000;

    /**
     * A place name is a handful of tokens; this budget is not, because it
     * pays for the model's reasoning too.
     *
     * It was 60 — the size of the ANSWER — which on a hybrid reasoning
     * model (an installation running glm-5.2 as its cheap model) is spent
     * thinking before a single character of JSON is written. On that
     * installation the summary's own 400-token cap came back fully spent
     * with an empty answer, so 60 never stood a chance. The reading
     * then comes back empty, the message stays unsorted, and nothing
     * anywhere says why: this path degrades silently by design, so it had
     * been failing quietly on every message. See
     * Service\PlaceSummaryService::MAX_TOKENS for the same lesson.
     */
    public const MAX_TOKENS = 1500;

    public function __construct(
        private CampRepository $camps,
        private CampService $campService,
        private PlaceService $placeService,
        private DuplicatePlaceDetector $duplicates,
        private MessageReader $reader,
        private SettingService $settings,
        // `?InboundMailInterface $inboundMail` used to sit here: this
        // service moved the message off Camps' reserved `unsorted`
        // reference, and there is no such reference any more (IT-07). The
        // caller returns the new stay id as an analysis result instead, so
        // the one place that creates associations stays the one place.
        /**
         * Optional `llm_connector` consumer (ARCHITECTURE.md §7.5). Null —
         * module absent, disabled, or unusable — means match-only: this
         * service then never names a place, it only recognises one.
         */
        private ?LlmConnectorInterface $llm = null,
        /**
         * What the message's attachments say (`Mail\AttachmentTextReader`).
         *
         * Null reads the body alone, which is what this service did — and
         * what made it blind to the ordinary case: a booking arrives as a
         * PDF contract with a one-word covering note, so everything worth
         * reading was in the one place nothing looked.
         */
        private ?AttachmentTextReader $attachmentText = null,
        /**
         * Where every refusal goes (see `journalSkip()`). Optional the way
         * everything else here is: without it this service behaves exactly
         * as it did, silently.
         */
        private ?JournalService $journal = null
    ) {
    }

    /**
     * Why no stay came out of a message — the one thing this path never
     * said.
     *
     * Automatic creation is a chain of six guards, any of which is a
     * perfectly ordinary « non », and until now all six looked identical
     * from outside: nothing happened. A unit whose camps box produced no
     * stay could not tell « le réglage est éteint » from « le message
     * n'annonce aucune date » from « aucun lieu n'a pu être nommé faute de
     * connecteur IA » — three different problems, two of which the unit
     * can fix themselves in a minute.
     *
     * The message id and the reason, and nothing else: no subject, no
     * sender, no place name, since a journal entry travels in a support
     * archive (§7.9). The id is the handle to open the message in
     * « Courrier ».
     */
    public function journalSkip(int $messageId, string $reason): void
    {
        $this->journal?->log(
            'camps',
            'camps_stay_from_mail_skipped',
            'info',
            sprintf('Message #%d : aucun séjour créé — %s.', $messageId, self::REASONS[$reason] ?? $reason),
            ['message_id' => $messageId, 'reason' => $reason]
        );
    }

    /**
     * What each reason means, in the words a chief would use. Kept next to
     * the constants that produce them so a new guard cannot be added
     * without a sentence to go with it.
     */
    public const REASONS = [
        self::SKIP_NOT_AUTOMATIC => 'le réglage « Créer un camp depuis un message » est désactivé',
        self::SKIP_MAILBOX_NOT_DEDICATED =>
            'la boîte n\'est pas déclarée dédiée aux camps (Configuration > Courrier entrant)',
        self::SKIP_NO_DATES => 'le message n\'annonce pas de période de séjour lisible',
        self::SKIP_NO_PLACE =>
            'aucun terrain connu ne correspond, et aucun nom de lieu n\'a pu être lu dans le message',
        self::SKIP_REFUSED => 'la création du séjour a été refusée',
    ];

    public const SKIP_NOT_AUTOMATIC = 'not_automatic';
    public const SKIP_MAILBOX_NOT_DEDICATED = 'mailbox_not_dedicated';
    public const SKIP_NO_DATES = 'no_dates';
    public const SKIP_NO_PLACE = 'no_place';
    public const SKIP_REFUSED = 'refused';

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
     * Whether a message may be read by the model at all.
     *
     * The whole of the AI half hangs off this one answer, and the RGPD
     * page says so: with a connector, the text of a message — its subject,
     * its body, and the readable text of its attachments — reaches the
     * configured provider; without one, nothing leaves the installation.
     */
    public function canNamePlaces(): bool
    {
        // The tier the reading itself uses — isAvailable() answers a
        // wider question and would say yes to a connector that cannot
        // serve this one.
        return $this->llm !== null && $this->llm->isTierAvailable(LlmTier::CHEAP);
    }

    /**
     * What one message says about a stay, in the shape the creation form
     * posts — so the pre-filled form and the automatic stay are the same
     * reading, not two.
     *
     * `place_name` is empty whenever nothing may be written down: no
     * connector, a model that failed, or a model that was not sure.
     *
     * @return array{place_name: string, address: string, postal_code: string, city: string,
     *   start_date: string, end_date: string, price: string, stay_type: string,
     *   participant_count: string}
     */
    public function readValues(InboundMessage $message): array
    {
        $text = $this->textOf($message);
        $range = $this->reader->readDateRange($text);
        $priceCents = $this->reader->readPriceCents($text);
        $participants = $this->reader->readParticipantCount($text);
        $venue = $this->venueOf($message);

        return [
            'place_name' => $venue['name'],
            'address' => $venue['address'],
            'postal_code' => $venue['postal_code'],
            'city' => $venue['city'],
            'start_date' => $range['start'] ?? '',
            'end_date' => $range['end'] ?? '',
            'price' => $priceCents !== null ? number_format($priceCents / 100, 2, ',', ' ') : '',
            'stay_type' => $this->stayTypeFor($range['start'] ?? '', $range['end'] ?? ''),
            'participant_count' => $participants !== null ? (string) $participants : '',
        ];
    }

    /**
     * Which kind of stay dates of that length describe.
     *
     * Every stay read out of a message used to be filed as a « Grand camp »
     * — including a three-day booking whose contract said so in as many
     * words. The dates were right there and nothing looked at them.
     *
     * **A proposal, never a verdict.** It fills a field a chief can change
     * before saving, and on the automatic path it is the same value the
     * pre-filled form would have shown — which is the whole point of this
     * reading being one reading. Dates that could not be read leave the
     * module's own default in place rather than guessing from nothing.
     *
     * Counted in whole days, arrival and departure included: a
     * Friday-to-Sunday stay is three days, which is what a chief means by
     * one. The threshold is a setting because a unit that camps from
     * Thursday to Sunday every month means something different by « petit »
     * than one that does not.
     */
    public function stayTypeFor(string $start, string $end): string
    {
        $from = DateInput::fromStorage($start);
        $to = DateInput::fromStorage($end);
        if ($from === null || $to === null || $to < $from) {
            return Camp::STAY_GRAND_CAMP;
        }

        $days = (int) $from->diff($to)->days + 1;

        return $days <= $this->shortStayMaxDays() ? Camp::STAY_SHORT_CAMP : Camp::STAY_GRAND_CAMP;
    }

    /**
     * `camps_short_stay_max_days`, floored at one.
     *
     * A zero or a negative — a stray keystroke, or a row written by hand —
     * would make every stay a grand camp and quietly undo the setting
     * rather than announcing itself.
     */
    private function shortStayMaxDays(): int
    {
        $raw = (int) ($this->settings->get('camps_short_stay_max_days', 'camps', (string) self::DEFAULT_SHORT_STAY_MAX_DAYS)
            ?? self::DEFAULT_SHORT_STAY_MAX_DAYS);

        return max(1, $raw);
    }

    /** Friday to Monday, the longest thing a unit still calls a small camp. */
    public const DEFAULT_SHORT_STAY_MAX_DAYS = 4;

    /**
     * Turns an unsorted message into a stay, or answers null and leaves it
     * exactly where it was.
     *
     * Null is the normal answer and is never an error: a message with no
     * usable dates, or one whose place can neither be recognised nor named,
     * is a message a human has to look at. Silence is what this module does
     * with ambiguity everywhere else (ARCHITECTURE.md §8.67).
     *
     * @return int|null the stay's id when one was created or reused
     */
    public function createFrom(InboundMessage $message): ?int
    {
        if (!$this->isAutomatic()) {
            $this->journalSkip($message->id, self::SKIP_NOT_AUTOMATIC);

            return null;
        }

        // The dates first, and on their own: they are a regex, they decide
        // by themselves whether this message can become a stay at all, and
        // a message that cannot must not cost a model call — every unsorted
        // message would otherwise be billed for the privilege of being
        // refused.
        if ($this->reader->readDateRange($this->textOf($message)) === null) {
            $this->journalSkip($message->id, self::SKIP_NO_DATES);

            return null;
        }

        $values = $this->readValues($message);
        if (!$this->isUsable($values)) {
            $this->journalSkip($message->id, self::SKIP_NO_DATES);

            return null;
        }

        try {
            $placeId = $this->resolvePlaceId($message, $values['place_name']);
            if ($placeId === null) {
                $this->journalSkip($message->id, self::SKIP_NO_PLACE);

                return null;
            }

            $campId = $this->existingStayId($placeId, $values['start_date'], $values['end_date'])
                ?? $this->campService->create(
                    $placeId,
                    [
                        'stay_type' => $values['stay_type'],
                        'start_date' => $values['start_date'],
                        'end_date' => $values['end_date'],
                        'status' => Camp::STATUS_TO_CONFIRM,
                        'price' => $values['price'],
                        'participant_count' => $values['participant_count'],
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
            // message simply stays unattached, where the chief d'unité and
            // this module's own users both see it, and a human decides.
            // Throwing would sink the whole pass over one badly-worded
            // e-mail. It is written down, though — a refusal nobody can see
            // is indistinguishable from a message nobody looked at.
            $this->journalSkip($message->id, self::SKIP_REFUSED);

            return null;
        }

        $this->journal?->log(
            'camps',
            'camps_stay_created_from_mail',
            'info',
            sprintf('Message #%d : séjour #%d créé ou retrouvé depuis le courrier.', $message->id, $campId),
            ['message_id' => $message->id, 'camp_id' => $campId, 'place_id' => $placeId]
        );

        // No `move()` any more: there is no `unsorted` association to move
        // the message OFF. The caller returns this id as an analysis
        // result, and `Service\AnalysisResultApplier` writes the
        // association — one place that creates associations rather than
        // two, which is what the consumer contract asks for.
        return $campId;
    }

    /**
     * The place this message is about: an existing one when the detector
     * is CERTAIN, a new one when — and only when — the model named it.
     *
     * Null means "nothing may be written down for this message", which is
     * the whole of the no-connector behaviour: recognise, never invent.
     *
     * Only 'certain' counts on the matching side. A 'possible' match is the
     * detector saying "a human should look at this", and attaching a stay
     * to a place on that basis would put a booking on somebody else's field
     * with nothing on the page to say so.
     */
    private function resolvePlaceId(InboundMessage $message, string $placeName): ?int
    {
        $matched = $this->matchPlaceIdFor($message, $placeName);
        if ($matched !== null) {
            // A place the unit already knows keeps the address a human
            // curated. The model's reading of a message is not a reason to
            // rewrite a field somebody checked on the ground.
            return $matched;
        }

        // No match. Creating a row means writing a name into a clear-text
        // column, and the only name allowed to end up there is one the
        // model read out of the body and that passed the guards.
        if ($placeName === '') {
            return null;
        }

        // The address goes in with it, or the place is created blind: the
        // geocoding pass needs a city or a postcode to build a query at
        // all, so a place created with a name alone can never reach the
        // map — and nothing said why.
        $venue = $this->venueOf($message);

        return $this->placeService->create(
            [
                'name' => $placeName,
                'address' => $venue['address'],
                'postal_code' => $venue['postal_code'],
                'city' => $venue['city'],
                'country' => $this->defaultCountry(),
            ],
            null,
            AuditSource::Email
        );
    }

    /**
     * `camps_default_country`, the same answer the creation form starts
     * from.
     *
     * Not read out of the message: a contract almost never names its own
     * country, and the geocoder needs one to tell « Dworp, Belgique » from
     * a Dworp somewhere else. The unit's own default is the honest guess,
     * and it is the one a chief would have accepted on the form.
     */
    private function defaultCountry(): string
    {
        return (string) ($this->settings->get('camps_default_country', 'camps', 'Belgique') ?? '');
    }

    /**
     * The known place this message designates, or null.
     *
     * Two hints, in this order: the name the model read out of the body,
     * then the sender's display name. The second is a MATCHING hint and
     * never a name — recognising « Domaine de Mozet » in a `From:` header
     * writes nothing anywhere, which is precisely what makes it safe when
     * naming a new place from it is not.
     *
     * Public because the pre-filled creation form asks the same question:
     * a chief opening « Créer un camp depuis ce message » should land on
     * the place already selected rather than on a second row for it.
     */
    public function matchPlaceIdFor(InboundMessage $message, string $placeName): ?int
    {
        foreach ([$placeName, $this->senderHint($message)] as $hint) {
            $match = $this->matchExistingPlaceId($hint);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * The known place this name certainly designates, or null.
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
     * Dates only. Whether the place is usable is `resolvePlaceId()`'s
     * question, and its answer is a row id or nothing at all.
     *
     * @param array{place_name: string, start_date: string, end_date: string, price: string} $values
     */
    private function isUsable(array $values): bool
    {
        return $values['start_date'] !== '' && $values['end_date'] !== '';
    }

    /**
     * The venue this message is about, according to the model — or an
     * empty string, which is the answer in every doubtful case.
     *
     * Degrades exactly like `Service\PlaceSummaryService`: no connector,
     * a refusal, a timeout or a blank answer all mean "no name", never an
     * exception. A model that is down must cost this module a creation,
     * never a synchronisation pass.
     */
    /**
     * The venue this message is about: its name, and where it is.
     *
     * **The address is read in the SAME call as the name**, and that is
     * the whole reason it can exist at all: a second model call per
     * message to learn a postcode would not be worth it, while three more
     * fields in a schema the model is already filling cost nothing.
     *
     * It was missing entirely, and it cost more than a blank form field. A
     * place created from a message had a name and nothing else, so
     * `Task\GeocodePlacesHandler` picked it up, found neither city nor
     * postcode to build a query from, stamped it as attempted and moved
     * on — the stay's venue never appeared on the map, and nothing
     * anywhere said the address had never been read in the first place.
     *
     * @return array{name: string, address: string, postal_code: string, city: string}
     */
    private function readVenue(InboundMessage $message): array
    {
        if (!$this->canNamePlaces() || $this->llm === null) {
            // No connector, or no model on the CHEAP tier. Nothing is
            // called and nothing is sent anywhere — and until this line
            // existed, that was indistinguishable from a model that
            // answered nothing.
            $this->journalRead($message->id, self::READ_NO_CONNECTOR, null, null);

            return self::NO_VENUE;
        }

        $text = $this->textOf($message);
        if ($text === '') {
            $this->journalRead($message->id, self::READ_NO_TEXT, null, null);

            return self::NO_VENUE;
        }

        $prompt = mb_substr($text, 0, self::MAX_PROMPT_CHARS);

        try {
            $response = $this->llm->complete(new LlmRequest(
                tier: LlmTier::CHEAP,
                prompt: $prompt,
                systemPrompt: self::PLACE_NAME_SYSTEM_PROMPT,
                responseSchema: [
                    'type' => 'object',
                    'properties' => [
                        'place_name' => ['type' => 'string'],
                        'address' => ['type' => 'string'],
                        'postal_code' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                    ],
                    'required' => ['place_name'],
                ],
                maxTokens: self::MAX_TOKENS,
            ));
        } catch (LlmException $e) {
            $this->journalRead($message->id, self::READ_PROVIDER_FAILED, null, null, mb_strlen($prompt), $e);

            return self::NO_VENUE;
        }

        $answer = $response->parsed['place_name'] ?? null;
        $name = is_string($answer) ? trim($answer) : '';

        $outcome = match (true) {
            !is_string($answer) => self::READ_NO_ANSWER,
            $name === '' => self::READ_MODEL_DECLINED,
            str_contains($name, '@') => self::READ_LOOKS_LIKE_AN_ADDRESS,
            mb_strlen($name) < self::MIN_PLACE_NAME_LENGTH => self::READ_TOO_SHORT,
            default => self::READ_ACCEPTED,
        };

        $venue = [
            'name' => $outcome === self::READ_ACCEPTED ? $name : '',
            'address' => self::cleanAddressPart($response->parsed['address'] ?? null, self::MIN_ADDRESS_LENGTH),
            'postal_code' => self::cleanPostalCode($response->parsed['postal_code'] ?? null),
            'city' => self::cleanAddressPart($response->parsed['city'] ?? null, self::MIN_CITY_LENGTH),
        ];

        $this->journalRead($message->id, $outcome, $name, $response, mb_strlen($prompt), null, $venue);

        return $venue;
    }

    /** @var array{name: string, address: string, postal_code: string, city: string} */
    public const NO_VENUE = ['name' => '', 'address' => '', 'postal_code' => '', 'city' => ''];

    /**
     * A street and a town are shorter than a place name and longer than a
     * typo. « Rue » alone is not an address and « D » is not a town.
     */
    public const MIN_ADDRESS_LENGTH = 5;
    public const MIN_CITY_LENGTH = 2;

    /** Enough for a French, Belgian, Dutch or British code, and no more. */
    public const MAX_POSTAL_CODE_LENGTH = 10;

    /**
     * The same shape of guard the place name has had since the day it
     * stopped coming out of the `From:` header: long enough to be the
     * thing it claims to be, and not an e-mail address. A model that
     * hedges answers an empty string, and an empty string is a complete
     * answer here — the field simply stays blank, as it did before this
     * reading existed.
     */
    private static function cleanAddressPart(mixed $value, int $minimum): string
    {
        if (!is_string($value)) {
            return '';
        }

        $clean = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_strlen($clean) >= $minimum
            && mb_strlen($clean) <= 200
            && !str_contains($clean, '@')
            ? $clean
            : '';
    }

    /**
     * A postcode has to contain a digit, and that one rule is what keeps a
     * model's « inconnu » or « n/a » out of the column.
     */
    private static function cleanPostalCode(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $clean = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $clean !== ''
            && mb_strlen($clean) <= self::MAX_POSTAL_CODE_LENGTH
            && preg_match('/\d/', $clean) === 1
            ? $clean
            : '';
    }

    /**
     * The instruction the model is given. A constant so the journal entry,
     * the tests and this class all quote the same words — a prompt written
     * inline is a prompt nobody can check against what was actually sent.
     */
    public const PLACE_NAME_SYSTEM_PROMPT = 'Tu lis un e-mail reçu par une unité scoute au sujet d\'un terrain de camp. '
        . 'Identifie le lieu dont ce message parle : le terrain, la ferme, le domaine, le gîte ou '
        . 'le bâtiment où le séjour aurait lieu. '
        . 'Donne son nom dans « place_name » : jamais un nom de personne, jamais le nom de '
        . 'l\'expéditeur ou de sa signature, jamais une adresse postale, jamais une adresse e-mail, '
        . 'jamais une commune seule. '
        . 'Donne SON adresse, celle du lieu du séjour et d\'aucun autre, dans « address » (rue et '
        . 'numéro), « postal_code » (code postal) et « city » (commune). '
        . 'N\'y mets JAMAIS l\'adresse de l\'expéditeur, celle de sa signature, celle d\'un bureau '
        . 'où renvoyer un contrat, ni celle de l\'unité scoute elle-même : seule compte l\'adresse '
        . 'où le camp aurait lieu. '
        . 'N\'invente rien et ne complète rien : recopie ce qui est écrit dans le message. '
        . 'Réponds une chaîne vide pour chaque champ que le message ne donne pas clairement, '
        . 'ou dont tu hésites — un champ vide est une réponse complète.';

    public const READ_ACCEPTED = 'accepted';
    public const READ_NO_CONNECTOR = 'no_connector';
    public const READ_NO_TEXT = 'no_text';
    public const READ_PROVIDER_FAILED = 'provider_failed';
    public const READ_NO_ANSWER = 'no_answer';
    public const READ_MODEL_DECLINED = 'model_declined';
    public const READ_TOO_SHORT = 'too_short';
    public const READ_LOOKS_LIKE_AN_ADDRESS = 'looks_like_an_address';

    /** What each outcome means, in the words a chief would use. */
    public const READ_OUTCOMES = [
        self::READ_ACCEPTED => 'le modèle a nommé un lieu',
        self::READ_NO_CONNECTOR => 'aucun connecteur IA disponible pour ce travail — rien n\'a été envoyé',
        self::READ_NO_TEXT => 'le message n\'a aucun texte lisible, pièces jointes comprises',
        self::READ_PROVIDER_FAILED => 'le fournisseur d\'IA a refusé ou n\'a pas répondu',
        self::READ_NO_ANSWER => 'le modèle n\'a pas répondu dans la forme demandée',
        self::READ_MODEL_DECLINED => 'le modèle a répondu qu\'il ne voyait pas de lieu nommé',
        self::READ_TOO_SHORT => 'le nom proposé est trop court pour être un lieu',
        self::READ_LOOKS_LIKE_AN_ADDRESS => 'le nom proposé ressemble à une adresse e-mail',
    ];

    /**
     * How much of the model's answer is kept. A place name is a handful of
     * words; anything longer is a model that misunderstood, and the first
     * eighty characters are enough to see that.
     */
    public const MAX_JOURNALLED_ANSWER = 80;

    /**
     * What the model answered, and what became of it.
     *
     * **This is a deliberate exception to the connector's own rule.**
     * `Modules\LlmConnector` journals token counts and never a word of
     * prompt or response, and it is right to: it serves every module and
     * cannot know what any of them is sending. Here the module knows
     * exactly what it asked for — the name of a camp site — and it is a
     * name this same reading would write into `camp_places.name`, a
     * clear-text column whose justification is that a place is not a
     * natural person (ARCHITECTURE.md §8.67). Writing it down when it is
     * REFUSED costs nothing more than accepting it would, and it is the
     * only way to tell « le modèle a répondu "Camp" » from « le modèle n'a
     * rien répondu » from « aucun modèle n'a été appelé » — three causes of
     * one symptom: a form that comes back without a place.
     *
     * The answer is bounded, and no other part of the message is here: not
     * the subject, not the sender, not the body it was read from.
     */
    /**
     * @param array{name: string, address: string, postal_code: string, city: string}|null $venue
     */
    private function journalRead(
        int $messageId,
        string $outcome,
        ?string $answer,
        ?LlmResponse $response,
        ?int $promptChars = null,
        ?LlmException $error = null,
        ?array $venue = null
    ): void {
        $context = [
            'message_id' => $messageId,
            'outcome' => $outcome,
        ];
        if ($venue !== null) {
            // Which of the address fields came back, and not their values:
            // « le modèle a répondu un nom sans adresse » is the fact a
            // chief needs, and the values are on the stay's own page.
            $context['address_fields'] = array_values(array_filter(
                ['address', 'postal_code', 'city'],
                static fn(string $field): bool => $venue[$field] !== ''
            ));
        }
        if ($answer !== null) {
            $context['answer'] = mb_substr($answer, 0, self::MAX_JOURNALLED_ANSWER);
        }
        if ($promptChars !== null) {
            $context['prompt_chars'] = $promptChars;
        }
        if ($response !== null) {
            // The budget, spelled out: a hybrid reasoning model can spend
            // the whole cap thinking and return an empty answer, and two
            // integers are what tells that apart from a model that read the
            // message and found no place in it.
            $context['input_tokens'] = $response->inputTokens;
            $context['output_tokens'] = $response->outputTokens;
            $context['max_tokens'] = self::MAX_TOKENS;
            $context['truncated'] = $response->truncated;
            $context['raw_answer_length'] = mb_strlen($response->content);
        }
        if ($error !== null) {
            $context['error'] = mb_substr($error->getMessage(), 0, 200);
        }

        $this->journal?->log(
            'camps',
            'camps_place_name_read',
            'info',
            sprintf(
                'Message #%d : lecture du lieu par le modèle — %s%s',
                $messageId,
                self::READ_OUTCOMES[$outcome] ?? $outcome,
                $answer !== null && $answer !== ''
                    ? ' (réponse : « ' . mb_substr($answer, 0, self::MAX_JOURNALLED_ANSWER) . ' »)'
                    : ''
            ),
            $context
        );
    }

    /**
     * The sender's display name, kept for MATCHING only.
     *
     * Deliberately never the address's local part: « info », « contact »
     * and « reservations » designate nothing, and an address is not a name
     * either.
     */
    private function senderHint(InboundMessage $message): string
    {
        $name = trim($message->fromName ?? '');

        return str_contains($name, '@') ? '' : $name;
    }

    /**
     * Everything of a message the readers look at: subject, body, and what
     * its attachments say.
     *
     * Memoised per message because `createFrom()` asks twice — once for
     * the dates alone, before deciding whether the model is worth calling —
     * and a PDF extraction is not a thing to do twice for one answer.
     *
     * @var array<int, string>
     */
    private array $textCache = [];

    /**
     * The model is asked once per message, whatever asks.
     *
     * `createFrom()` reads the values and then resolves the place, and the
     * pre-filled form reads the values and then matches the place: without
     * this, one message would buy two identical model calls and two
     * identical journal entries saying the same thing.
     *
     * @var array<int, array{name: string, address: string, postal_code: string, city: string}>
     */
    private array $venueCache = [];

    /**
     * @return array{name: string, address: string, postal_code: string, city: string}
     */
    private function venueOf(InboundMessage $message): array
    {
        return $this->venueCache[$message->id] ??= $this->readVenue($message);
    }

    /**
     * Everything this service reads of one message: its subject, its body
     * and the text of its attachments.
     *
     * Public because `Mail\CampsMessageConsumer` asks the same question
     * for a different purpose — recognising a stay rather than creating
     * one — and a second assembly of the same three parts would drift
     * from this one. Memoised per message id, so asking twice costs one
     * reading of the contract.
     */
    public function fullTextOf(InboundMessage $message): string
    {
        return $this->textOf($message);
    }

    private function textOf(InboundMessage $message): string
    {
        if (!isset($this->textCache[$message->id])) {
            $attachments = $this->attachmentText?->read($message->attachments) ?? '';
            $this->textCache[$message->id] = trim(
                $message->subject . "\n" . $message->bodyText . ($attachments === '' ? '' : "\n" . $attachments)
            );
        }

        return $this->textCache[$message->id];
    }
}
