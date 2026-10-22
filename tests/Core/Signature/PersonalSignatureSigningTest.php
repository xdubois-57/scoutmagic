<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Signature;

use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\Signature\AccountSignature;
use Core\Signature\PersonalSignatureRepository;
use Core\Signature\PersonalSignatureService;
use Core\Signature\SignatureException;
use Core\Signature\SignatureImage;
use Core\Signature\SignatureOrigin;
use Core\Signature\SignatureSubmission;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The signing half of the personal signature (issue #926, lot C1): what a
 * module signs a document with — the account's own signature or one given
 * in the dialog — whether it is kept afterwards, a witness without an
 * account, and the journal line of the act, never with a pixel.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PersonalSignatureSigningTest extends TestCase
{
    private \PDO $pdo;
    private PersonalSignatureService $service;
    private int $account;
    private int $other;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $accounts = new UserAccountRepository($this->pdo, $encryption);
        $this->account = $accounts->create('marie@example.org')->id;
        $accounts->updateProfile($this->account, 'Marie', 'Dupont');
        $this->other = $accounts->create('paul@example.org')->id;

        $this->service = new PersonalSignatureService(
            new PersonalSignatureRepository($this->pdo, $encryption),
            new JournalService(new JournalRepository($this->pdo)),
            $accounts
        );
    }

    private static function pngBytes(int $width = 300, int $height = 100): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function drawing(): string
    {
        return 'data:image/png;base64,' . base64_encode(self::pngBytes());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journal(string $type): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_log WHERE event_type = ? ORDER BY id');
        $stmt->execute([$type]);

        return $stmt->fetchAll();
    }

    // ── forSigning() ────────────────────────────────────────────────────

    public function testTheStoredSignatureIsTheAccountsOwnAndIsNeverRememberedAgain(): void
    {
        $kept = SignatureImage::fromBytes(self::pngBytes());
        $this->service->save($this->account, $kept, new \DateTimeImmutable());

        $signature = $this->service->forSigning($this->account, new SignatureSubmission(true, null, null, true));

        $this->assertSame($this->account, $signature->accountId);
        $this->assertSame(SignatureOrigin::Stored, $signature->origin);
        $this->assertSame($kept->bytes(), $signature->png->bytes());
        $this->assertFalse($signature->rememberRequested);
        $this->assertSame('Marie Dupont', $signature->signerName);
    }

    public function testAStoredSignatureDeletedSinceTheDialogOpenedSignsNothing(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage("Votre signature enregistrée n'existe plus");

        $this->service->forSigning($this->account, new SignatureSubmission(true));
    }

    /** Another account's signature is never reached: the account asked about is the session's. */
    public function testAnotherAccountsStoredSignatureIsNeverUsed(): void
    {
        $this->service->save($this->other, SignatureImage::fromBytes(self::pngBytes(40, 40)), new \DateTimeImmutable());

        $this->expectException(SignatureException::class);
        $this->service->forSigning($this->account, new SignatureSubmission(true));
    }

    public function testADrawingIsDecodedNowAndKeptNowhere(): void
    {
        $signature = $this->service->forSigning($this->account, new SignatureSubmission(false, self::drawing()));

        $this->assertSame(SignatureOrigin::DrawnNow, $signature->origin);
        $this->assertStringStartsWith("\x89PNG", $signature->png->bytes());
        $this->assertFalse($signature->rememberRequested);
        $this->assertFalse($this->service->has($this->account), 'forSigning() writes nothing');
    }

    public function testAnImportIsDecodedFromItsFile(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($path, self::pngBytes(600, 200));
        try {
            $signature = $this->service->forSigning($this->account, new SignatureSubmission(false, null, $path, true));
        } finally {
            @unlink($path);
        }

        $this->assertSame(SignatureOrigin::ImportedNow, $signature->origin);
        $this->assertTrue($signature->rememberRequested);
        $this->assertFalse($this->service->has($this->account));
    }

    public function testNothingSubmittedIsRefusedInFrench(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage("Tracez votre signature ou importez-en l'image.");

        $this->service->forSigning($this->account, new SignatureSubmission(false));
    }

    public function testAnAccountWithoutANameSignsWithoutOne(): void
    {
        $signature = $this->service->forSigning($this->other, new SignatureSubmission(false, self::drawing()));

        $this->assertNull($signature->signerName);
    }

    // ── rememberIfAsked() ───────────────────────────────────────────────

    public function testUntickedNothingIsKept(): void
    {
        $signature = $this->service->forSigning($this->account, new SignatureSubmission(false, self::drawing(), null, false));

        $this->assertFalse($this->service->rememberIfAsked($signature, new \DateTimeImmutable()));
        $this->assertFalse($this->service->has($this->account));
        $this->assertSame([], $this->journal('personal_signature_saved'));
    }

    public function testTickedTheSignatureIsKeptForItsOwner(): void
    {
        $signature = $this->service->forSigning($this->account, new SignatureSubmission(false, self::drawing(), null, true));

        $this->assertTrue($this->service->rememberIfAsked($signature, new \DateTimeImmutable('2026-10-22 10:00:00')));
        $this->assertSame($signature->png->bytes(), $this->service->ownImage($this->account)?->bytes());
        $this->assertFalse($this->service->has($this->other));
        $this->assertCount(1, $this->journal('personal_signature_saved'));
    }

    public function testTheStoredSignatureIsNeverCopiedOntoItself(): void
    {
        $this->service->save($this->account, SignatureImage::fromBytes(self::pngBytes()), new \DateTimeImmutable('2026-01-01 08:00:00'));
        $stored = $this->service->forSigning($this->account, new SignatureSubmission(true));
        // Even built by hand with the box ticked, a signature that came
        // from the account is not written back to it.
        $signature = new AccountSignature($this->account, $stored->png, SignatureOrigin::Stored, true, null);

        $this->assertFalse($this->service->rememberIfAsked($signature, new \DateTimeImmutable()));
        $this->assertSame('2026-01-01 08:00:00', $this->service->savedAt($this->account)?->format('Y-m-d H:i:s'));
    }

    /** It opens no transaction of its own: the caller's rollback takes the remembering with it. */
    public function testRememberingRunsInTheCallersTransactionAndRollsBackWithIt(): void
    {
        $signature = $this->service->forSigning($this->account, new SignatureSubmission(false, self::drawing(), null, true));

        $this->pdo->beginTransaction();
        $this->service->rememberIfAsked($signature, new \DateTimeImmutable());
        $this->assertTrue($this->pdo->inTransaction(), 'rememberIfAsked() never commits for its caller');
        $this->pdo->rollBack();

        $this->assertFalse($this->service->has($this->account));
        $this->assertSame([], $this->journal('personal_signature_saved'));
    }

    // ── witness() ───────────────────────────────────────────────────────

    public function testAWitnessSignsWithADrawingAndTheNameTheyTyped(): void
    {
        $witness = $this->service->witness(new SignatureSubmission(false, self::drawing()), "  Jeanne   d'Arc ");

        $this->assertSame("Jeanne d'Arc", $witness->typedName);
        $this->assertStringStartsWith("\x89PNG", $witness->png->bytes());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_account_signatures')->fetchColumn());
    }

    public function testAWitnessCannotImportAnImage(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($path, self::pngBytes());
        try {
            $this->expectException(SignatureException::class);
            $this->expectExceptionMessage('une image importée ne convient pas');
            $this->service->witness(new SignatureSubmission(false, self::drawing(), $path), 'Jeanne');
        } finally {
            @unlink($path);
        }
    }

    public function testAWitnessCannotSignWithAStoredSignature(): void
    {
        $this->expectException(SignatureException::class);
        $this->service->witness(new SignatureSubmission(true), 'Jeanne');
    }

    public function testAWitnessWithoutADrawingSignsNothing(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Tracez la signature dans le cadre.');
        $this->service->witness(new SignatureSubmission(false), 'Jeanne');
    }

    public function testAWitnessMustTypeTheirName(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Indiquez le nom de la personne qui signe.');
        $this->service->witness(new SignatureSubmission(false, self::drawing()), "  \n ");
    }

    public function testAWitnessNameHasACeiling(): void
    {
        $this->assertSame(120, mb_strlen($this->service->witness(
            new SignatureSubmission(false, self::drawing()),
            str_repeat('é', 120)
        )->typedName));

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('120 caractères');
        $this->service->witness(new SignatureSubmission(false, self::drawing()), str_repeat('é', 121));
    }

    // ── witnessFromRequest() — the brick #989 will use ──────────────────

    /**
     * What the witness dialog posts becomes a signature in one call: the
     * drawing and the typed name. A « Mémoriser » box or an account id
     * posted alongside is not read, and nothing is written anywhere —
     * neither a signature nor a journal line.
     */
    public function testTheWitnessDialogIsReadIntoASignatureThatNothingKeeps(): void
    {
        $journalBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn();

        $witness = $this->service->witnessFromRequest(self::request([
            'signature_source' => 'new',
            'signature_data' => self::drawing(),
            PersonalSignatureService::WITNESS_NAME_FIELD => ' Jeanne Martin ',
            'remember_signature' => '1',
            'user_account_id' => (string) $this->account,
        ]));

        $this->assertSame('signer_name', PersonalSignatureService::WITNESS_NAME_FIELD);
        $this->assertSame('Jeanne Martin', $witness->typedName);
        $this->assertStringStartsWith("\x89PNG", $witness->png->bytes());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_account_signatures')->fetchColumn());
        $this->assertSame($journalBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn());
        $this->assertFalse($this->service->has($this->account));
        // A WitnessSignature names no account: it has no property for one.
        $this->assertSame(['png', 'typedName'], array_map(
            static fn(\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass($witness))->getProperties()
        ));
    }

    /** A forged `signer_name[]` is no name at all, never the word « Array ». */
    public function testANameSentAsAnArrayIsRefusedAsNoName(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Indiquez le nom de la personne qui signe.');
        $this->service->witnessFromRequest(self::request([
            'signature_source' => 'new',
            'signature_data' => self::drawing(),
            PersonalSignatureService::WITNESS_NAME_FIELD => ['Jeanne'],
        ]));
    }

    /** The stored mode is the account's signature, and a witness has no account. */
    public function testTheWitnessDialogRefusesTheStoredMode(): void
    {
        $this->service->save($this->account, SignatureImage::fromBytes(self::pngBytes()), new \DateTimeImmutable());

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('une signature enregistrée ne convient pas');
        $this->service->witnessFromRequest(self::request([
            'signature_source' => 'stored',
            PersonalSignatureService::WITNESS_NAME_FIELD => 'Jeanne Martin',
        ]));
    }

    public function testTheWitnessDialogNeedsADrawingAndAName(): void
    {
        try {
            $this->service->witnessFromRequest(self::request([
                'signature_source' => 'new',
                PersonalSignatureService::WITNESS_NAME_FIELD => 'Jeanne Martin',
            ]));
            $this->fail('signed without a drawing');
        } catch (SignatureException $e) {
            $this->assertSame('Tracez la signature dans le cadre.', $e->getMessage());
        }

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Indiquez le nom de la personne qui signe.');
        $this->service->witnessFromRequest(self::request([
            'signature_source' => 'new',
            'signature_data' => self::drawing(),
        ]));
    }

    // ── recordSigning() ─────────────────────────────────────────────────

    public function testTheActIsJournaledForTheSessionAccountWithoutAPixel(): void
    {
        $signature = $this->service->forSigning($this->account, new SignatureSubmission(false, self::drawing(), null, true));

        $this->service->recordSigning($signature, 'rental', 'signed_contract', 40, 12, new \DateTimeImmutable('2026-10-22 10:15:00'));

        $entries = $this->journal('personal_signature_document_signed');
        $this->assertCount(1, $entries);
        $this->assertSame($this->account, (int) $entries[0]['user_account_id']);
        $this->assertSame('security', $entries[0]['level']);
        $this->assertSame([
            'module' => 'rental',
            'document_type' => 'signed_contract',
            'document_id' => 40,
            'owner_entity_id' => 12,
            'origin' => 'drawn_now',
            'remembered' => true,
            'signed_at' => '2026-10-22 10:15:00',
        ], json_decode((string) $entries[0]['context'], true));
        $everything = self::rawJournal($this->pdo);
        // The slash-free start of every base64 PNG: a stored context is
        // JSON, which may escape the slashes of a full base64 string.
        $this->assertStringNotContainsString('iVBORw0KGgo', $everything);
        $this->assertStringNotContainsString(base64_encode($signature->png->bytes()), $everything);
        $this->assertStringNotContainsString((string) $signature->png->byteLength(), (string) $entries[0]['context']);
    }

    /** Every column of every journal row, as stored — never re-encoded. */
    private static function rawJournal(\PDO $pdo): string
    {
        $raw = '';
        foreach ($pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $raw .= implode("\n", array_map(static fn(mixed $value): string => (string) $value, $row)) . "\n";
        }

        return $raw;
    }

    /**
     * The document is signed and committed when the act is journaled: a
     * journal that fails then is reported to the server's log, with the
     * ids and never a pixel, and never turns the signing into an error.
     */
    public function testAJournalThatFailsNeverFailsTheSigning(): void
    {
        $signature = $this->service->forSigning($this->account, new SignatureSubmission(false, self::drawing()));
        $this->pdo->exec('DROP TABLE event_log');
        $log = (string) tempnam(sys_get_temp_dir(), 'siglog');
        $previous = ini_set('error_log', $log);

        try {
            $this->service->recordSigning($signature, 'rental', 'signed_contract', 40, 12, new \DateTimeImmutable());
            $written = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($log);
        }

        $this->assertStringContainsString('module rental, signed_contract #40, account #' . $this->account, $written);
        $this->assertStringNotContainsString('iVBORw0KGgo', $written);
    }

    // ── SignatureSubmission::fromRequest() ──────────────────────────────

    /**
     * @param array<string, mixed> $body
     */
    private static function request(array $body): Request
    {
        return new Request('POST', '/sign', [], $body, [], []);
    }

    public function testThePostedFormIsReadWithoutEverAnAccountId(): void
    {
        $submission = SignatureSubmission::fromRequest(self::request([
            'signature_source' => 'new',
            'signature_data' => self::drawing(),
            'remember_signature' => '1',
            'user_account_id' => (string) $this->other,
        ]));

        $this->assertFalse($submission->useStored);
        $this->assertTrue($submission->rememberRequested);
        $this->assertNotNull($submission->drawing);

        // The account signing is the one the caller passes — the
        // session's — and never the one the form named.
        $signature = $this->service->forSigning($this->account, $submission);
        $this->assertSame($this->account, $signature->accountId);
        $this->service->rememberIfAsked($signature, new \DateTimeImmutable());
        $this->assertTrue($this->service->has($this->account));
        $this->assertFalse($this->service->has($this->other));
    }

    public function testTheStoredSourceIgnoresEverythingElse(): void
    {
        $submission = SignatureSubmission::fromRequest(self::request([
            'signature_source' => 'stored',
            'signature_data' => self::drawing(),
            'remember_signature' => '1',
        ]));

        $this->assertTrue($submission->useStored);
        $this->assertNull($submission->drawing);
        $this->assertFalse($submission->rememberRequested);
    }

    public function testTheBoxIsReadOnlyWhereTheModuleOffersIt(): void
    {
        $submission = SignatureSubmission::fromRequest(
            self::request(['signature_data' => self::drawing(), 'remember_signature' => '1']),
            true,
            false
        );

        $this->assertFalse($submission->rememberRequested);
        $this->assertTrue(SignatureSubmission::fromRequest(self::request([]))->isEmpty());
    }

    /** A path that PHP did not receive with this request is never read, import allowed or not. */
    public function testAFileThatIsNotAnUploadOfThisRequestIsNeverRead(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($path, self::pngBytes());
        $_FILES['signature_file'] = ['tmp_name' => $path, 'name' => 'sig.png', 'error' => 0, 'size' => 10, 'type' => 'image/png'];
        try {
            $this->assertNull(SignatureSubmission::fromRequest(self::request([]))->uploadedPath);
            $this->assertNull(SignatureSubmission::fromRequest(self::request([]), false)->uploadedPath);
        } finally {
            unset($_FILES['signature_file']);
            @unlink($path);
        }
    }

    /**
     * PHP refused the file for its size: the visitor is told so, rather
     * than asked to draw or import a signature they just chose. A witness,
     * who never imports, is not concerned.
     */
    #[\PHPUnit\Framework\Attributes\TestWith([UPLOAD_ERR_INI_SIZE])]
    #[\PHPUnit\Framework\Attributes\TestWith([UPLOAD_ERR_FORM_SIZE])]
    public function testAnImageTooHeavyForTheHostIsRefusedAsSuch(int $error): void
    {
        $_FILES['signature_file'] = ['tmp_name' => '', 'name' => 'sig.png', 'error' => $error, 'size' => 0, 'type' => ''];
        try {
            $this->assertNull(SignatureSubmission::fromRequest(self::request([]), false)->uploadedPath);
            $this->expectException(SignatureException::class);
            $this->expectExceptionMessage(SignatureSubmission::TOO_HEAVY_TO_SEND);
            SignatureSubmission::fromRequest(self::request([]));
        } finally {
            unset($_FILES['signature_file']);
        }
    }

    public function testASubmissionNeverPrintsTheDrawing(): void
    {
        $submission = new SignatureSubmission(false, self::drawing(), null, true);

        $this->assertStringNotContainsString('base64', print_r($submission, true));
    }
}
