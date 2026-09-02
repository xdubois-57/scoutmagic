<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Service\DateInput;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;

/**
 * Everything the door screen asks: which events can be controlled, who is
 * expected, who this ticket is, and how many have come in.
 *
 * **Seats are counted from the priced-or-capped number fields**, and from
 * nothing else. `news_form_fields` already carries `capacity_max` and
 * `price_per_unit` per field, so a form declaring « Repas adulte, 15 € »
 * and « Repas enfant, 8 € » already says, per response, how many people it
 * covers. A form declaring neither has no seats to count and each response
 * is worth one entry — which is the honest reading of a plain sign-up.
 *
 * **The name and e-mail search decrypts and filters in PHP, on purpose.**
 * `contact_email` is an encrypted BLOB with a blind index for exact match
 * only (SECURITY.md §5), and every answer is encrypted too, so there is no
 * SQL LIKE to write against either. What bounds the cost is the query: one
 * event's responses, a few hundred at the very most, on a page nobody
 * opens without an event selected. That is a different shape from a
 * site-wide member search, and it is why this is acceptable here and would
 * not be there.
 */
class ScanService
{
    /** The reference search is global; only this one is scoped to an event. */
    private const SEARCH_RESULT_LIMIT = 25;

    public const STATUS_VALID = 'valid';
    public const STATUS_USED = 'used';
    public const STATUS_OTHER_EVENT = 'other_event';
    public const STATUS_NOT_FOUND = 'not_found';

    public function __construct(
        private FormRepository $forms,
        private FormFieldRepository $fields,
        private FormResponseRepository $responses,
        private ArticleRepository $articles,
        private TicketService $tickets,
        // Optional module dependency (ARCHITECTURE.md §7.5): with finance
        // off, every payment surface of this screen disappears — which is
        // exactly what a ticketed free event looks like too.
        private ?ExpectedReceivableInterface $expectedReceivable = null
    ) {
    }

    /**
     * The events a door can be held for: they deliver a ticket, and at
     * least one person has booked.
     *
     * **An event nobody booked has no door to hold**, so it is not offered
     * — that is the difference between this list and the article list.
     *
     * Sorted by nearness to today when the event has a date, by creation
     * descending otherwise: on the evening of the 14th, the dinner of the
     * 14th is the first row, and last year's edition of the same dinner is
     * far down.
     *
     * `closes_at` is deliberately NOT a filter. It closes the
     * REGISTRATIONS — a dinner on 14 March closes its bookings on the 10th
     * — so filtering on it would hide the event on precisely the evening
     * it is being controlled.
     *
     * @return array<int, array{form_id: int, article_id: int, title: string, event_date: ?string, event_location: ?string, seats: int}>
     */
    public function listControllableEvents(string $query = '', ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $needle = self::normalizeForSearch($query);

        $events = [];
        foreach ($this->forms->findAllIssuingTickets() as $form) {
            $article = $this->articles->findById($form->newsArticleId);
            if ($article === null) {
                continue;
            }

            $responses = $this->responses->findByFormId($form->id);
            if ($responses === []) {
                continue;
            }

            if ($needle !== '' && !str_contains(self::normalizeForSearch($article->title . ' ' . ($form->eventLocation ?? '')), $needle)) {
                continue;
            }

            $events[] = [
                'form_id' => $form->id,
                'article_id' => $article->id,
                'title' => $article->title,
                'event_date' => $form->eventDate,
                'event_location' => $form->eventLocation,
                'seats' => $this->countSeats($this->fields->findByFormId($form->id), $responses),
                // Through Core\Service\DateInput, never the raw
                // constructor: an empty or malformed stored value answers
                // « today » there, believed and sorted on (SECURITY.md
                // § 35). A date this cannot read sorts with the undated.
                '_sort_distance' => self::daysFromToday($today, $form->eventDate),
                '_sort_created' => $article->createdAt,
            ];
        }

        usort($events, static function (array $a, array $b): int {
            return [$a['_sort_distance'], $b['_sort_created']] <=> [$b['_sort_distance'], $a['_sort_created']];
        });

        return array_map(
            static fn (array $e) => array_diff_key($e, ['_sort_distance' => null, '_sort_created' => null]),
            $events
        );
    }

    /**
     * How far an event's date is from today, either way — what puts the
     * dinner of the 14th at the top of the list on the evening of the
     * 14th, and last year's edition far down.
     *
     * An event with no date, or with one that will not read, sorts with
     * the undated and falls back to creation order.
     */
    private static function daysFromToday(\DateTimeImmutable $today, ?string $eventDate): int
    {
        $parsed = $eventDate !== null ? DateInput::iso($eventDate) : null;

        return $parsed !== null ? abs((int) $today->diff($parsed)->days) : PHP_INT_MAX;
    }

