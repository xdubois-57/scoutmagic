<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Ai;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\LlmConnector\Api\LlmTier;
use Modules\Rental\Ai\RentalAiAssistant;
use Modules\Rental\Ai\Suggestion;
use PHPUnit\Framework\TestCase;

/**
 * The optional AI helpers (§6.36).
 *
 * **The rule this file exists to pin is a negative one**: the AI never, on
 * its own, accepts or refuses a booking, changes an agreed price, blames a
 * renter for damage, withholds a deposit, triggers a refund or moves a
 * final financial status. That is enforced structurally — the assistant has
 * no repository, no service and no write path — and asserted here, because
 * the day somebody hands it one, everything else about this module's
 * safety rests on a comment.
 */
class RentalAiAssistantTest extends TestCase
{
    /** @var LlmRequest[] */
    private array $requests = [];

    private function connector(string $answer, bool $available = true): LlmConnectorInterface
    {
        return new class ($answer, $available, $this->requests) implements LlmConnectorInterface {
            /**
             * @param LlmRequest[] $requests
             */
            public function __construct(
                private string $answer,
                private bool $available,
                public array &$requests
            ) {
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function isTierAvailable(LlmTier $tier): bool
            {
                return $this->available;
            }

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->requests[] = $request;

                return new LlmResponse($this->answer, null, 10, 5);
            }
        };
    }

    private function throwingConnector(): LlmConnectorInterface
    {
        return new class implements LlmConnectorInterface {
            public function isAvailable(): bool
            {
                return true;
            }

            public function isTierAvailable(LlmTier $tier): bool
            {
                return true;
            }

            public function complete(LlmRequest $request): LlmResponse
            {
                throw new LlmException('le fournisseur est injoignable');
            }
        };
    }

    // ── It never decides (§6.36) ────────────────────────────────────────

    public function testTheAssistantHasNoWayToWriteAnything(): void
    {
        // Not "we do not call a setter" — there is nothing to call. The
        // constructor takes one collaborator, and it is the LLM connector.
        $constructor = (new \ReflectionClass(RentalAiAssistant::class))->getConstructor();
        $this->assertNotNull($constructor);

        $types = array_map(
            static fn(\ReflectionParameter $p) => (string) $p->getType(),
            $constructor->getParameters()
        );

        $this->assertSame(['?' . LlmConnectorInterface::class], $types);
    }

    public function testEveryPublicMethodReturnsASuggestionOrNothing(): void
    {
        // A method that returned a saved value, a status or a boolean
        // "accepted" would be the beginning of an autonomous decision.
        $methods = (new \ReflectionClass(RentalAiAssistant::class))->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->isConstructor() || $method->getName() === 'isAvailable') {
                continue;
            }

