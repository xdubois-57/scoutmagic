<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Modules\InboundMail\Client\MailboxConnectionException;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Exceptions\RuntimeException as ImapRuntimeException;

/**
 * Which sentence a mailbox failure becomes (#1325), with the library's own
 * exceptions built the way it builds them.
 *
 * « Le serveur n'a pas répondu » is for a connection that did not happen.
 * Every IMAP failure used to arrive under the same wrapping class, whose
 * name holds « connection », and every one of them was called that — an
 * EXAMINE refused after a successful login included.
 */
class MailboxErrorFormatterTest extends TestCase
{
    private const SECRET = 'chef@unite.be mot-de-passe-2026';

    private MailboxErrorFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MailboxErrorFormatter();
    }

    public function testARefusedLoginIsAnAuthenticationProblemWhateverItIsWrappedIn(): void
    {
        $sentence = $this->format(MailboxConnectionException::STEP_CONNECT, new AuthFailedException(
            'failed to authenticate',
            0,
            new ImapServerErrorException('NO [AUTHENTICATIONFAILED] ' . self::SECRET)
        ));

        $this->assertStringContainsString('refusé l\'authentification', $sentence);
    }

    public function testACertificateRefusalIsSaidAsSuch(): void
    {
        $sentence = $this->format(MailboxConnectionException::STEP_CONNECT, new ConnectionFailedException(
            'connection failed',
            0,
            new \ErrorException('stream_socket_client(): SSL operation failed: certificate verify failed')
        ));

        $this->assertStringContainsString('certificat', $sentence);
    }

    public function testASocketThatNeverOpenedIsAServerThatDidNotAnswer(): void
    {
        $sentence = $this->format(
            MailboxConnectionException::STEP_CONNECT,
            new ConnectionFailedException('connection refused')
        );

        $this->assertStringContainsString('n\'a pas répondu', $sentence);
    }

    public function testAServerThatAnswersButRefusesTheSessionDidAnswer(): void
    {
        $sentence = $this->format(
            MailboxConnectionException::STEP_CONNECT,
            new ImapServerErrorException('NO ' . self::SECRET)
        );

        $this->assertStringNotContainsString('n\'a pas répondu', $sentence);
        $this->assertStringContainsString('refusé d\'ouvrir la session', $sentence);
    }

    public function testAFolderRefusedAfterLoginNamesTheStepAndNotTheHost(): void
    {
        $sentence = $this->format(
            MailboxConnectionException::STEP_OPEN_FOLDER,
            new ImapServerErrorException('NO [UNAVAILABLE] EXAMINE refused for ' . self::SECRET)
        );

        $this->assertStringNotContainsString('n\'a pas répondu', $sentence);
        $this->assertStringContainsString('l\'ouverture d\'un dossier surveillé', $sentence);
        $this->assertStringContainsString('ImapServerErrorException', $sentence);
    }

    public function testAFolderTheServerSaysDoesNotExistIsAMissingFolder(): void
    {
        $sentence = $this->format(
            MailboxConnectionException::STEP_OPEN_FOLDER,
            new ImapRuntimeException("folder doesn't exist")
        );

        $this->assertStringContainsString('introuvable', $sentence);
    }

    public function testAFolderTheClientCouldNotFindIsAMissingFolder(): void
    {
        $sentence = $this->formatter->format(new MailboxConnectionException(
            'Le dossier demandé est introuvable sur le serveur.',
            0,
            null,
            MailboxConnectionException::STEP_OPEN_FOLDER
        ));

        $this->assertStringContainsString('introuvable', $sentence);
    }

    /** The folder by its name, so the operator knows which one to fix (#1343). */
    public function testAMissingFolderIsNamedWhenTheClientSaysWhich(): void
    {
        $sentence = $this->formatter->format(new MailboxConnectionException(
            'Le dossier demandé est introuvable sur le serveur.',
            0,
            null,
            MailboxConnectionException::STEP_OPEN_FOLDER,
            'INBOX.Archives 2025'
        ));

        $this->assertStringContainsString('« INBOX.Archives 2025 »', $sentence);
        $this->assertStringContainsString('introuvable', $sentence);
    }

    public function testASessionCutDuringTheFetchIsNotCalledAServerThatNeverAnswered(): void
    {
        $sentence = $this->format(MailboxConnectionException::STEP_FETCH, new ImapRuntimeException('empty response'));

        $this->assertStringNotContainsString('n\'a pas répondu', $sentence);
        $this->assertStringContainsString('coupée', $sentence);
        $this->assertStringContainsString('la lecture des messages', $sentence);
    }

    public function testAnUnknownFailureOfAStepStillNamesTheStepAndTheClass(): void
    {
        $sentence = $this->format(MailboxConnectionException::STEP_LIST, new \LogicException(self::SECRET));

        $this->assertStringContainsString('la lecture de la liste des dossiers', $sentence);
        $this->assertStringContainsString('LogicException', $sentence);
    }

    public function testAFailureAfterTheMessagesWereReadIsAProcessingFailure(): void
    {
        $sentence = $this->formatter->format(new \PDOException('SQLSTATE[HY000] ' . self::SECRET));

        $this->assertStringContainsString('traitement', $sentence);
        $this->assertStringContainsString('PDOException', $sentence);
    }

    public function testAWrapperWithoutAStepIsNotCalledAConnectionFailureForItsOwnName(): void
    {
        $sentence = $this->formatter->format(new MailboxConnectionException('ResponseException'));

        $this->assertStringNotContainsString('n\'a pas répondu', $sentence);
    }

    public function testNoSentenceAndNoDiagnosticEverQuotesTheServer(): void
    {
        $causes = [
            [MailboxConnectionException::STEP_CONNECT, new ConnectionFailedException(self::SECRET)],
            [MailboxConnectionException::STEP_CONNECT, new ImapServerErrorException(self::SECRET)],
            [MailboxConnectionException::STEP_OPEN_FOLDER, new ImapServerErrorException(self::SECRET)],
            [MailboxConnectionException::STEP_FETCH, new ImapRuntimeException(self::SECRET)],
            [MailboxConnectionException::STEP_LIST, new \LogicException(self::SECRET)],
        ];

        foreach ($causes as [$step, $cause]) {
            $error = MailboxConnectionException::at($step, $cause);
            $this->assertStringNotContainsString('chef@unite.be', $this->formatter->format($error));
            $this->assertStringNotContainsString('mot-de-passe', $this->formatter->format($error));
            $this->assertStringNotContainsString('chef@unite.be', $this->formatter->diagnostic($error));
        }
    }

    public function testTheDiagnosticNamesTheStepAndTheClassesOfTheCauses(): void
    {
        $error = MailboxConnectionException::at(
            MailboxConnectionException::STEP_CONNECT,
            new AuthFailedException('failed to authenticate', 0, new ImapServerErrorException(self::SECRET))
        );

        $this->assertSame(
            'step=connect; causes=AuthFailedException > ImapServerErrorException',
            $this->formatter->diagnostic($error)
        );
        $this->assertSame('step=processing; causes=PDOException', $this->formatter->diagnostic(new \PDOException('x')));
    }

    private function format(string $step, \Throwable $cause): string
    {
        return $this->formatter->format(MailboxConnectionException::at($step, $cause));
    }
}
