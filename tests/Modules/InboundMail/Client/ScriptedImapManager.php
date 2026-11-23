<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;

/**
 * `ClientManager::make()` exactly as the library writes it — the vendor
 * defaults merged with the account options the caller passes — except
 * that the client it builds talks to a ScriptedImapServer.
 */
final class ScriptedImapManager extends ClientManager
{
    public function __construct(private ProtocolInterface $server)
    {
        parent::__construct();
    }

    public function make(array $config): Client
    {
        $name = $this->config->getDefaultAccount();
        $clientConfig = $this->config->all();
        $clientConfig['accounts'] = [$name => $config];

        return new ScriptedImapClient(Config::make($clientConfig), $this->server);
    }
}