            $this->assertSame(
                '?' . Suggestion::class,
                (string) $method->getReturnType(),
                $method->getName() . ' must only ever propose.'
            );
        }
    }

    public function testASuggestionIsItsOwnTypeSoItCannotBePassedToASetterByAccident(): void
    {
        $suggestion = new Suggestion('1234,567');

        $this->assertNotSame('1234,567', $suggestion);
        $this->assertSame('1234,567', $suggestion->value);
    }

    // ── Reading a meter (§6.22, §6.36) ──────────────────────────────────

    public function testAMeterReadingComesBackAsASuggestion(): void
    {
        $assistant = new RentalAiAssistant($this->connector('1234,567'));

        $suggestion = $assistant->readMeter('binaire', 'image/jpeg');

        $this->assertNotNull($suggestion);
        $this->assertSame('1234,567', $suggestion->value);
    }

    public function testAMeterSuggestionConvertsToThousandthsLikeEveryOtherReading(): void
    {
        // A meter index is not money, but it has money's problem: a float
        // differenced against another float is how a bill ends up a cent
        // off in a way nobody can reproduce.
        $this->assertSame(1234567, (new Suggestion('1234,567'))->asThousandths());
        $this->assertSame(1234567, (new Suggestion('1234.567'))->asThousandths());
        $this->assertSame(1234000, (new Suggestion('1234'))->asThousandths());
    }

    public function testAnUnreadableDialProducesNoSuggestionAtAll(): void
    {
        // Not a suggestion reading "ILLISIBLE", which a hurried manager
        // could accept into a meter reading.
        $assistant = new RentalAiAssistant($this->connector('ILLISIBLE'));

        $this->assertNull($assistant->readMeter('binaire', 'image/jpeg'));
    }

    public function testAnUnparseableSuggestionConvertsToNullRatherThanZero(): void
    {
        // Zero is a meter reading. Null is not.
        $this->assertNull((new Suggestion('à peu près mille'))->asThousandths());
        $this->assertNull((new Suggestion(''))->asInt());
    }

    // ── Classifying a document (§7.8) ───────────────────────────────────

    public function testADocumentTypeIsSuggestedFromTheFilenameAndSubject(): void
    {
        $assistant = new RentalAiAssistant($this->connector('Contrat signé'));

        $suggestion = $assistant->suggestDocumentType('contrat-signe.pdf', 'Re: [LOC-2027-0042]', ['Contrat signé', 'Photo']);

        $this->assertNotNull($suggestion);
        $this->assertSame('Contrat signé', $suggestion->value);
    }

    public function testTheDocumentsOwnContentsAreNeverSent(): void
    {
        // They routinely contain the renter's address and signature, and
        // the question can be answered without them.
        $connector = $this->connector('Contrat signé');
        $assistant = new RentalAiAssistant($connector);

        $assistant->suggestDocumentType('contrat-signe.pdf', 'Re: [LOC-2027-0042]', ['Contrat signé']);

        $this->assertCount(1, $this->requests);
        $this->assertSame([], $this->requests[0]->attachments);
    }

    public function testWithNoCategoriesToChooseFromNothingIsAsked(): void
    {
        $connector = $this->connector('peu importe');
        $assistant = new RentalAiAssistant($connector);

        $this->assertNull($assistant->suggestDocumentType('x.pdf', 'sujet', []));
        $this->assertSame([], $this->requests);
    }

    // ── Resolving an email ambiguity (§7.6, level 4) ────────────────────

    public function testTheModelIsNeverAskedWhenTheHeadersAlreadySettleIt(): void
    {
        // §7.6 fixes the ordering: the AI is never the first source of
        // truth, and a single candidate is not an ambiguity.
        $connector = $this->connector('LOC-2027-0042');
        $assistant = new RentalAiAssistant($connector);

        $this->assertNull($assistant->resolveEmailAmbiguity('sujet', 'corps', ['LOC-2027-0042']));
        $this->assertSame([], $this->requests);
    }

    public function testARealAmbiguityIsPutToTheModel(): void
    {
        $assistant = new RentalAiAssistant($this->connector('LOC-2027-0043'));

        $suggestion = $assistant->resolveEmailAmbiguity('sujet', 'corps', ['LOC-2027-0042', 'LOC-2027-0043']);

        $this->assertNotNull($suggestion);
        $this->assertSame('LOC-2027-0043', $suggestion->value);
    }

    public function testAModelThatCannotChooseProducesNothing(): void
    {
        $assistant = new RentalAiAssistant($this->connector('INCERTAIN'));

        $this->assertNull($assistant->resolveEmailAmbiguity('sujet', 'corps', ['LOC-2027-0042', 'LOC-2027-0043']));
    }

    // ── Failure is silence, never an error ──────────────────────────────

    public function testAnUnreachableProviderIsSilence(): void
    {
        // The manager types the number themselves, exactly as they did
        // before the feature existed.
        $assistant = new RentalAiAssistant($this->throwingConnector());

        $this->assertNull($assistant->readMeter('binaire', 'image/jpeg'));
    }

    public function testWithoutTheModuleTheFeatureSimplyIsNotOffered(): void
    {
        $assistant = new RentalAiAssistant(null);

        $this->assertFalse($assistant->isAvailable());
        $this->assertNull($assistant->readMeter('binaire', 'image/jpeg'));
        $this->assertNull($assistant->summariseThread('un échange'));
    }

    public function testAnUnconfiguredConnectorIsNotOfferedEither(): void
    {
        // A button that always fails is worse than no button.
        $assistant = new RentalAiAssistant($this->connector('peu importe', available: false));

        $this->assertFalse($assistant->isAvailable());
        $this->assertNull($assistant->readMeter('binaire', 'image/jpeg'));
    }

    public function testAnEmptyAnswerIsNotASuggestion(): void
    {
        $assistant = new RentalAiAssistant($this->connector('   '));

        $this->assertNull($assistant->summariseThread('un échange'));
    }

    public function testAnEmptyConversationIsNeverSent(): void
    {
        $connector = $this->connector('résumé');
        $assistant = new RentalAiAssistant($connector);

        $this->assertNull($assistant->summariseThread('   '));
        $this->assertSame([], $this->requests);
    }

    // ── The prompt says what the model may do ───────────────────────────

    public function testTheSystemPromptTellsTheModelItProposesRatherThanDecides(): void
    {
        $connector = $this->connector('résumé');
        $assistant = new RentalAiAssistant($connector);

        $assistant->summariseThread('un échange');

        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('ne décide', (string) $this->requests[0]->systemPrompt);
    }

    public function testAMeterPhotoUsesTheOcrTier(): void
    {
        $connector = $this->connector('1234');
        $assistant = new RentalAiAssistant($connector);

        $assistant->readMeter('binaire', 'image/jpeg');

        $this->assertSame(LlmTier::OCR, $this->requests[0]->tier);
    }

    public function testEveryCallIsBoundedInTimeSoNobodyWaitsAtAScreen(): void
    {
        $connector = $this->connector('résumé');
        $assistant = new RentalAiAssistant($connector);

        $assistant->summariseThread('un échange');

        $this->assertNotNull($this->requests[0]->timeoutSeconds);
        $this->assertLessThanOrEqual(30, $this->requests[0]->timeoutSeconds);
    }
}
