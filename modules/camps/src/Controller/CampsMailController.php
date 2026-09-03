<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Mail\MailFieldCompletionService;
use Modules\Camps\Repository\FieldProposalRepository;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Mail\ExistingStayMatcher;
use Modules\Camps\Service\CampLabels;
use Modules\Camps\Service\StaySearchService;
use Modules\InboundMail\Api\InboundMailInterface;
use Twig\Environment;

/**
 * « Courrier des camps » — the business triage list, this module's view of
 * it (§8.58, IT-07).
 *
 * **It used to be « Courrier non classé »**, backed by a reserved
 * `unsorted` business reference: a bucket masquerading as a stay, with its
 * own retention, its own purge task and its own screen, all duplicating
 * what `inbound_mail` now does once for every module. The reference is
 * gone; what a dedicated box collects and nobody could attribute is simply
 * stored like every other message, and this screen reads it through
 * `InboundMailInterface::findForTriage()` — the same call every other
 * consumer will make.
 *
 * The list is therefore what a chief can *do something about*: what this
 * module attached to a stay, what it merely proposed, and — on a box the
 * superadmin declared dedicated to camps — everything else that box holds.
 * A shared box contributes only what concerns camps, which is exactly the
 * point of the configuration screen.
 *
 * Reachable from the camps list rather than from a menu entry of its own:
 * an installation with no mailbox never has anything here, and a permanent
 * menu entry that is permanently empty teaches people to ignore it.
 */
class CampsMailController extends AbstractController
{
    /** How much of a message the card shows before it has to be opened. */
    private const EXCERPT_LENGTH = 220;

    /**
     * One screenful. A dedicated box that has been collecting for three
     * years holds thousands of messages, and a page that renders all of
     * them is a page nobody waits for. The Chef d'Unité's own screen
     * (`/courrier`) is the one with pagination; this one is a work list.
     */
    public const MAX_MESSAGES = 100;

    public function __construct(
        protected Environment $twig,
        private CampRepository $camps,
        private ?InboundMailInterface $inboundMail = null,
        private ?FieldProposalRepository $proposals = null,
        private ?MailFieldCompletionService $fieldCompletion = null,
        /**
         * « Quel séjour ? » (`Service\StaySearchService`). Null keeps the
         * short list this screen renders and gives up the search box: a
         * caller that did not build it gets the control it always had.
         */
        private ?StaySearchService $staySearch = null,
        /**
         * What the message itself says about its dates
         * (`Mail\ExistingStayMatcher`), so the picker opens on the stay
         * before anybody types. Optional in the same way and for the same
         * reason as everything else here.
         */
        private ?ExistingStayMatcher $existingStay = null
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function unsorted(Request $request, array $params): Response
    {
        $all = $this->messages();
        $status = self::status((string) $request->getQuery('statut', ''));

        return $this->render('@camps/unsorted_mail.html.twig', [
            'messages' => self::filtered($all, $status),
            'can_search_stays' => $this->staySearch !== null,
            'has_inbound_mail' => $this->inboundMail !== null && $this->inboundMail->isCollecting(),
            'status' => $status,
            'counts' => [
                self::STATUS_UNLINKED => count(self::filtered($all, self::STATUS_UNLINKED)),
                self::STATUS_LINKED => count(self::filtered($all, self::STATUS_LINKED)),
                self::STATUS_ALL => count($all),
            ],
            'breadcrumb_current' => 'Courrier des camps',
        ]);
    }

    /**
     * The three answers the filter can take, and the default.
     *
     * **Unattached is the default, and that is the point of the screen.**
     * What a chief comes here to do is decide about the mail nobody could
     * attribute; the messages this module already filed are on their
     * stays' own pages, and a list that opens on everything buries the
     * dozen that need a decision under the hundreds that do not.
     */
    public const STATUS_UNLINKED = 'non_rattaches';
    public const STATUS_LINKED = 'rattaches';
    public const STATUS_ALL = 'tous';

    /** An unknown value reads as the default rather than as an error. */
    private static function status(string $raw): string
    {
        return in_array($raw, [self::STATUS_LINKED, self::STATUS_ALL], true) ? $raw : self::STATUS_UNLINKED;
    }

    /**
     * Filtered here rather than in SQL, deliberately: the read is already
     * bounded to one screenful by `findForTriage()`, the association is
     * this MODULE's (`linksFor()`) rather than any link the message
     * carries, and a filter that had to travel through
     * `Api\InboundMailInterface` would be a query shape every consumer
     * inherits for one screen's sake.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function filtered(array $rows, string $status): array
    {
        if ($status === self::STATUS_ALL) {
            return $rows;
        }

        $wantLinked = $status === self::STATUS_LINKED;

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => (($row['links'] ?? []) !== []) === $wantLinked
        ));
    }

    /**
     * « Relancer l'analyse » — offer every unattributed message to this
     * module again.
     *
     * The button exists because the site's knowledge moves and the mail
     * already collected does not follow it: a chief attaches one e-mail of
     * a thread to a stay and the rest of that thread becomes attributable,
     * a place is created and a farmer's address starts matching, a contact
     * is added to a camp. A message analysed the day it arrived was
     * analysed against what the site knew that day, and nothing ever went
     * back to ask again.
     *
     * The answer comes in two parts, and the flash says both: what this
     * request settled, and what the hourly task will — reading an
     * attachment's text or calling a model is not something a chief should
     * watch a page spin through.
     *
     * @param array<string, string> $params
     */
    public function reanalyze(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/chefs/camps/courrier')) !== null) {
            return $guard;
        }

        if ($this->inboundMail === null) {
            return $this->notFound();
        }

        $report = $this->inboundMail->reanalyzeUnlinked(CampsMessageConsumer::CONSUMER_ID, self::MAX_MESSAGES);

        FlashMessage::set('success', self::reanalysisMessage($report));

        return $this->redirect('/chefs/camps/courrier');
    }

