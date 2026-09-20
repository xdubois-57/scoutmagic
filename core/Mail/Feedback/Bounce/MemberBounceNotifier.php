<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Bounce;

use Core\Notification\NotificationService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;

/**
 * Tells the people behind a bounced address that it bounced (roadmap
 * IT-05).
 *
 * **A bounce names a mailbox and nobody in particular**, so the work here
 * is going from an address to the account that owns it — and to that one
 * only: the account whose sign-in identity **is** that address. A parent's
 * account is usually the address the unit writes to, so this covers most
 * bounces.
 *
 * **It used to reach further, and that was a leak.** A second route
 * gathered the other valid addresses of every member profile listing the
 * bounced one, meaning to catch « one person, two secondary addresses, one
 * of them failing ». But `member_emails` hangs addresses off the CHILD,
 * and a child's profile routinely carries one address per guardian, with
 * no column saying which belongs to whom — the ownership that route needed
 * is not in the schema and cannot be inferred. So a mother's mailbox
 * failing told the father « Une de tes adresses est suspendue » about a
 * mailbox he neither owns nor can act on, between two people the site
 * elsewhere takes care to keep apart. Worse, it counted: `notify()`
 * answering true has {@see BounceService} record « déjà dit », so telling
 * the wrong guardian could spend the notification the right one never got.
 * The roadmap had already ruled out the same shape one step downstream —
 * « `dispatch()` enverrait vers toutes les adresses actives du membre » —
 * and this was that hazard coming back in through the recipient list.
 *
 * **The gap, stated rather than papered over**: an address that belongs to
 * nobody who can sign in is not prompted, and neither is a member whose
 * account is their Desk address and whose *secondary* address bounces —
 * the Desk address lives in `member_years` and is always supplied by the
 * caller rather than looked up here. Neither is left uninformed: the
 * reason and the button wait on their own address page, and the
 * super-admin sees the block on the Courrier sortant page. Answering false
 * for them is what keeps the error un-filed, so the prompt is still
 * available the day a route to them exists.
 *
 * Nothing here ever puts the address in a notification: the member has
 * one line per address on their own page and can see which is which,
 * while a notification body is stored, pushed, and read on a lock screen
 * (SECURITY.md §11).
 */
class MemberBounceNotifier implements BounceNotifier
{
    /** The shared identity purpose — the one accounts and addresses are indexed under. */
    private const BLIND_INDEX_PURPOSE = 'email';

    public function __construct(
        private NotificationService $notifications,
        private UserAccountRepository $accounts,
        private EncryptionService $encryption
    ) {
    }

    public function notify(BounceState $state, bool $blocking): bool
    {
        $recipients = $this->recipientsFor($state->email);
        if ($recipients === []) {
            // Nobody to tell — an address the site holds that belongs to
            // no account, which is the ordinary case for a parent's
            // secondary address with no login. Saying so matters: the
            // caller must not file this error as « déjà dit ».
            return false;
        }

        $this->notifications->dispatch(
            $blocking ? 'core.mail_bounce_blocked' : 'core.mail_bounce_temporary',
            $recipients,
            $blocking ? $this->blockedPayload($state) : $this->temporaryPayload($state)
        );

        return true;
    }

    /**
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    private function recipientsFor(string $email): array
    {
        // This address and no other. Widening the lookup to the addresses
        // sharing a member profile with it is what leaked one guardian's
        // mailbox to the other — see the class docblock.
        $accountIds = $this->accounts->findIdsByBlindIndexes([$this->blindIndex($email)]);

        $recipients = [];
        foreach (array_values(array_unique($accountIds)) as $accountId) {
            $recipients[] = ['userAccountId' => $accountId, 'memberId' => null];
        }

        return $recipients;
    }

    /**
     * @return array{title: string, body: string, url?: ?string}
     */
    private function temporaryPayload(BounceState $state): array
    {
        return [
            'title' => 'Un message n’a pas pu être remis',
            // The category, which is the actionable half, and never the
            // address nor the server's own sentence.
            'body' => $state->category->label() . '. ' . $state->category->guidance(),
            'url' => null,
        ];
    }

    /**
     * @return array{title: string, body: string, url?: ?string}
     */
    private function blockedPayload(BounceState $state): array
    {
        return [
            'title' => 'Une de tes adresses est suspendue',
            'body' => $state->category->label() . '. '
                . 'L’unité a cessé d’écrire à cette adresse. ' . $state->category->guidance(),
            'url' => null,
        ];
    }

    private function blindIndex(string $email): string
    {
        return $this->encryption->blindIndex(
            EncryptionService::normalizeEmailForIndex($email),
            self::BLIND_INDEX_PURPOSE
        );
    }
}
