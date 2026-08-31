<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageLink;

/**
 * The old `claim()` / `onMessageStored()` vocabulary, over the v2 contract.
 *
 * **Deliberately a test-only adapter, and deliberately thin.** The consumer
 * contract changed shape in the same change that introduced it, but this
 * module's *behaviour* did not — which is precisely what the suite below
 * has to keep proving. Rewriting fifteen call sites would have meant
 * rewriting the assertions around them, and an assertion rewritten during a
 * refactor proves nothing about the refactor.
 *
 * So the behavioural tests keep asking the questions they always asked, and
 * this class translates them. Anything genuinely new about v2 —
 * propositions, `onUnlinked()`, the audience declarations — is tested
 * against the real contract, not through here.
 */
class CampsConsumerV1Adapter extends CampsMessageConsumer
{
    /**
     * What `claim()` used to answer: the single association this consumer
     * makes of a message, or null for "not mine".
     */
    public function claim(CandidateMessage $message): ?MessageLink
    {
        return $this->analyze($message)->links[0] ?? null;
    }

    /**
     * What `onMessageStored()` used to do, for the association the message
     * was read through.
     */
    public function onMessageStored(InboundMessage $message): void
    {
        $this->onLinked($message, new MessageLink(
            self::CONSUMER_ID,
            $message->businessReference,
            LinkOrigin::SENDER
        ));
    }
}