    /**
     * What happened, in the words a chief would use — and « rien de neuf »
     * said plainly rather than dressed up.
     *
     * A run that changes nothing is the ordinary outcome and has to read
     * like one: the alternative is a button whose success message always
     * sounds like something happened, which teaches people to stop reading
     * it.
     *
     * @param array{examined: int, linked: int, proposed: int} $report
     */
    private static function reanalysisMessage(array $report): string
    {
        if ($report['examined'] === 0) {
            return 'Aucun message en attente : tout ce qui est conservé est déjà rattaché.';
        }

        $found = [];
        if ($report['linked'] > 0) {
            $found[] = $report['linked'] . ' rattachement' . ($report['linked'] > 1 ? 's' : '');
        }
        if ($report['proposed'] > 0) {
            $found[] = $report['proposed'] . ' proposition' . ($report['proposed'] > 1 ? 's' : '');
        }

        return sprintf(
            '%d message%s réexaminé%s : %s. La lecture des pièces jointes se poursuit en arrière-plan.',
            $report['examined'],
            $report['examined'] > 1 ? 's' : '',
            $report['examined'] > 1 ? 's' : '',
            $found === [] ? 'rien de neuf pour l\'instant' : implode(' et ', $found)
        );
    }

    /**
     * Confirm one of this module's propositions.
     *
     * Through `InboundMailInterface`, which re-checks that the proposition
     * belongs to this consumer AND targets a stay this caller may reach —
     * the controller's own list is a convenience, never the guard.
     *
     * @param array<string, string> $params
     */
    public function confirmProposition(Request $request, array $params): Response
    {
        return $this->decideProposition($request, $params, true);
    }

    /**
     * @param array<string, string> $params
     */
    public function dismissProposition(Request $request, array $params): Response
    {
        return $this->decideProposition($request, $params, false);
    }

    /**
     * @param array<string, string> $params
     */
    private function decideProposition(Request $request, array $params, bool $confirm): Response
    {
        if (($guard = $this->guardCsrf($request, '/chefs/camps/courrier')) !== null) {
            return $guard;
        }

        if ($this->inboundMail === null) {
            return $this->notFound();
        }

        $messageId = (int) ($params['id'] ?? 0);
        $candidateId = (int) $request->getBody('candidate_id', 0);
        $references = $this->stayReferences();

        $done = $confirm
            ? $this->inboundMail->confirmCandidate(
                CampsMessageConsumer::CONSUMER_ID,
                $references,
                $messageId,
                $candidateId,
                AuthSession::getUserAccountId()
            )
            : $this->inboundMail->dismissCandidate(
                CampsMessageConsumer::CONSUMER_ID,
                $references,
                $messageId,
                $candidateId
            );

        FlashMessage::set(
            $done ? 'success' : 'error',
            $done
                ? ($confirm ? 'Message rattaché au séjour.' : 'Proposition écartée.')
                : 'Cette proposition n\'existe plus.'
        );

        return $this->redirect('/chefs/camps/courrier');
    }

