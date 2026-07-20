<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Provider\Ovh;

use Modules\SosStaff\Provider\ForwardingState;
use Modules\SosStaff\Provider\Ovh\OvhApiClient;
use Modules\SosStaff\Provider\Ovh\OvhTelephonyProvider;
use Modules\SosStaff\Provider\PhoneLine;
use Modules\SosStaff\Provider\ProviderException;
use PHPUnit\Framework\TestCase;

class OvhTelephonyProviderTest extends TestCase
{
    private function clientWithTransport(\Closure $transport): OvhApiClient
    {
        return new OvhApiClient(
            applicationKey: 'ak',
            applicationSecret: 'as',
            consumerKey: 'ck',
            transport: $transport
        );
    }

    public function testReadForwardingStateReturnsActiveWithNumber(): void
    {
        $client = $this->clientWithTransport(fn() => [
            'status' => 200,
            'body' => '{"forwardUnconditional":true,"forwardUnconditionalNumber":"+32470000000"}',
        ]);
        $provider = new OvhTelephonyProvider($client, 'ba-123', '0033100000000');

        $state = $provider->readForwardingState();

        $this->assertInstanceOf(ForwardingState::class, $state);
        $this->assertTrue($state->active);
        $this->assertSame('+32470000000', $state->number);
    }

    public function testReadForwardingStateReturnsInactiveWithNullNumber(): void
    {
        $client = $this->clientWithTransport(fn() => [
            'status' => 200,
            'body' => '{"forwardUnconditional":false}',
        ]);
        $provider = new OvhTelephonyProvider($client, 'ba-123', '0033100000000');

        $state = $provider->readForwardingState();

        $this->assertFalse($state->active);
        $this->assertNull($state->number);
    }

    public function testSetForwardingSendsExpectedBodyToOptionsEndpoint(): void
    {
        $captured = null;
        $client = $this->clientWithTransport(function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
            $captured = ['method' => $method, 'url' => $url, 'body' => $body];
            return ['status' => 200, 'body' => '{}'];
        });
        $provider = new OvhTelephonyProvider($client, 'ba-123', '0033100000000');

        $provider->setForwarding('+32470000000');

        $this->assertSame('PUT', $captured['method']);
        $this->assertSame('https://eu.api.ovh.com/1.0/telephony/ba-123/line/0033100000000/options', $captured['url']);
        $decoded = json_decode((string) $captured['body'], true);
        $this->assertTrue($decoded['forwardUnconditional']);
        $this->assertSame('+32470000000', $decoded['forwardUnconditionalNumber']);
    }

    public function testTestConnectionReturnsTrueOnSuccess(): void
    {
        $client = $this->clientWithTransport(fn() => ['status' => 200, 'body' => '{"forwardUnconditional":false}']);
        $provider = new OvhTelephonyProvider($client, 'ba-123', '0033100000000');

        $this->assertTrue($provider->testConnection());
    }

    public function testTestConnectionThrowsProviderExceptionOnFailure(): void
    {
        $client = $this->clientWithTransport(fn() => ['status' => 403, 'body' => '{"message":"Invalid Application Key"}']);
        $provider = new OvhTelephonyProvider($client, 'ba-123', '0033100000000');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Invalid Application Key');
        $provider->testConnection();
    }

    public function testListLinesFetchesEveryBillingAccountsLines(): void
    {
        $client = $this->clientWithTransport(function (string $method, string $url) {
            if (str_ends_with($url, '/telephony')) {
                return ['status' => 200, 'body' => '["ba-1","ba-2"]'];
            }
            if (str_ends_with($url, '/telephony/ba-1/line')) {
                return ['status' => 200, 'body' => '["0033100000001"]'];
            }
            if (str_ends_with($url, '/telephony/ba-2/line')) {
                return ['status' => 200, 'body' => '["0033100000002","0033100000003"]'];
            }
            return ['status' => 404, 'body' => '{}'];
        });
        $provider = new OvhTelephonyProvider($client, 'ba-1', '0033100000001');

        $lines = $provider->listLines();

        $this->assertCount(3, $lines);
        $this->assertContainsOnlyInstancesOf(PhoneLine::class, $lines);
        $this->assertSame('ba-1', $lines[0]->billingAccount);
        $this->assertSame('0033100000001', $lines[0]->serviceName);
        $this->assertSame('ba-2', $lines[1]->billingAccount);
        $this->assertSame('0033100000002', $lines[1]->serviceName);
    }

    public function testReadForwardingStateWrapsApiFailureAsProviderException(): void
    {
        $client = $this->clientWithTransport(fn() => ['status' => 500, 'body' => '{"message":"OVH internal error"}']);
        $provider = new OvhTelephonyProvider($client, 'ba-123', '0033100000000');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OVH internal error');
        $provider->readForwardingState();
    }
}
