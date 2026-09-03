<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Mail;

use Core\Notification\NotificationService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Service\ReviewNotificationService;

/**
 * Tells the chiefs of a stay what the mail did about it: a proposition
 * waiting for their decision (`camps.mail_proposition`), or a stay the
 * site created from a message and that needs confirming
 * (`camps.stay_from_mail`).
 *
 * Recipients are the staff of the stay's sections — the same resolution
 * the review invitation uses (`Service\ReviewNotificationService`), so a
 * chief is told about the same stays on both occasions. Nothing personal
 * travels: the stay's name and dates, never the sender nor the subject.
 */
class CampsMailNotifier
{
    public const TYPE_PROPOSITION = 'camps.mail_proposition';

    public const TYPE_STAY_CREATED = 'camps.stay_from_mail';

    public function __construct(
        private NotificationService $notifications,
        private ReviewNotificationService $recipients
    ) {
    }

    /**
     * @param Camp[] $camps the stays proposed, in the order the consumer ranked them
     * @param array<int, string> $labels camp id => how a chief names the stay
     */
    public function proposed(array $camps, array $labels): void
    {
        if ($camps === []) {
            return;
        }

        $recipients = $this->recipientsOf($camps);
        if ($recipients === []) {
            return;
        }

        $named = array_map(static fn(Camp $c): string => $labels[$c->id] ?? ('séjour n° ' . $c->id), $camps);

        $this->notifications->dispatch(
            self::TYPE_PROPOSITION,
            $recipients,
            [
                'title' => 'Un message attend votre décision — Camps',
                'body' => count($named) === 1
                    ? 'Un e-mail reçu pourrait concerner le séjour « ' . $named[0]
                        . ' ». Confirmez ou écartez la proposition sur l\'écran du courrier des camps.'
                    : 'Un e-mail reçu pourrait concerner l\'un de ces séjours : « '
                        . implode(' », « ', $named)
                        . ' ». Le site n\'en a choisi aucun : tranchez sur l\'écran du courrier des camps.',
                'url' => '/chefs/camps/courrier',
            ]
        );
    }

    public function stayCreated(Camp $camp, string $label): void
    {
        $recipients = $this->recipientsOf([$camp]);
        if ($recipients === []) {
            return;
        }

        $this->notifications->dispatch(
            self::TYPE_STAY_CREATED,
            $recipients,
            [
                'title' => 'Un séjour a été créé depuis un message',
                'body' => '« ' . $label . ' » a été créé « à confirmer » d\'après un e-mail reçu. '
                    . 'Vérifiez ses dates et son lieu, puis confirmez-le ou supprimez-le.',
                'url' => '/chefs/camps/sejours/' . $camp->id,
            ]
        );
    }

    /**
     * @param Camp[] $camps
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    private function recipientsOf(array $camps): array
    {
        $byAccount = [];
        foreach ($camps as $camp) {
            foreach ($this->recipients->recipientsFor($camp) as $recipient) {
                $byAccount[$recipient['userAccountId']] ??= $recipient;
            }
        }

        return array_values($byAccount);
    }
}
