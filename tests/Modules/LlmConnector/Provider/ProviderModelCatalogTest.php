<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Provider;

use Modules\LlmConnector\Provider\ProviderModelCatalog;
use Modules\LlmConnector\Provider\ScalewayModelCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderModelCatalogTest extends TestCase
{
    public function testChatAndImageEvidenceAreIndependentAndNamesDoNotGrantCapabilities(): void
    {
        $catalog = new ProviderModelCatalog([
            ['id' => '', 'display_name' => 'Invalid', 'chat' => true, 'vision' => true],
            ['id' => 'opaque-id', 'display_name' => 'Images', 'chat' => true, 'vision' => true],
            ['id' => 'small-vision', 'display_name' => 'Unknown'],
            ['id' => 'text', 'display_name' => 'Text', 'chat' => true, 'vision' => false],
            ['id' => 'unknown-input', 'display_name' => 'Text', 'chat' => true],
            ['id' => 'document-ocr', 'display_name' => 'Images', 'chat' => false, 'vision' => true],
        ]);
        self::assertSame([
            'models' => [
                ['id' => 'opaque-id', 'display_name' => 'Images'],
                ['id' => 'text', 'display_name' => 'Text'],
                ['id' => 'unknown-input', 'display_name' => 'Text'],
            ],
            'chat' => ['opaque-id', 'text', 'unknown-input'],
            'vision' => ['opaque-id'],
            'exclusions' => ['small-vision' => 'chat_capability_unverified', 'document-ocr' => 'chat_endpoint_unsupported'],
        ], [
            'models' => $catalog->chatModels(),
            'chat' => $catalog->chatModelIds(),
            'vision' => $catalog->visionModelIds(),
            'exclusions' => $catalog->exclusions(),
        ]);
    }

    public function testConflictingDuplicateCapabilitiesStayUnverifiedEvenIfAThirdEntryClaimsSupport(): void
    {
        $catalog = new ProviderModelCatalog([
            ['id' => 'duplicate', 'display_name' => 'First', 'chat' => true, 'vision' => true],
            ['id' => 'duplicate', 'display_name' => 'Second', 'chat' => false, 'vision' => false],
            ['id' => 'duplicate', 'display_name' => 'Third', 'chat' => true, 'vision' => true],
        ]);
        self::assertSame([[], [], ['duplicate' => 'chat_capability_unverified']], [
            $catalog->chatModelIds(), $catalog->visionModelIds(), $catalog->exclusions(),
        ]);
    }

    public static function scalewayEvidence(): iterable
    {
        yield 'observed embeddings' => ['bge-multilingual-gemma2', false, false];
        yield 'other embeddings' => ['sentence-t5-xxl', false, false];
        yield 'dedicated OCR' => ['lightonocr-3-0.8b', false, false];
        yield 'transcription' => ['whisper-large-v3', false, false];
        yield 'small text only' => ['mistral-small-24b-instruct-2501', true, false];
        yield 'documented small vision' => ['mistral-small-3.2-24b-instruct-2506', true, true];
        yield 'no vision substring' => ['gemma-3-27b-it', true, true];
        yield 'unknown promising name' => ['brand-new-small-vision', null, null];
        yield 'ambiguous endpoint' => ['voxtral-small-24b-2507', null, null];
    }

    #[DataProvider('scalewayEvidence')]
    public function testScalewayUsesDocumentedExactIdentifiers(string $id, ?bool $chat, ?bool $vision): void
    {
        self::assertSame(['chat' => $chat, 'vision' => $vision], ScalewayModelCapabilities::forModel($id));
    }
}
