<?php

declare(strict_types=1);

namespace Accord\Server;

use Accord\Core\Protocol;

/** The Accord sync server. P2 fills it in; for now it states the protocol it will speak. */
final class AccordServer
{
    public const int PROTOCOL_VERSION = Protocol::VERSION;
}