    /**
     * The head-of-screen counters: seats sold, people in, still expected.
     *
     * **The kitchen needs these more than the door does** — knowing that
     * 80 of 120 meals have been served is what decides whether to start
     * another pan. They are counts over data that is already there.
     *
     * @return array{sold: int, entered: int, expected: int, responses: int, used_responses: int}
     */
    public function counters(NewsForm $form): array
    {
        $fields = $this->fields->findByFormId($form->id);
        $responses = $this->responses->findByFormId($form->id);
        $used = array_values(array_filter($responses, static fn (FormResponse $r) => $r->isTicketUsed()));

        $sold = $this->countSeats($fields, $responses);
        $entered = $this->countSeats($fields, $used);

        return [
            'sold' => $sold,
            'entered' => $entered,
            'expected' => max(0, $sold - $entered),
            'responses' => count($responses),
            'used_responses' => count($used),
        ];
    }

    /**
     * What the door should say about what was scanned or typed.
     *
     * The five cases the screen has to cover live here rather than in the
     * template: valid, already used (with the time of the previous entry),
     * a ticket for ANOTHER event (named, because « introuvable » would
     * send somebody looking for a fault that does not exist), not found,
     * and — inside the first two — whether a payment is even expected.
     *
     * @return array{status: string, response: ?FormResponse, form: ?NewsForm, article_title: ?string, event_date: ?string, seats: array<int, array{label: string, quantity: string}>, seat_total: int, payment: ?array{amount_due: int, amount_received: int, status: string, receivable_id: int}, used_at: ?string, holder: ?string}
     */
    public function verdictFor(NewsForm $form, string $scanned): array
    {
        $response = $this->tickets->findByReference($scanned);
        if ($response === null) {
            return self::emptyVerdict(self::STATUS_NOT_FOUND);
        }

        return $this->verdictForResponse($form, $response);
    }

    /**
     * The same verdict, for a response already resolved — what the name
     * and e-mail search hands over once somebody picks a row.
     *
     * @return array{status: string, response: ?FormResponse, form: ?NewsForm, article_title: ?string, event_date: ?string, seats: array<int, array{label: string, quantity: string}>, seat_total: int, payment: ?array{amount_due: int, amount_received: int, status: string, receivable_id: int}, used_at: ?string, holder: ?string}
     */
    public function verdictForResponse(NewsForm $form, FormResponse $response): array
    {
        $ticketForm = $response->formId === $form->id ? $form : $this->forms->findById($response->formId);
        $article = $ticketForm !== null ? $this->articles->findById($ticketForm->newsArticleId) : null;

        $status = match (true) {
            $response->formId !== $form->id => self::STATUS_OTHER_EVENT,
            $response->isTicketUsed() => self::STATUS_USED,
            default => self::STATUS_VALID,
        };

        $fields = $ticketForm !== null ? $this->fields->findByFormId($ticketForm->id) : [];
        $answers = $this->responses->getValues($response->id);

        return [
            'status' => $status,
            'response' => $response,
            'form' => $ticketForm,
            'article_title' => $article?->title,
            'event_date' => $ticketForm?->eventDate,
            'seats' => $this->seatLines($fields, $answers),
            'seat_total' => $this->seatsForAnswers($fields, $answers),
            'payment' => $this->paymentFor($response),
            'used_at' => $response->ticketUsedAt,
            // The name the form collected, not the e-mail address: at the
            // door somebody says « Roskam », and an address is what the
            // screen falls back to when the form asked for no name at all.
            'holder' => $this->describeHolder($fields, $answers, $response),
        ];
    }

    /**
     * The responses of one event matching a free-text query — a reference,
     * a name, or an e-mail address, in one field.
     *
     * The QR fails more often than one expects: a mailbox nobody can find,
     * a flat phone, somebody who came in the place of whoever booked. The
     * previous site only ever searched by reference, which is useless
     * precisely in those cases.
     *
     * @return array<int, array{response: FormResponse, label: string, used_at: ?string, seat_total: int}>
     */
    public function searchResponses(NewsForm $form, string $query): array
    {
        $needle = self::normalizeForSearch($query);
        if ($needle === '') {
            return [];
        }

        $fields = $this->fields->findByFormId($form->id);
        $matches = [];

        foreach ($this->responses->findByFormId($form->id) as $response) {
            $answers = $this->responses->getValues($response->id);
            $haystack = self::normalizeForSearch(
                $response->contactEmail . ' ' . implode(' ', $answers) . ' ' . (string) $response->ticketReference
            );
            if (!str_contains($haystack, $needle)) {
                continue;
            }

            $matches[] = [
                'response' => $response,
                'label' => $this->describeHolder($fields, $answers, $response),
                'used_at' => $response->ticketUsedAt,
                'seat_total' => $this->seatsForAnswers($fields, $answers),
            ];

            if (count($matches) >= self::SEARCH_RESULT_LIMIT) {
                break;
            }
        }

        return $matches;
    }

