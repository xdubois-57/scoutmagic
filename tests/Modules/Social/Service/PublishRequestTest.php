<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Modules\Social\Api\SocialPlatform;
use Modules\Social\Service\PublishRequest;
use PHPUnit\Framework\TestCase;

/**
 * What a publishing form asked for — read from what the browser sends,
 * which is never trusted to be well-formed.
 */
final class PublishRequestTest extends TestCase
{
    public function testPlatformsAndTheGroupsChosenWhileGroupeDeDiscussionIsTicked(): void
    {
        $asked = PublishRequest::fromForm(['facebook', 'groups', 'nowhere'], ['3', '4', 'x', '-1'], []);

        $this->assertSame([SocialPlatform::Facebook], $asked->platforms);
        $this->assertSame([3, 4], $asked->groupIds);
        $this->assertFalse($asked->isEmpty());
    }

    public function testGroupsChosenWithoutTheBoxAreNotSent(): void
    {
        $asked = PublishRequest::fromForm(['instagram'], ['3'], []);

        $this->assertSame([], $asked->groupIds);
    }

    public function testAConfirmedRetryIsADestinationOfItsOwn(): void
    {
        $asked = PublishRequest::fromForm([], [], ['instagram', 'group:4', 'group:bad']);

        $this->assertSame([SocialPlatform::Instagram], $asked->platforms);
        $this->assertSame([SocialPlatform::Instagram], $asked->platformRetries);
        $this->assertSame([4], $asked->groupIds);
        $this->assertSame([4], $asked->groupRetries);
    }

    public function testNothingAskedIsEmpty(): void
    {
        $this->assertTrue(PublishRequest::fromForm('x', null, 42)->isEmpty());
    }
}