    /**
     * Every stay this module has, as references.
     *
     * A chief may reach all of them — a stay has no manager list of its
     * own, and `canRead()` says the same thing. The list exists so that
     * `inbound_mail` can scope without knowing what a stay is.
     *
     * @return string[]
     */
    private function stayReferences(): array
    {
        return array_map(
            static fn(int $campId): string => CampsMessageConsumer::referenceFor($campId),
            $this->camps->findAllIds()
        );
    }

    /**
     * Moves a message onto a stay.
     *
     * A plain move() between two references of this consumer — no
     * extension to inbound_mail was needed for any of this, which is what
     * the reserved 'unsorted' reference buys.
     *
     * @param array<string, string> $params
     */
    public function attach(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/chefs/camps/courrier')) !== null) {
            return $guard;
        }
        $messageId = (int) ($params['id'] ?? 0);
        $campId = (int) $request->getBody('camp_id', 0);

        if ($this->inboundMail === null || $campId <= 0 || $this->camps->findById($campId) === null) {
            FlashMessage::set('error', 'Choisissez le séjour auquel rattacher ce message.');

            return $this->redirect('/chefs/camps/courrier');
        }

        // An association, not a move: there is no `unsorted` reference to
        // move the message OFF any more, and a message that is already on
        // another stay is being corrected rather than relocated — which
        // the chief does by detaching it from that stay.
        $moved = $this->inboundMail->attach(
            CampsMessageConsumer::CONSUMER_ID,
            CampsMessageConsumer::referenceFor($campId),
            $messageId,
            AuthSession::getUserAccountId()
        );

        FlashMessage::set(
            $moved ? 'success' : 'error',
            $moved ? 'Message rattaché au séjour.' : 'Ce message n\'a pas pu être rattaché.'
        );

