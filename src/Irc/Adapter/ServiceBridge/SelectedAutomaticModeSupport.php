<?php

declare(strict_types=1);

namespace App\Irc\Adapter\ServiceBridge;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Application\Port\In\AutomaticModeDelivery;
use App\Irc\Application\Port\In\AutomaticModeSupport;
use App\Irc\Application\Port\In\LocalUserModeSyncPort;
use App\Irc\Application\Port\In\NativeAutomaticModeSupport;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class SelectedAutomaticModeSupport implements AutomaticModeSupport, AutomaticModeDelivery
{
    public function __construct(
        private ActiveConnectionHolder $connection,
        private LoggerInterface $logger,
        private LocalUserModeSyncPort $localModes,
    ) {}

    public function getAutomaticModes(): ?array
    {
        if (!$this->connection->isConnected()) {
            return null;
        }
        $handler = $this->connection->getProtocolHandler();

        return $handler instanceof AutomaticModeSupport ? $handler->getAutomaticModes() : null;
    }

    public function usesNativeProfiles(): bool
    {
        return $this->connection->getProtocolHandler() instanceof NativeAutomaticModeSupport;
    }

    public function changeUserModes(string $uid, string $delta): bool
    {
        $module = $this->connection->getProtocolModule();
        $sid = $this->connection->getServerSid();
        if ($this->usesNativeProfiles() || !$this->connection->isConnected() || null === $module || null === $sid) {
            return false;
        }
        try {
            $module->getServiceActions()->setUserMode($sid, $uid, $delta);
            // Forced changes need not be echoed back by the selected IRCd.
            $this->localModes->apply($uid, $delta);

            return true;
        } catch (Throwable) {
            $this->logger->warning('Automatic nickname modes could not be delivered.', ['uid' => $uid]);

            return false;
        }
    }
}
