<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealStandalone;

use App\Irc\Adapter\Network\Event\CaseMappingChangedEvent;
use App\Irc\Adapter\Protocol\ChannelBanMatcher;
use App\Irc\Adapter\Protocol\ChannelBanMatcherProvider;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use App\Irc\Application\Port\In\UserModeSupportInterface;
use App\Irc\Domain\ValueObject\CaseMapping;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * UnrealIRCd protocol module: handler, service actions, mode support, and nick reservation.
 */
final class UnrealStandaloneModule implements ProtocolRuntimeModuleInterface, ChannelBanMatcherProvider, EventSubscriberInterface
{
    public const string PROTOCOL_NAME = 'unreal';

    private CaseMapping $caseMapping = CaseMapping::Ascii;

    public function __construct(
        private UnrealStandaloneProtocolHandler $handler,
        private UnrealStandaloneProtocolServiceActions $serviceActions,
        private UnrealStandaloneChannelModeSupport $channelModeSupport,
        private UnrealStandaloneUserModeSupport $userModeSupport,
        private UnrealStandaloneNickReservation $nickReservation,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [CaseMappingChangedEvent::class => 'onCaseMappingChanged'];
    }

    public function onCaseMappingChanged(CaseMappingChangedEvent $event): void
    {
        $this->caseMapping = $event->mapping;
    }

    public function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    public function getHandler(): ProtocolHandlerInterface
    {
        return $this->handler;
    }

    public function getServiceActions(): ProtocolServiceActionsInterface
    {
        return $this->serviceActions;
    }

    public function getChannelModeSupport(): ChannelModeSupportInterface
    {
        return $this->channelModeSupport;
    }

    public function getNickReservation(): ServiceNickReservationInterface
    {
        return $this->nickReservation;
    }

    public function getUserModeSupport(): UserModeSupportInterface
    {
        return $this->userModeSupport;
    }

    public function getMaxNickLength(): int
    {
        return 30;
    }

    public function getChannelBanMatcher(): ChannelBanMatcher
    {
        return new UnrealStandaloneChannelBanMatcher($this->caseMapping->value);
    }
}
