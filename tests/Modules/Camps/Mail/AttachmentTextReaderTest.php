<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\Security\EncryptionService;
use Modules\Camps\Mail\AttachmentTextReader;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * A booking is almost never written in the body of the e-mail that carries
 * it — the real ones are a one-word covering note and a PDF contract.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AttachmentTextReaderTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private FileRepository $files;
    private AttachmentTextReader $reader;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/campsattachments_' . uniqid();
        mkdir($this->storagePath . '/inbound', 0700, true);
        $this->files = new FileRepository($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->reader = new AttachmentTextReader(new StoredFileReader(
            $this->files,
            new EncryptedFileStorageService($this->files, $encryption, $this->storagePath),
            $this->storagePath
        ));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/inbound/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath . '/inbound');
        @rmdir($this->storagePath);
    }

    public function testAContractsDatesComeOutOfThePdfAndNotOutOfTheBody(): void
    {
        $attachment = $this->store(
            'contrat.pdf',
            'application/pdf',
            (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/pdf/camp_booking_contract.pdf')
        );

        $text = $this->reader->read([$attachment]);

        $this->assertStringContainsString('Arrivee: 18-09-26', $text);
        $this->assertStringContainsString('LE GRAND PRE', $text);
    }

    public function testAPlainTextPartIsReadAsItIs(): void
    {
        $attachment = $this->store('note.txt', 'text/plain', 'Du 12 au 19 juillet 2028.');

        $this->assertSame('Du 12 au 19 juillet 2028.', $this->reader->read([$attachment]));
    }

    public function testWithoutAConnectorAScannedContractStillReadsAsNothing(): void
    {
        // §7.5 degradation: no connector, no transcription, and the same
        // silence this reader had before the OCR door existed.
        $attachment = $this->store('scan.jpg', 'image/jpeg', str_repeat('x', 60000));

        $this->assertSame('', $this->reader->read([$attachment]));
    }

    // ── The scanned contract: the model transcribes it ──────────────────

    public function testAPhotographedContractIsTranscribedByTheModel(): void
    {
        $attachment = $this->store('scan.jpg', 'image/jpeg', str_repeat('x', 60000));

        $text = $this->readerWith($this->llmTranscribing('Arrivee: 18-09-26 Depart: 20-09-26'))
            ->read([$attachment]);

        $this->assertSame('Arrivee: 18-09-26 Depart: 20-09-26', $text);
        $this->assertSame('image/jpeg', $this->asked[0]->attachments[0]['mime_type']);
        $this->assertStringContainsString('Transcris', $this->asked[0]->prompt);
    }

    public function testAScannedPdfIsRasterisedThenTranscribed(): void
    {
        // A photograph of a contract saved as a PDF: no text layer to try,
        // so the first page is rendered and the picture is what the model
        // reads — the same path a photographed receipt already takes.
        $attachment = $this->store('scan.pdf', 'application/pdf', 'not a real pdf, so no text layer');

        $text = $this->readerWith($this->llmTranscribing('Arrivee: 18-09-26 Depart: 20-09-26'))
            ->read([$attachment]);

        $this->assertSame('Arrivee: 18-09-26 Depart: 20-09-26', $text);
        $this->assertSame('image/jpeg', $this->asked[0]->attachments[0]['mime_type']);
    }

    public function testAPdfWithATextLayerNeverCostsATranscription(): void
    {
        // The expensive door is the last one, not the first.
        $attachment = $this->store(
            'contrat.pdf',
            'application/pdf',
            (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/pdf/camp_booking_contract.pdf')
        );

        $this->readerWith($this->llmTranscribing('jamais appelé'))->read([$attachment]);

        $this->assertSame([], $this->asked);
    }

    public function testOneReadableAttachmentSparesTheOtherItsTranscription(): void
    {
        // A message carrying a contract AND a scanned access plan: the
        // contract answers, so the plan is never sent anywhere.
        $contract = $this->store('note.txt', 'text/plain', 'Du 12 au 19 juillet 2028.');
        $scan = $this->store('plan.jpg', 'image/jpeg', str_repeat('x', 60000));

        $text = $this->readerWith($this->llmTranscribing('plan'))->read([$contract, $scan]);

        $this->assertSame('Du 12 au 19 juillet 2028.', $text);
        $this->assertSame([], $this->asked);
    }

    public function testAtMostOneTranscriptionPerMessage(): void
    {
        $scans = [];
        foreach (range(1, 3) as $n) {
            $scans[] = $this->store("scan{$n}.jpg", 'image/jpeg', str_repeat('x', 60000));
        }

        $this->readerWith($this->llmTranscribing('page'))->read($scans);

        $this->assertCount(AttachmentTextReader::MAX_OCR_CALLS, $this->asked);
    }

    public function testASignatureLogoIsNeverWorthATranscription(): void
    {
        // Almost every message carries one, there is nothing written on it,
        // and without the size floor each would buy itself a provider call.
        $logo = $this->store('signature.png', 'image/png', str_repeat('x', 4000));

        $this->assertSame('', $this->readerWith($this->llmTranscribing('logo'))->read([$logo]));
        $this->assertSame([], $this->asked);
    }

    public function testAProviderThatIsDownCostsTheStayAndNothingElse(): void
    {
        $attachment = $this->store('scan.jpg', 'image/jpeg', str_repeat('x', 60000));

        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willThrowException(new LlmException('provider down'));

        $this->assertSame('', $this->readerWith($llm)->read([$attachment]));
    }

    public function testAConnectorWithNoModelForTheJobIsNotAsked(): void
    {
        $attachment = $this->store('scan.jpg', 'image/jpeg', str_repeat('x', 60000));

        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isTierAvailable')->willReturn(false);
        $llm->method('complete')->willReturnCallback(function (LlmRequest $request): LlmResponse {
            $this->asked[] = $request;

            return new LlmResponse('', null, 0, 0);
        });

        $this->assertSame('', $this->readerWith($llm)->read([$attachment]));
        $this->assertSame([], $this->asked);
    }

    public function testATranscriptionIsBounded(): void
    {
        $attachment = $this->store('scan.jpg', 'image/jpeg', str_repeat('x', 60000));

        $text = $this->readerWith($this->llmTranscribing(str_repeat('a', AttachmentTextReader::MAX_OCR_CHARS * 2)))
            ->read([$attachment]);

        $this->assertSame(AttachmentTextReader::MAX_OCR_CHARS, mb_strlen($text));
    }

    public function testAHugeAttachmentIsNotEvenFetched(): void
    {
        $attachment = new InboundAttachment(
            1,
            1,
            9999,
            'annexes.pdf',
            'application/pdf',
            AttachmentTextReader::MAX_FILE_BYTES + 1,
            'hash'
        );

        // File id 9999 does not exist: reaching the disk at all would be
        // the bug, and the size check is what stops it.
        $this->assertSame('', $this->reader->read([$attachment]));
    }

    public function testOnlyTheFirstFewAttachmentsAreRead(): void
    {
        $attachments = [];
        foreach (range(1, AttachmentTextReader::MAX_ATTACHMENTS + 2) as $n) {
            $attachments[] = $this->store("piece{$n}.txt", 'text/plain', "piece {$n}");
        }

        $text = $this->reader->read($attachments);

        $this->assertStringContainsString('piece 1', $text);
        $this->assertStringNotContainsString(
            'piece ' . (AttachmentTextReader::MAX_ATTACHMENTS + 1),
            $text
        );
    }

    public function testTheTextHandedBackIsBounded(): void
    {
        $attachment = $this->store('long.txt', 'text/plain', str_repeat('a', AttachmentTextReader::MAX_CHARS * 2));

        $this->assertSame(AttachmentTextReader::MAX_CHARS, mb_strlen($this->reader->read([$attachment])));
    }

    public function testAMissingFileIsNotAnError(): void
    {
        $attachment = $this->store('parti.pdf', 'application/pdf', 'x');
        @unlink($this->storagePath . '/inbound/parti.pdf');

        $this->assertSame('', $this->reader->read([$attachment]));
    }

    public function testAMessageWithNoAttachmentsReadsAsNothing(): void
    {
        $this->assertSame('', $this->reader->read([]));
    }

    /** @var list<LlmRequest> */
    private array $asked = [];

    /** A connector that answers one transcription, and remembers what it was asked. */
    private function llmTranscribing(string $text): LlmConnectorInterface
    {
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willReturnCallback(
            function (LlmRequest $request) use ($text): LlmResponse {
                $this->asked[] = $request;

                return new LlmResponse($text, null, 100, 10);
            }
        );

        return $llm;
    }

    private function readerWith(LlmConnectorInterface $llm): AttachmentTextReader
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new AttachmentTextReader(
            new StoredFileReader(
                $this->files,
                new EncryptedFileStorageService($this->files, $encryption, $this->storagePath),
                $this->storagePath
            ),
            null,
            $llm,
            // A rasterizer that always answers, so these tests are about
            // the decision to transcribe rather than about Ghostscript
            // being installed.
            new class extends \Core\File\PdfRasterizer {
                public function firstPageToJpeg(string $pdfContent): ?string
                {
                    return 'jpeg-bytes';
                }
            }
        );
    }

    private function store(string $name, string $mimeType, string $bytes): InboundAttachment
    {
        file_put_contents($this->storagePath . '/inbound/' . $name, $bytes);
        $fileId = $this->files->create(
            'inbound/' . $name,
            $name,
            $mimeType,
            strlen($bytes),
            'chief',
            'inbound_mail',
            null,
            false
        );

        return new InboundAttachment(
            $fileId,
            1,
            $fileId,
            $name,
            $mimeType,
            strlen($bytes),
            hash('sha256', $bytes)
        );
    }
}