        return $this->redirect($moved ? '/chefs/camps/sejours/' . $campId : '/chefs/camps/courrier');
    }

    /**
     * @param array<string, string> $params
     */
    public function discard(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/chefs/camps/courrier')) !== null) {
            return $guard;
        }

        if ($this->inboundMail !== null) {
            // detach() does not destroy the message: it falls back into the
            // unit's general mail, where a chef d'unité can still re-orient
            // it and where inbound_mail's own retention removes it. The
            // wording says what actually happens.
            $this->inboundMail->detach(
                CampsMessageConsumer::CONSUMER_ID,
                (string) $request->getBody('business_reference', ''),
                (int) ($params['id'] ?? 0)
            );
            FlashMessage::set('success', 'Message retiré de ce séjour.');
        }

        return $this->redirect('/chefs/camps/courrier');
    }

    /**
     * @param array<string, string> $params
     */
    public function applyProposal(Request $request, array $params): Response
    {
        return $this->decideProposal($request, $params, true);
    }

    /**
     * @param array<string, string> $params
     */
    public function dismissProposal(Request $request, array $params): Response
    {
        return $this->decideProposal($request, $params, false);
    }

    /**
     * @param array<string, string> $params
     */
    private function decideProposal(Request $request, array $params, bool $accept): Response
    {
        if ($this->proposals === null || $this->fieldCompletion === null) {
            return $this->notFound();
        }
        $proposal = $this->proposals->findById((int) ($params['id'] ?? 0));
        if ($proposal === null) {
            return $this->notFound();
        }

        $target = '/chefs/camps/sejours/' . $proposal->campId;
        if (($guard = $this->guardCsrf($request, $target)) !== null) {
            return $guard;
        }

        $actorId = AuthSession::getUserAccountId();
        if ($accept) {
            $this->fieldCompletion->accept($proposal, $actorId);
            FlashMessage::set('success', 'Information appliquée au séjour.');
        } else {
            // Recorded too: six months later somebody will ask why the
            // page does not say what the mail says.
            $this->fieldCompletion->dismiss($proposal, $actorId);
            FlashMessage::set('success', 'Information ignorée.');
        }

        return $this->redirect($target);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function messages(): array
    {
        if ($this->inboundMail === null) {
            return [];
        }

        $messages = $this->inboundMail->findForTriage(
            CampsMessageConsumer::CONSUMER_ID,
            $this->stayReferences(),
            self::MAX_MESSAGES
        );

        $candidates = $this->inboundMail->findCandidatesFor(
            CampsMessageConsumer::CONSUMER_ID,
            array_map(static fn($message) => $message->id, $messages)
        );

        $rows = [];
        foreach ($messages as $message) {
            $body = trim($message->bodyText);
            $rows[] = [
                'message' => $message,
                'excerpt' => mb_substr($body, 0, self::EXCERPT_LENGTH),
                // Whether the excerpt actually cut something off, so the
                // screen can promise "there is more" only when there is.
                'truncated' => mb_strlen($body) > self::EXCERPT_LENGTH,
                'has_body' => $body !== '' || trim($message->bodyHtml) !== '',
                'attachment_count' => count($message->attachments),
                // Only THIS module's links and propositions. Another
                // module's business on the same message is not this
                // screen's, and showing it would leak one module's guesses
                // into another's audience.
                'links' => $message->linksFor(CampsMessageConsumer::CONSUMER_ID),
                'candidates' => $candidates[$message->id] ?? [],
                // Its own shortlist, because the stay its own dates name
                // belongs at the top of ITS list and nowhere else. One
                // query for the lot — `Service\StaySearchService` reads
                // the stays once and ranks them per message.
                'preferred_stay_ids' => $preferred = $this->preferredStayIds($message),
                'camp_options' => $this->campOptions($preferred),
            ];
        }

        return $rows;
    }

    /**
     * How many stays the `<select>` behind the search box holds.
     *
     * That control is the answer when JavaScript does not run, and it is
     * NOT the old one: it used to hold the cross product of every visible
     * place and every stay it ever hosted — two hundred lines on a unit in
     * its tenth year, built with one query per place. This is the same
     * ranked shortlist the search box opens on, which is the right answer
     * far more often than it is not.
     */
    private const PICKER_OPTIONS = 20;

    /**
     * The stays this particular message is likely to be about.
     *
     * The same reading the automatic pass uses (`Mail\ExistingStayMatcher`)
     * — the period the message announces — so the line a chief lands on is
     * the line ScoutMagic would have chosen if it had been sure. It is a
     * suggestion here and nothing more: this screen exists precisely for
     * the messages where nobody was sure.
     *
     * Only the subject and the body, never the attachments: this runs
     * while a page is being rendered, and opening a hundred files to sort
     * a hundred suggestions is not something a chief should wait for.
     *
     * @return int[]
     */
    private function preferredStayIds(\Modules\InboundMail\Api\InboundMessage $message): array
    {
        if ($this->existingStay === null) {
            return [];
        }

        return array_map(
            static fn(Camp $camp): int => $camp->id,
            $this->existingStay->matching(trim($message->subject . "\n" . $message->bodyText))
        );
    }

    /**
     * The shortlist for one message, as `partials/form_field.html.twig`
     * wants it.
     *
     * @param int[] $preferredIds
     * @return array<int, array{value: string, label: string, selected: bool}>
     */
    private function campOptions(array $preferredIds): array
    {
        $options = [['value' => '', 'label' => 'Choisir un séjour…', 'selected' => true]];
        foreach ($this->staySearch?->search('', $preferredIds, self::PICKER_OPTIONS) ?? [] as $stay) {
            $options[] = [
                'value' => (string) $stay['id'],
                'label' => $stay['label'],
                'selected' => false,
            ];
        }

        return $options;
    }

    /**
     * GET /chefs/camps/courrier/sejours — « quel séjour ? », answered as
     * the chief types.
     *
     * Read-only and bounded, like every other search endpoint here: it
     * answers with at most `StaySearchService::LIMIT` stays of the unit,
     * named the way the screens name them. There is nothing here a chief
     * may not already read on /chefs/camps — this route only spares them
     * the trip.
     *
     * @param array<string, string> $params
     */
    public function searchStays(Request $request, array $params): Response
    {
        if ($this->staySearch === null) {
            return $this->json(['success' => true, 'stays' => []]);
        }

        return $this->json([
            'success' => true,
            'stays' => $this->staySearch->search(
                (string) $request->getQuery('q', ''),
                self::idList((string) $request->getQuery('preferred', ''))
            ),
        ]);
    }

    /**
     * `"12,45"` as `[12, 45]`, and anything else as nothing.
     *
     * These ids come from the page's own markup, so they are a hint about
     * ORDER and never an authorisation: every id the answer names was
     * going to be readable by this caller anyway, and one that matches no
     * stay simply ranks nothing.
     *
     * @return int[]
     */
    private static function idList(string $raw): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return $ids;
    }
}
