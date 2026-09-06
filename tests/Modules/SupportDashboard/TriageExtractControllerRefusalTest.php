<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Http\Request;
use Modules\SupportDashboard\Controller\TriageExtractController;
use Modules\SupportDashboard\Service\TriageExtractResult;
use Modules\SupportDashboard\Service\TriageExtractService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The controller's half of the uniform 403 (SECURITY.md §4): whatever
 * reason the service refuses for, the bytes that leave are the same.
 *
 * Deliberately NOT in the `database` group. The service-backed test in
 * TriageExtractControllerTest skips when no server answers, and a
 * confidentiality property that is verified only on machines with a
 * database is a property that reads as verified on the machines
 * without one. This one runs everywhere: the service is a stub, and the
 * controller is the thing under test.
 */
class TriageExtractControllerRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reasons(): array
    {
        return [
            'unauthenticated' => [TriageExtractResult::REJECT_UNAUTHENTICATED],
            'cleartext' => [TriageExtractResult::REJECT_INSECURE_TRANSPORT],
            'malformed body' => [TriageExtractResult::REJECT_MALFORMED],
            'unknown reference' => [TriageExtractResult::REJECT_UNKNOWN_REFERENCE],
            'no archive' => [TriageExtractResult::REJECT_NO_ARCHIVE],
            'no consent' => [TriageExtractResult::REJECT_NO_CONSENT],
            'another issue' => [TriageExtractResult::REJECT_ISSUE_MISMATCH],
            'unbuildable' => [TriageExtractResult::REJECT_UNBUILDABLE],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reasons')]
    public function testEveryReasonAnswersTheSameBareForbidden(string $reason): void
    {
        $service = $this->createStub(TriageExtractService::class);
        $service->method('serve')->willReturn(TriageExtractResult::rejected($reason));

        $controller = new TriageExtractController(new Environment(new ArrayLoader([])), $service);

        $response = $controller->serve(
            new Request('POST', '/api/support/tickets/SUP-ABC234/triage-extract', [], [], [], ['REMOTE_ADDR' => '203.0.113.1']),
            ['reference' => 'SUP-ABC234']
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('{"status":"rejected"}', $response->getBody());
        $this->assertStringNotContainsString($reason, $response->getBody());
        $this->assertArrayNotHasKey('Content-Disposition', $response->getHeaders());
    }
}
