<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use Core\Notification\NotificationService;
use Modules\Camps\Mail\CampsMailNotifier;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Service\ReviewNotificationService;
use PHPUnit\Framework\TestCase;

/**
 * Who is told of what the camps mail did, and what they are told.
 */
class CampsMailNotifierTest extends TestCase
{
    private function camp(int $id): Camp
    {
        return new Camp(
            $id, 1, Camp::STAY_GRAND_CAMP, '2026-07-12', '2026-07-19', null, Camp::STATUS_TO_CONFIRM,
            null, null, null, null, [1], '2026-01-01 00:00:00', '2026-01-01 00:00:00'
        );
    }

    /**
     * @param array<int, int[]> $accountsByCamp camp id => user account ids
     */
    private function recipients(array $accountsByCamp): ReviewNotificationService
    {
        $recipients = $this->createStub(ReviewNotificationService::class);
        $recipients->method('recipientsFor')->willReturnCallback(
            static fn(Camp $camp): array => array_map(
                static fn(int $id): array => ['userAccountId' => $id, 'memberId' => null],
                $accountsByCamp[$camp->id] ?? []
            )
        );

        return $recipients;
    }

    public function testAPropositionTellsTheStaysChiefsOnceEachWhereToDecide(): void
    {
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())->method('dispatch')->with(
            CampsMailNotifier::TYPE_PROPOSITION,
            $this->callback(static fn(array $recipients): bool => array_column($recipients, 'userAccountId') === [7, 8]),
            $this->callback(static fn(array $payload): bool
                => str_contains($payload['body'], '« Mozet, juillet 2026 », « Mozet, août 2026 »')
                    && $payload['url'] === '/chefs/camps/courrier')
        );

        (new CampsMailNotifier($notifications, $this->recipients([1 => [7], 2 => [7, 8]])))->proposed(
            [$this->camp(1), $this->camp(2)],
            [1 => 'Mozet, juillet 2026', 2 => 'Mozet, août 2026']
        );
    }

    public function testAStayWithNobodyToTellIsToldToNobody(): void
    {
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->never())->method('dispatch');

        $notifier = new CampsMailNotifier($notifications, $this->recipients([]));
        $notifier->proposed([$this->camp(1)], [1 => 'Mozet']);
        $notifier->stayCreated($this->camp(1), 'Mozet');
    }

    public function testACreatedStayIsAnnouncedWithItsOwnPage(): void
    {
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())->method('dispatch')->with(
            CampsMailNotifier::TYPE_STAY_CREATED,
            $this->anything(),
            $this->callback(static fn(array $payload): bool
                => str_contains($payload['body'], '« Mozet, juillet 2026 » a été créé « à confirmer »')
                    && $payload['url'] === '/chefs/camps/sejours/1')
        );

        (new CampsMailNotifier($notifications, $this->recipients([1 => [7]])))->stayCreated($this->camp(1), 'Mozet, juillet 2026');
    }
}
