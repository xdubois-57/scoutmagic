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
use Modules\InboundMail\Api\ReanalysisReport;
use Modules\InboundMail\Api\TriageFilter;
use Modules\InboundMail\Api\TriageScreen;
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
        $filter = TriageFilter::fromQuery((string) $request->getQuery('statut', ''));
        $triage = TriageScreen::of(
            $this->messages(),
            $filter,
            (string) $request->getQuery('automatique', '') === '1',
            $filter === TriageFilter::DISMISSED ? $this->messages(dismissed: true) : [],
            $this->dismissedCount()
        )->toArray();

        // The stays as a chief names them, once for the page: « Rattaché —
        // camp-51 » told nobody anything, and the label the picker already
        // shows is the one the badge should carry.
        $labels = [];
        $urls = [];
        foreach ($this->camps->findAllWithPlaceName() as $row) {
            $reference = CampsMessageConsumer::referenceFor($row['camp']->id);
            $labels[$reference] = StaySearchService::labelFor($row['camp'], $row['place_name']);
            $urls[$reference] = '/chefs/camps/sejours/' . $row['camp']->id;
        }

        return $this->render('@camps/unsorted_mail.html.twig', $triage + [
            'can_search_stays' => $this->staySearch !== null,
            // Whether the module is there at all: its views are registered
            // only while it is enabled, so without it the shared screen
            // cannot even be named.
            'inbound_mail_enabled' => $this->inboundMail !== null,
            'has_inbound_mail' => $this->inboundMail !== null && $this->inboundMail->isCollecting(),
            'labels' => $labels,
            'reference_urls' => $urls,
            'breadcrumb_current' => 'Courrier des camps',
        ]);
    }

    /**
     * « Ce courrier ne concerne pas les camps » (#174).
     *
     * A dedicated camps box collects newsletters, delivery receipts and
     * out-of-office replies alongside the booking contracts, and until now
     * the only way to stop seeing one was to wait ninety days for the
     * retention: the dozen messages needing a decision were buried under
     * hundreds that never will.
     *
     * **It deletes nothing and protects nothing.** The message stays in
     * the unit's general mail, another module that cares about it is
     * unaffected, and the unassociated-mail retention removes it on
     * exactly the day it would have anyway — « écarter » must not quietly
     * mean « conserver » (A3).
     *
     * @param array<string, string> $params
     */
    public function setAside(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/chefs/camps/courrier')) !== null) {
            return $guard;
        }

        $done = $this->inboundMail?->dismissMessage(
            CampsMessageConsumer::CONSUMER_ID,
            $this->stayReferences(),
            (int) ($params['id'] ?? 0),
            AuthSession::getUserAccountId()
        ) ?? false;

        FlashMessage::set(
            $done ? 'success' : 'error',
            $done
                // Said in full, because the button does less than the word
                // suggests and a chief must not believe they deleted mail.
                ? 'Courrier écarté de la liste des camps. Il reste dans le courrier de l\'unité.'
                : 'Ce courrier n\'a pas pu être écarté.'
        );

        return $this->redirect('/chefs/camps/courrier');
    }

    /**
     * The undo, and the reason setting aside is a row rather than a
     * deletion: a chief who wrote off the wrong message finds it under
     * « Écartés » and puts it back.
     *
     * @param array<string, string> $params
     */
    public function restore(Request $request, array $params): Response
    {
        $back = '/chefs/camps/courrier?statut=' . TriageFilter::DISMISSED->value;
        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $done = $this->inboundMail?->restoreMessage(
            CampsMessageConsumer::CONSUMER_ID,
            $this->stayReferences(),
            (int) ($params['id'] ?? 0)
        ) ?? false;

        FlashMessage::set(
            $done ? 'success' : 'error',
            $done ? 'Courrier remis dans la liste.' : 'Ce courrier n\'a pas pu être remis dans la liste.'
        );

        return $this->redirect($back);
    }

    private function dismissedCount(): int
    {
        return $this->inboundMail?->countDismissedMessages(
            CampsMessageConsumer::CONSUMER_ID,
            $this->stayReferences()
        ) ?? 0;
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

        FlashMessage::set('success', ReanalysisReport::fromArray($report)->message());

        return $this->redirect('/chefs/camps/courrier');
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
    private function messages(bool $dismissed = false): array
    {
        if ($this->inboundMail === null) {
            return [];
        }

        return array_map(
            fn(array $row): array => $row + [
                // Its own shortlist, because the stay its own dates name
                // belongs at the top of ITS list and nowhere else. One
                // query for the lot — `Service\StaySearchService` reads
                // the stays once and ranks them per message.
                'preferred_stay_ids' => $preferred = $this->preferredStayIds($row['message']),
                'camp_options' => $this->campOptions($preferred),
            ],
            $this->inboundMail->triageRows(
                CampsMessageConsumer::CONSUMER_ID,
                $this->stayReferences(),
                self::MAX_MESSAGES,
                $dismissed
            )
        );
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
