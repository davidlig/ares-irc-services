<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Runtime;

use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Protocol\RawCommandInterception;
use App\Irc\Adapter\Protocol\RawCommandInterceptionOutcome;
use App\Irc\Adapter\Protocol\RawCommandInterceptorInterface;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Adapter\Runtime\SelectedRawCommandInterceptor;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use App\Irc\Application\Port\In\UserModeSupportInterface;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SelectedRawCommandInterceptor::class)]
final class SelectedRawCommandInterceptorTest extends TestCase
{
    #[Test]
    public function ignoresRawCommandsWhenTheSelectedModuleHasNoInterceptor(): void
    {
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);

        $result = new SelectedRawCommandInterceptor($module)->intercept(['OPAQUE']);

        self::assertSame(RawCommandInterceptionOutcome::NotHandled, $result->outcome);
    }

    #[Test]
    public function delegatesToTheSelectedModuleWhenItOwnsRawCommands(): void
    {
        $module = new RawAwareRuntimeModule();

        $result = new SelectedRawCommandInterceptor($module)->intercept(['OPAQUE']);

        self::assertSame(['OPAQUE'], $module->arguments);
        self::assertSame(RawCommandInterceptionOutcome::Executed, $result->outcome);
        self::assertSame('PROTOCOL ACTION', $result->operation);
    }
}

final class RawAwareRuntimeModule implements ProtocolRuntimeModuleInterface, RawCommandInterceptorInterface
{
    /** @var list<string> */
    public array $arguments = [];

    public function intercept(array $arguments): RawCommandInterception
    {
        $this->arguments = $arguments;

        return RawCommandInterception::executed('PROTOCOL ACTION');
    }

    public function getProtocolName(): string
    {
        return 'test';
    }

    public function getHandler(): ProtocolHandlerInterface
    {
        throw new LogicException('Not needed by this test.');
    }

    public function getServiceActions(): ProtocolServiceActionsInterface
    {
        throw new LogicException('Not needed by this test.');
    }

    public function getChannelModeSupport(): ChannelModeSupportInterface
    {
        throw new LogicException('Not needed by this test.');
    }

    public function getNickReservation(): ServiceNickReservationInterface
    {
        throw new LogicException('Not needed by this test.');
    }

    public function getUserModeSupport(): UserModeSupportInterface
    {
        throw new LogicException('Not needed by this test.');
    }
}
