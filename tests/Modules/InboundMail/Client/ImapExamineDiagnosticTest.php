<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Modules\InboundMail\Client\ImapMailboxClient;
use Modules\InboundMail\Client\MailboxConnectionException;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Mailbox\SyncState;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailboxFailureDiagnostic;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

final class ImapExamineDiagnosticTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function operations(): array
    {
        $cases = [];
        foreach (['folderState', 'firstReadSelection', 'fetchSince', 'fetchUids'] as $operation) {
            foreach (['NO', 'BAD', 'BYE'] as $status) {
                $cases[$operation . ' ' . $status] = [$operation, $status];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('operations')]
    public function testRealExamineRefusalsKeepTheirCodesWithoutDebugging(string $operation, string $status): void
    {
        $credentials = new MailboxCredentials('box@example.test', 'private-password');
        $signatures = [];
        foreach (['UNAVAILABLE', 'NOPERM', 'UNAVAILABLE', null] as $index => $code) {
            $metadata = $status . ($code === null ? '' : ' [' . $code . ']');
            $server = new ExamineReplyProtocol('A1 ' . $metadata . ' Refused for box@example.test private-password ' . $index . "\r\n");
            $client = new ImapMailboxClient(new ScriptedImapManager($server));
            $client->connect(new Mailbox(1, ProviderType::IMAP, 'imap.example.test', 993, 'ssl',
                $credentials->username, ['INBOX'], true, SyncState::NEVER), $credentials);

            try {
                match ($operation) {
                    'folderState' => $client->folderState('INBOX'),
                    'firstReadSelection' => $client->firstReadSelection('INBOX', new \DateTimeImmutable('2026-01-01'), 10),
                    'fetchSince' => $client->fetchSince('INBOX', 0, 10),
                    default => $client->fetchUids('INBOX', [1]),
                };
                self::fail('The server refused EXAMINE.');
            } catch (MailboxConnectionException $error) {
                $diagnostic = MailboxFailureDiagnostic::from($error, $credentials);
                self::assertSame([$status, $code], [
                    $diagnostic->details['imap_status'] ?? null, $diagnostic->details['imap_code'] ?? null,
                ]);
                self::assertSame($metadata . ' Refused for [masqué] [masqué] ' . $index,
                    $diagnostic->details['server_response']);
                self::assertSame('ResponseException', $error->getMessage());
                self::assertStringNotContainsString('private-password', (new MailboxErrorFormatter())->format($error));
                $signatures[] = $diagnostic->signature();
            } finally {
                $client->disconnect();
            }
            self::assertSame(['EXAMINE'], $server->commands);
        }
        self::assertNotSame($signatures[0], $signatures[1], 'A changed refusal must be reported.');
        self::assertSame($signatures[0], $signatures[2], 'Changing prose must not flood the journal.');
    }

    public function testAnEchoedMultilineCredentialIsMaskedEvenWhenTheProtocolEndsAtItsFirstLine(): void
    {
        $credentials = new MailboxCredentials('box@example.test', "private-prefix\r\nprivate-suffix");
        $server = new ExamineReplyProtocol('A1 NO [AUTHENTICATIONFAILED] ' . $credentials->password . "\r\n");
        $client = new ImapMailboxClient(new ScriptedImapManager($server));
        $client->connect(new Mailbox(1, ProviderType::IMAP, 'imap.example.test', 993, 'ssl',
            $credentials->username, ['INBOX'], true, SyncState::NEVER), $credentials);

        try {
            $client->folderState('INBOX');
            self::fail('The server refused EXAMINE.');
        } catch (MailboxConnectionException $error) {
            $diagnostic = MailboxFailureDiagnostic::from($error, $credentials);
            self::assertSame('NO [AUTHENTICATIONFAILED] [masqué]', $diagnostic->details['server_response']);
        } finally {
            $client->disconnect();
        }
    }
}

/** Only the socket is scripted: EXAMINE parsing and validation are the installed library's. */
final class ExamineReplyProtocol extends ImapProtocol
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<string> */
    private array $replies;

    public function __construct(string $reply)
    {
        parent::__construct(new Config());
        $this->replies = preg_split('/(?<=\n)/', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public function __destruct()
    {
    }

    public function connected(): bool
    {
        return true;
    }

    public function logout(): Response
    {
        return Response::empty()->setResult(true);
    }

    public function folders(string $reference = '', string $folder = '*'): Response
    {
        return Response::empty()->setResult(['INBOX' => ['delimiter' => '/', 'flags' => []]]);
    }

    public function sendRequest(string $command, array $tokens = [], ?string &$tag = null): Response
    {
        $this->commands[] = $command;
        $tag = 'A1';

        return Response::make(1, [$command . ' ' . implode(' ', $tokens)]);
    }

    public function nextLine(Response $response): string
    {
        $line = array_shift($this->replies) ?? throw new \RuntimeException('empty response');
        $response->addResponse($line);

        return $line;
    }
}
