<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;

/**
 * The library's real client, whose connection is a ScriptedImapServer
 * instead of a socket. Everything else is the parent's code.
 */
final class ScriptedImapClient extends Client
{
    public function __construct(Config $config, private ProtocolInterface $server)
    {
        parent::__construct($config);
    }

    public function connect(): Client
    {
        $this->connection = $this->server;

        return $this;
    }
}