    /**
     * The whole event's expected attendees, for the printable list —
     * name, seats, payment state — which is the fallback for the decision
     * that a connection is required. A list printed the evening before
     * and ticked with a pen is what stops an evening collapsing because
     * the network went down.
     *
     * @return array<int, array{label: string, reference: string, seats: array<int, array{label: string, quantity: string}>, seat_total: int, payment: ?array{amount_due: int, amount_received: int, status: string, receivable_id: int}}>
     */
    public function expectedAttendees(NewsForm $form): array
    {
        $fields = $this->fields->findByFormId($form->id);

        $rows = [];
        foreach ($this->responses->findByFormId($form->id) as $response) {
            $answers = $this->responses->getValues($response->id);
            $rows[] = [
                'label' => $this->describeHolder($fields, $answers, $response),
                'reference' => $response->hasTicket() ? TicketService::format((string) $response->ticketReference) : '—',
                'seats' => $this->seatLines($fields, $answers),
                'seat_total' => $this->seatsForAnswers($fields, $answers),
                'payment' => $this->paymentFor($response),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * Whether this form expects money at all.
     *
     * A ticketed event can be free, and then no receivable exists —
     * showing a « payé/impayé » about one would invite somebody to go
     * looking for a receivable that was never created.
     */
    public function expectsPayment(NewsForm $form): bool
    {
        if ($this->expectedReceivable === null || $form->financeAccountId === null) {
            return false;
        }

        foreach ($this->fields->findByFormId($form->id) as $field) {
            if ($field->isPriced()) {
                return true;
            }
        }

        return false;
    }

    /**
     * How this response should be named to a human at the door.
     *
     * The first short-text answer, which on every real form is « Nom » or
     * « Nom de famille », else the contact address — which is always
     * there, being structural. Never an id.
     *
     * @param FormField[] $fields
     * @param array<int, string> $answers
     */
    private function describeHolder(array $fields, array $answers, FormResponse $response): string
    {
        foreach ($fields as $field) {
            if ($field->fieldType !== FormField::TYPE_SHORT_TEXT) {
                continue;
            }
            $value = trim((string) ($answers[$field->id] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $response->contactEmail;
    }

    /**
     * What this response booked, in words — « Repas adulte × 2 ».
     *
     * @param FormField[] $fields
     * @param array<int, string> $answers
     * @return array<int, array{label: string, quantity: string}>
     */
    private function seatLines(array $fields, array $answers): array
    {
        $lines = [];
        foreach ($fields as $field) {
            if (!self::isSeatField($field)) {
                continue;
            }
            $quantity = trim((string) ($answers[$field->id] ?? ''));
            if ($quantity === '' || (float) $quantity <= 0) {
                continue;
            }
            $lines[] = ['label' => (string) $field->label, 'quantity' => $quantity];
        }

        return $lines;
    }

    /**
     * @param FormField[] $fields
     * @param FormResponse[] $responses
     */
    private function countSeats(array $fields, array $responses): int
    {
        $total = 0;
        foreach ($responses as $response) {
            $total += $this->seatsForAnswers($fields, $this->responses->getValues($response->id));
        }

        return $total;
    }

    /**
     * A response's own seat count.
     *
     * **One when the form declares no quantity field at all**: a plain
     * sign-up is one person coming, and reporting zero sold on a form that
     * clearly sold something would make the counters lie.
     *
     * @param FormField[] $fields
     * @param array<int, string> $answers
     */
    private function seatsForAnswers(array $fields, array $answers): int
    {
        $seatFields = array_values(array_filter($fields, static fn (FormField $f) => self::isSeatField($f)));
        if ($seatFields === []) {
            return 1;
        }

        $total = 0;
        foreach ($seatFields as $field) {
            $total += (int) (float) ($answers[$field->id] ?? '0');
        }

        return $total;
    }

    /**
     * A quantity field: a number that carries a price or a capacity.
     *
     * Those two columns are what make a field say « how many people »
     * rather than « what size of t-shirt » — a plain number field asking
     * for an age would otherwise be counted as thirty-eight seats.
     */
    private static function isSeatField(FormField $field): bool
    {
        return $field->fieldType === FormField::TYPE_NUMBER
            && ($field->pricePerUnit !== null || $field->capacityMax !== null);
    }

    /**
     * @return ?array{amount_due: int, amount_received: int, status: string, receivable_id: int}
     */
    private function paymentFor(FormResponse $response): ?array
    {
        if ($response->receivableId === null || $this->expectedReceivable === null) {
            return null;
        }

        return $this->expectedReceivable->getReceivableStatus($response->receivableId)
            + ['receivable_id' => $response->receivableId];
    }

    /**
     * @return array{status: string, response: ?FormResponse, form: ?NewsForm, article_title: ?string, event_date: ?string, seats: array<int, array{label: string, quantity: string}>, seat_total: int, payment: ?array{amount_due: int, amount_received: int, status: string, receivable_id: int}, used_at: ?string, holder: ?string}
     */
    private static function emptyVerdict(string $status): array
    {
        return [
            'status' => $status,
            'response' => null,
            'form' => null,
            'article_title' => null,
            'event_date' => null,
            'seats' => [],
            'seat_total' => 0,
            'payment' => null,
            'used_at' => null,
            'holder' => null,
        ];
    }

    /**
     * Lowercased and stripped of accents, so « Vandenbrande » finds
     * « vandenbrandé » and neither of them depends on how a tired animateur
     * types at nine in the evening.
     */
    private static function normalizeForSearch(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return mb_strtolower($folded !== false ? $folded : $value);
    }
}
