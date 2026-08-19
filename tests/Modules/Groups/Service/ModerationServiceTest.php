<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Modules\Groups\Service\ModerationService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Every path through the a-priori check, and one property that holds
 * across all of them: only an explicit flagged:true refuses a message.
 * No provider, module disabled, timeout, malformed answer — each is
 * "could not check", never "suspicious".
 *
 * @group database
 */
#[Group('database')]
class ModerationServiceTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
    }

    public function testWithoutTheLlmModuleItIsUnavailableAndChecksNothing(): void
    {
        $service = new ModerationService($this->settingService(), null);

        $this->assertFalse($service->isAvailable());
        $this->assertNull($service->moderate('un message quelconque'));
    }

    public function testWithAProviderThatReportsItselfUnavailableNothingIsChecked(): void
    {
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(false);
        // No configured provider means no call at all, not a call that
        // fails.
        $connector->expects($this->never())->method('complete');

        $service = new ModerationService($this->settingService(), $connector);

        $this->assertFalse($service->isAvailable());
        $this->assertNull($service->moderate('un message quelconque'));
    }

    /**
     * The admin switch is the single gate every caller passes through, so
     * turning it off must mean no request is made — not a request whose
     * verdict is then discarded, which would still cost money and still
     * send the member's text to a third party.
     */
    public function testTurningTheSettingOffMakesNoLlmCallAtAll(): void
    {
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->expects($this->never())->method('complete');

        $this->setSetting(ModerationService::SETTING_ENABLED, '0');
        $service = new ModerationService($this->settingService(), $connector);

        $this->assertFalse($service->isEnabled());
        $this->assertFalse($service->isAvailable());
        $this->assertNull($service->moderate('un message quelconque'));
    }

    public function testTheSettingDefaultsToOnWhenItHasNeverBeenSaved(): void
    {
        $connector = $this->createStub(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);

        $service = new ModerationService($this->settingService(), $connector);

        $this->assertTrue($service->isEnabled());
        $this->assertTrue($service->isAvailable());
    }

    public function testAnAcceptedMessageComesBackUnflagged(): void
    {
        $service = $this->serviceReturning(['flagged' => false, 'reason' => null, 'suggestion' => null]);

        $result = $service->moderate('Super sortie samedi, merci à tous !');

        $this->assertSame(['flagged' => false, 'reason' => null, 'suggestion' => null], $result);
    }

    public function testAFlaggedMessageCarriesBackItsReasonAndSuggestion(): void
    {
        $service = $this->serviceReturning([
            'flagged' => true,
            'reason' => 'Ce message vise une personne.',
            'suggestion' => 'Je ne suis pas d\'accord avec la façon dont la sortie a été organisée.',
        ]);

        $result = $service->moderate('texte refusé');

        $this->assertTrue($result['flagged']);
        $this->assertSame('Ce message vise une personne.', $result['reason']);
        $this->assertSame(
            'Je ne suis pas d\'accord avec la façon dont la sortie a été organisée.',
            $result['suggestion']
        );
    }

    public function testAFlaggedMessageWithoutAReasonStillGetsAFrenchOne(): void
    {
        $service = $this->serviceReturning(['flagged' => true, 'reason' => '  ', 'suggestion' => null]);

        $result = $service->moderate('texte refusé');

        $this->assertTrue($result['flagged']);
        $this->assertNotSame('', trim((string) $result['reason']));
        $this->assertNull($result['suggestion']);
    }

    /**
     * Never trust the model's own character counting — the cap is applied
     * here whatever came back.
     */
    public function testAnOverlongSuggestionIsTruncatedRatherThanTrusted(): void
    {
        $service = $this->serviceReturning([
            'flagged' => true,
            'reason' => 'raison',
            'suggestion' => str_repeat('é', 5000),
        ]);

        $result = $service->moderate('texte refusé');

        $this->assertSame(1000, mb_strlen((string) $result['suggestion']));
    }

    /**
     * A timeout arrives as an LlmException like any other failure, and
     * means "posted without moderation". Failing closed here would let a
     * slow provider block an entire group from talking.
     */
    public function testATimeoutFailsOpen(): void
    {
        $connector = $this->createStub(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->method('complete')->willThrowException(new LlmException('timeout'));

        $service = new ModerationService($this->settingService(), $connector);

        $this->assertNull($service->moderate('un message quelconque'));
    }

    public function testAMalformedAnswerFailsOpen(): void
    {
        // Schema-violating shapes, each of which must read as "could not
        // check" rather than as a refusal.
        foreach ([null, [], ['reason' => 'x'], ['flagged' => 'oui'], ['flagged' => 1]] as $parsed) {
            $connector = $this->createStub(LlmConnectorInterface::class);
            $connector->method('isAvailable')->willReturn(true);
            $connector->method('complete')->willReturn(new LlmResponse('{}', $parsed, 1, 1));

            $service = new ModerationService($this->settingService(), $connector);

            $this->assertNull($service->moderate('un message quelconque'));
        }
    }

    /**
     * The timeout is deliberately tighter than a provider's own default:
     * this call sits inside a member's publish request, so a slow provider
     * has to degrade quickly rather than hold the page open.
     */
    public function testTheRequestIsCheapTieredSchemaBoundAndTightlyTimedOut(): void
    {
        $captured = null;
        $connector = $this->createStub(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->method('complete')->willReturnCallback(
            function (LlmRequest $request) use (&$captured): LlmResponse {
                $captured = $request;
                return new LlmResponse('{}', ['flagged' => false], 1, 1);
            }
        );

        (new ModerationService($this->settingService(), $connector))->moderate('bonjour');

        $this->assertNotNull($captured);
        $this->assertSame(\Modules\LlmConnector\Api\LlmTier::CHEAP, $captured->tier);
        $this->assertLessThanOrEqual(10, $captured->timeoutSeconds);
        $this->assertIsArray($captured->responseSchema);
        $this->assertSame(['flagged', 'reason', 'suggestion'], $captured->responseSchema['required']);
        // The member's text is the prompt, never spliced into the system
        // prompt — which is what keeps a message that says "ignore les
        // instructions" from reading as an instruction.
        $this->assertSame('bonjour', $captured->prompt);
    }

    /**
     * @param array<string, mixed>|null $parsed
     */
    private function serviceReturning(?array $parsed): ModerationService
    {
        $connector = $this->createStub(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->method('complete')->willReturn(new LlmResponse('{}', $parsed, 10, 10));

        return new ModerationService($this->settingService(), $connector);
    }

    private function settingService(): SettingService
    {
        return new SettingService(new SettingRepository($this->pdo));
    }

    private function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['groups', $key, $value, 'boolean', 'Modération IA', 'Modération IA']);
    }
}
