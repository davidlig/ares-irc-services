<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/** A protocol that derives the network's operator-only mode from stored channel options. */
interface OperatorOnlyModeManagedByOptions extends ProtocolModuleInterface {}
