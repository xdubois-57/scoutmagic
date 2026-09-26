<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Meta;

use Modules\Social\Meta\MetaClient;
use Modules\Social\Meta\MetaException;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * What this module asks of Meta, and what it makes of the answers — with
 * Meta played by a fake transport.
 */
final class MetaClientTest extends TestCase
{
    public function testTheFacebookConsentAsksForThePagesAndNothingMore(): void
    {
        $url = (new MetaClient(H::transport([])))->facebookAuthorizationUrl('12345', 'https://u.be/r', 'st');

        $this->assertStringStartsWith('https://www.facebook.com/' . MetaClient::GRAPH_VERSION . '/dialog/oauth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('pages_show_list,pages_read_engagement,pages_manage_posts', $query['scope']);
        $this->assertSame('st', $query['state']);
        $this->assertSame('https://u.be/r', $query['redirect_uri']);
    }

    public function testTheInstagramConsentUsesInstagramLogin(): void
    {
        $url = (new MetaClient(H::transport([])))->instagramAuthorizationUrl('12345', 'https://u.be/r', 'st');

        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        $this->assertStringContainsString('instagram_business_content_publish', urldecode($url));
    }

    public function testTheFacebookCodeBecomesALongLivedUserToken(): void
    {
        $transport = H::transport([
            'fb_exchange_token' => H::ok(['access_token' => 'LONG', 'token_type' => 'bearer']),
            'oauth/access_token' => H::ok(['access_token' => 'SHORT']),
        ]);

        $token = (new MetaClient($transport))->facebookUserToken('1', 'secret', 'https://u.be/r', 'CODE');

        $this->assertSame('LONG', $token);
        $this->assertCount(2, $transport->requests);
        // The exchange is what makes the Page tokens permanent: it must be
        // the short token that is exchanged.
        $this->assertStringContainsString('fb_exchange_token=SHORT', $transport->requests[1]['url']);
    }

    public function testPagesWithoutATokenAreNotOffered(): void
    {
        $transport = H::transport(['me/accounts' => H::ok(['data' => [
            ['id' => '1', 'name' => 'Unité 25', 'access_token' => 'P1'],
            ['id' => '2', 'name' => 'Sans jeton'],
        ]])]);

        $pages = (new MetaClient($transport))->facebookPages('USER');

        $this->assertSame([['id' => '1', 'name' => 'Unité 25', 'access_token' => 'P1']], $pages);
    }

    public function testTheInstagramCodeBecomesASixtyDayToken(): void
    {
        $transport = H::transport([
            'api.instagram.com/oauth/access_token' => H::ok(['data' => [['access_token' => 'SHORT', 'user_id' => 9]]]),
            'ig_exchange_token' => H::ok(['access_token' => 'LONG', 'expires_in' => 5184000]),
        ]);

        $token = (new MetaClient($transport))->instagramToken('1', 'secret', 'https://u.be/r', 'CODE');

        $this->assertSame(['token' => 'LONG', 'expires_in' => 5184000], $token);
        $this->assertSame('POST', $transport->requests[0]['method']);
        $this->assertSame('authorization_code', $transport->requests[0]['fields']['grant_type']);
    }

    public function testAPersonalInstagramAccountIsRefusedWithItsOwnSentence(): void
    {
        $transport = H::transport(['/me?' => H::ok(['user_id' => '9', 'username' => 'moi', 'account_type' => 'PERSONAL'])]);

        $this->expectException(MetaException::class);
        $this->expectExceptionMessage('compte personnel');

        (new MetaClient($transport))->instagramAccount('T');
    }

    public function testAProfessionalAccountIsRead(): void
    {
        $transport = H::transport(['/me?' => H::ok(['user_id' => 17841, 'username' => 'unite25', 'account_type' => 'BUSINESS'])]);

        $this->assertSame(['id' => '17841', 'username' => 'unite25'], (new MetaClient($transport))->instagramAccount('T'));
    }

    public function testARefusedTokenSaysReconnectAndKeepsMetasWordsForTheJournalOnly(): void
    {
        $token = str_repeat('EAAB', 20);
        $transport = H::transport(['graph.facebook.com' => ['status' => 400, 'body' => (string) json_encode(['error' => [
            'message' => 'Error validating access token: ' . $token,
            'type' => 'OAuthException',
            'code' => 190,
        ]])]]);

        try {
            (new MetaClient($transport))->facebookPageName('1', $token);
            $this->fail('A refused token must throw.');
        } catch (MetaException $e) {
            $this->assertTrue($e->authRefused);
            $this->assertStringContainsString('Reconnectez', $e->getMessage());
            $this->assertStringNotContainsString('Error validating', $e->getMessage());
            $this->assertStringContainsString('code 190', $e->detail);
            // No secret, no token in the journal (CHANTIER-partage-social).
            $this->assertStringNotContainsString($token, $e->detail);
        }
    }

    public function testAnErrorShapedAnyOtherWayIsStillAnError(): void
    {
        $transport = H::transport(['graph.facebook.com' => ['status' => 502, 'body' => '<html>Bad gateway</html>']]);

        $this->expectException(MetaException::class);

        (new MetaClient($transport))->facebookPageName('1', 'T');
    }

    public function testNoAnswerAtAllIsReported(): void
    {
        $transport = H::transport(['graph.facebook.com' => null]);

        $this->expectException(MetaException::class);
        $this->expectExceptionMessage('Meta ne répond pas');

        (new MetaClient($transport))->facebookPageName('1', 'T');
    }

    public function testTheRenewalReturnsTheNewTokenAndItsLife(): void
    {
        $transport = H::transport(['refresh_access_token' => H::ok(['access_token' => 'NEW', 'expires_in' => 5183944])]);

        $this->assertSame(
            ['token' => 'NEW', 'expires_in' => 5183944],
            (new MetaClient($transport))->refreshInstagramToken('OLD')
        );
        $this->assertStringContainsString('grant_type=ig_refresh_token', $transport->requests[0]['url']);
    }
}
