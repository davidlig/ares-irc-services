<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Message\IRCMessage;
use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;
use App\Infrastructure\IRC\Protocol\UnrealFamily\UnrealFamilyHandshakeTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Implements the UnrealIRCd 4.x / 5.x / 6.x server-to-server link protocol.
 *
 * The handshake, NETINFO, EOS and capability tokens are shared with other
 * Unreal-family protocols via UnrealFamilyHandshakeTrait; this module only
 * keeps its own incoming-command handling.
 */
class UnrealIRCdProtocolHandler extends AbstractProtocolHandler
{
    use UnrealFamilyHandshakeTrait;

    private const string PROTOCOL_NAME = 'unreal';

    public function __construct(
        private readonly string $sid = '001',
        LoggerInterface $logger = new NullLogger(),
        ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        parent::__construct($logger, $eventDispatcher);
    }

    public function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    /**
     * Handles UnrealIRCd-specific incoming commands on top of the base PING/PONG.
     *
     * EOS (End of Sync): the IRCd sends EOS when it finishes its burst. We must
     * respond with our own EOS so UnrealIRCd knows we are ready. Failing to send
     * EOS causes an immediate clean disconnect ("Success" error code).
     */
    public function handleIncoming(IRCMessage $message, ConnectionInterface $connection): void
    {
        parent::handleIncoming($message, $connection);

        match ($message->command) {
            'EOS' => $this->handleEos($connection),
            'NETINFO' => $this->handleNetinfo($message, $connection),
            default => null,
        };
    }
}
