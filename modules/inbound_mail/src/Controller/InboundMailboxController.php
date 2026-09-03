<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\InboundMail\Api\ReferenceDirectory;
use Modules\InboundMail\Api\ReferenceSuggestion;
use Modules\InboundMail\Service\GeneralMailboxService;
use Modules\InboundMail\Service\InboundMailService;
use Modules\InboundMail\Service\ManualRefreshService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Twig\Environment;

/**
 * `/courrier` — the unit's whole mail, for the **Chef d'Unité and nobody
 * else**.
 *
 * This screen is one of the three things that make storing every message
 * defensible (§8.58). The other two are the retention that removes what
 * nothing points at, and the fact that exactly one role answers for the
 * archive — which is this route's `role_min`, and why there is no second
 * route here at a lower one.
 *
 * **Nothing here searches the content.** The filters are metadata only
 * (D16): mailbox, whether the message is associated, whether it is
 * automatic. Searching a subject or a body would mean either decrypting
 * the whole table on every keystroke or keeping a plaintext index of
 * everything anybody ever wrote to the unit, and the second is how an
 * archive with a retention becomes an archive without one.
 */
class InboundMailboxController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private GeneralMailboxService $mailbox,
        private InboundMailService $inboundMail,
        private MessageConsumerRegistry $consumers,
        private JournalService $journal,
        /**
         * « Rafraîchir maintenant » for the chief who is waiting for a
         * reply. Null means the button is not offered rather than offered
         * and inert. The same closure-backed service the configuration
         * page uses, lock included.
         */
        private ?ManualRefreshService $refreshService = null
    ) {
    }

    /** How many targets one search returns. */
    private const SEARCH_LIMIT = 10;

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $association = (string) $request->getQuery('association', 'none');
        if (!in_array($association, ['none', 'proposed', 'some', 'all'], true)) {
            $association = 'none';
        }

        $mailboxId = (int) $request->getQuery('boite', 0);
        $includeBulk = (string) $request->getQuery('automatique', '') === '1';

        $filters = [
            'mailbox_id' => $mailboxId > 0 ? $mailboxId : null,
            'association' => $association,
            'include_bulk' => $includeBulk,
        ];

        $page = $this->mailbox->page(
            $filters,
            GeneralMailboxService::decodeCursor((string) $request->getQuery('apres', ''))
        );

        $candidates = $this->candidatesFor($page['messages']);

        return $this->render('@inbound_mail/mailbox/index.html.twig', [
            'messages' => $page['messages'],
            'next_cursor' => $page['next_cursor'],
            'mailboxes' => $this->mailbox->mailboxNames(),
            'consumer_names' => $this->consumerNames(),
            'candidates' => $candidates,
            'reference_labels' => $this->referenceLabels($page['messages'], $candidates),
            'filter_association' => $association,
            // What tells « voilà tout mon courrier » from « le filtre en
            // cache deux cents » — see the template.
            'association_counts' => $this->mailbox->counts($filters),
            'filter_mailbox' => $mailboxId,
            'include_bulk' => $includeBulk,
            'bulk_count' => $this->mailbox->bulkCount(),
            'can_refresh' => $this->refreshService !== null,
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /courrier/rafraichir — relève the boxes now, for the chief who
     * is waiting for a reply and does not have the configuration page.
     *
     * @param array<string, string> $params
     */
    public function refreshNow(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/courrier')) !== null) {
            return $guard;
        }

        if ($this->refreshService === null) {
            return $this->notFound();
        }

        $outcome = $this->refreshService->refresh(new \DateTimeImmutable());
        FlashMessage::set($outcome['ok'] ? 'success' : 'warning', $outcome['message']);

        return $this->redirect('/courrier');
    }

    /**
     * GET /courrier/cibles?module=&q= — the objects a module offers for
     * « Rattacher à… », from its own directory (`Api\ReferenceDirectory`).
     *
     * @param array<string, string> $params
     */
    public function searchTargets(Request $request, array $params): Response
    {
        $directory = $this->directoryFor((string) $request->getQuery('module', ''));
        $query = trim((string) $request->getQuery('q', ''));

        if ($directory === null || $query === '') {
            return $this->json(['success' => true, 'targets' => []]);
        }

        try {
            $targets = $directory->searchReferences($query, self::SEARCH_LIMIT);
        } catch (\Throwable) {
            // A consumer that cannot search must not take the field down
            // with it — the same posture every other callback takes.
            $targets = [];
        }

        return $this->json([
            'success' => true,
            'targets' => array_map(
                static fn(ReferenceSuggestion $suggestion): array => $suggestion->toArray(),
                $targets
            ),
        ]);
    }

    /**
     * POST /courrier/{id}/association — orient a message towards an
     * object a person named.
     *
     * The target has to come back from the module's own search: that is
     * what stops a hand-crafted POST filing a message under a reference
     * that does not exist, without this module learning what a reference
     * looks like.
     *
     * @param array<string, string> $params
     */
    public function attach(Request $request, array $params): Response
    {
        $messageId = (int) ($params['id'] ?? 0);
        $back = '/courrier/' . $messageId;

        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $consumerId = (string) $request->getBody('consumer_id', '');
        $reference = trim((string) $request->getBody('business_reference', ''));
        $directory = $this->directoryFor($consumerId);

        if ($directory === null || $reference === '' || !self::isOffered($directory, $reference)) {
            FlashMessage::set('error', 'Choisissez une cible parmi celles proposées.');

            return $this->redirect($back);
        }

        $created = $this->inboundMail->attach($consumerId, $reference, $messageId, AuthSession::getUserAccountId());

        if ($created) {
            // Internal ids only — never the sender, the subject or the
            // target's label (§7.9).
            $this->journal->log(
                'inbound_mail',
                'inbound_link_added',
                'info',
                'Message rattaché depuis le courrier de l\'unité',
                ['message_id' => $messageId, 'consumer_id' => $consumerId],
                null
            );
        }

        FlashMessage::set(
            $created ? 'success' : 'info',
            $created ? 'Message rattaché.' : 'Ce message était déjà rattaché à cette cible.'
        );

        return $this->redirect($back);
    }

    /**
     * The consumer's directory, when it has one and is registered.
     */
    private function directoryFor(string $consumerId): ?ReferenceDirectory
    {
        if ($consumerId === '') {
            return null;
        }

        $consumer = $this->consumers->find($consumerId);

        return $consumer instanceof ReferenceDirectory ? $consumer : null;
    }

    /**
     * Whether the module's own search names this exact reference — the
     * check that makes « choisissez parmi celles proposées » true.
     */
    private static function isOffered(ReferenceDirectory $directory, string $reference): bool
    {
        try {
            foreach ($directory->searchReferences($reference, self::SEARCH_LIMIT) as $suggestion) {
                if ($suggestion->businessReference === $reference) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * The modules a chief may orient a message towards, keyed by id —
     * those whose consumer publishes a directory.
     *
     * @return array<string, string>
     */
    private function directories(): array
    {
        $names = [];
        foreach ($this->consumers->all() as $consumer) {
            if ($consumer instanceof ReferenceDirectory) {
                $names[$consumer->consumerId()] = $consumer->displayName();
            }
        }

        return $names;
    }

    /**
     * Where each rendered object lives, keyed like referenceLabels().
     *
     * @param \Modules\InboundMail\Api\InboundMessage[] $messages
     * @return array<string, array<string, string>>
     */
    private function referenceUrls(array $messages): array
    {
        $urls = [];
        foreach ($messages as $message) {
            foreach ($message->links as $link) {
                $directory = $this->directoryFor($link->consumerId);
                if ($directory === null || isset($urls[$link->consumerId][$link->businessReference])) {
                    continue;
                }

                try {
                    $url = $directory->referenceUrl($link->businessReference);
                } catch (\Throwable) {
                    $url = null;
                }

                if ($url !== null && $url !== '') {
                    $urls[$link->consumerId][$link->businessReference] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $message = $this->mailbox->find((int) ($params['id'] ?? 0));
        if ($message === null) {
            return $this->notFound();
        }

        $candidates = $this->mailbox->candidatesFor($message->id);

        return $this->render('@inbound_mail/mailbox/show.html.twig', [
            'message' => $message,
            'candidates' => $candidates,
            'reference_labels' => $this->referenceLabels([$message], [$message->id => $candidates]),
            'reference_urls' => $this->referenceUrls([$message]),
            'consumer_names' => $this->consumerNames(),
            'directories' => $this->directories(),
            'mailboxes' => $this->mailbox->mailboxNames(),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function confirmCandidate(Request $request, array $params): Response
    {
        $messageId = (int) ($params['id'] ?? 0);
        $back = '/courrier/' . $messageId;

        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $done = $this->mailbox->confirmCandidate(
            $messageId,
            (int) $request->getBody('candidate_id', 0),
            AuthSession::getUserAccountId()
        );

        if ($done) {
            // Internal ids only. Never the sender, the subject, the target's
            // label or a word of what anybody wrote (§7.9).
            $this->journal->log(
                'inbound_mail',
                'inbound_candidate_confirmed',
                'info',
                'Proposition de rattachement confirmée',
                ['message_id' => $messageId],
                null
            );
        }

        FlashMessage::set(
            $done ? 'success' : 'error',
            $done ? 'Message rattaché.' : 'Cette proposition n\'existe plus.'
        );

        return $this->redirect($back);
    }

    /**
     * @param array<string, string> $params
     */
    public function dismissCandidate(Request $request, array $params): Response
    {
        $messageId = (int) ($params['id'] ?? 0);
        $back = '/courrier/' . $messageId;

        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $done = $this->mailbox->dismissCandidate($messageId, (int) $request->getBody('candidate_id', 0));

        FlashMessage::set(
            $done ? 'success' : 'error',
            $done ? 'Proposition écartée.' : 'Cette proposition n\'existe plus.'
        );

        return $this->redirect($back);
    }

    /**
     * Remove one association. The message stays — detaching is almost
     * always a correction, and destroying what is being corrected makes
     * re-filing it impossible (§8.58).
     *
     * @param array<string, string> $params
     */
    public function detach(Request $request, array $params): Response
    {
        $messageId = (int) ($params['id'] ?? 0);
        $back = '/courrier/' . $messageId;

        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $done = $this->inboundMail->detach(
            (string) $request->getBody('consumer_id', ''),
            (string) $request->getBody('business_reference', ''),
            $messageId
        );

        if ($done) {
            $this->journal->log(
                'inbound_mail',
                'inbound_link_removed',
                'info',
                'Association retirée depuis le courrier de l\'unité',
                ['message_id' => $messageId],
                null
            );
        }

        FlashMessage::set(
            $done ? 'success' : 'error',
            $done
                ? 'Message détaché. Il reste dans le courrier de l\'unité.'
                : 'Ce rattachement n\'existe plus.'
        );

        return $this->redirect($back);
    }

    /**
     * @return array<string, string>
     */
    private function consumerNames(): array
    {
        $names = [];
        foreach ($this->consumers->all() as $consumer) {
            $names[$consumer->consumerId()] = $consumer->displayName();
        }

        return $names;
    }

    /**
     * A human name for every business reference the page is about to
     * render, keyed `consumerId` then `businessReference`.
     *
     * Built here rather than in the template for the same reason
     * candidatesFor() is: a template that queried inside its own loop
     * would ask the same consumer the same question once per row.
     *
     * A consumer that answers null is simply absent from the map, and the
     * template falls back to the reference — which for `rental` is already
     * the name a manager uses out loud.
     *
     * @param \Modules\InboundMail\Api\InboundMessage[] $messages
     * @param array<int, \Modules\InboundMail\Api\MessageCandidate[]> $candidatesByMessage
     * @return array<string, array<string, string>>
     */
    private function referenceLabels(array $messages, array $candidatesByMessage = []): array
    {
        $wanted = [];
        foreach ($messages as $message) {
            foreach ($message->links as $link) {
                $wanted[$link->consumerId][$link->businessReference] = true;
            }
        }

        foreach ($candidatesByMessage as $candidates) {
            foreach ($candidates as $candidate) {
                $wanted[$candidate->consumerId][$candidate->businessReference] = true;
            }
        }

        $labels = [];
        foreach ($wanted as $consumerId => $references) {
            $consumer = $this->consumers->find($consumerId);
            if ($consumer === null) {
                continue;
            }

            foreach (array_keys($references) as $reference) {
                // A consumer that throws while naming its own object must
                // not take the courrier page down with it — the same
                // posture every other callback into a consumer takes.
                try {
                    $label = $consumer->describeReference((string) $reference);
                } catch (\Throwable) {
                    $label = null;
                }

                if ($label !== null && $label !== '') {
                    $labels[$consumerId][(string) $reference] = $label;
                }
            }
        }

        return $labels;
    }

    /**
     * The propositions of a whole page, so the list can show a chip
     * without the template querying inside its own loop.
     *
     * @param \Modules\InboundMail\Api\InboundMessage[] $messages
     * @return array<int, \Modules\InboundMail\Api\MessageCandidate[]>
     */
    private function candidatesFor(array $messages): array
    {
        $byMessage = [];
        foreach ($messages as $message) {
            $byMessage[$message->id] = $this->mailbox->candidatesFor($message->id);
        }

        return $byMessage;
    }
}
