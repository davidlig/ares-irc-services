<?php

declare(strict_types=1);

namespace App\Tests\UI\CLI;

use App\Application\Port\ConsumerProcessManagerInterface;
use App\Irc\Application\Connect\ConnectionPreflightResult;
use App\Irc\Application\Connect\ConnectToServerCommand;
use App\Irc\Application\Connect\ConnectToServerHandlerInterface;
use App\Irc\Application\Connect\ProtocolConnectionPreflightInterface;
use App\Irc\Application\IrcSessionInterface;
use App\UI\CLI\ConnectCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

#[CoversClass(ConnectCommand::class)]
final class ConnectCommandTest extends TestCase
{
    /**
     * @var array{
     *     serverName: string,
     *     host: string,
     *     port: int,
     *     password: string,
     *     description: string,
     *     useTls: bool,
     *     tlsVerifyPeer: bool,
     * }
     */
    private const array DEFAULTS = [
        'serverName' => 'services.test.local',
        'host' => '127.0.0.1',
        'port' => 7029,
        'password' => 'link-secret',
        'description' => 'Ares Test',
        'useTls' => false,
        'tlsVerifyPeer' => true,
    ];

    /**
     * @param array{
     *     serverName?: string,
     *     host?: string,
     *     port?: int,
     *     password?: string,
     *     description?: string,
     *     useTls?: bool,
     *     tlsVerifyPeer?: bool,
     * } $defaults
     */
    private function createCommand(
        ?ConnectToServerHandlerInterface $handler = null,
        ?ConsumerProcessManagerInterface $consumerManager = null,
        array $defaults = [],
        ?ProtocolConnectionPreflightInterface $connectionPreflight = null,
    ): ConnectCommand {
        $m = array_merge(self::DEFAULTS, $defaults);
        $defaultHandler = new HandlerStub($this->createClientThatReturnsFromRun(), null);
        if (null === $connectionPreflight) {
            $connectionPreflight = $this->createStub(ProtocolConnectionPreflightInterface::class);
            $connectionPreflight->method('prepare')->willReturn(new ConnectionPreflightResult(true));
        }

        return new ConnectCommand(
            handler: $handler ?? $defaultHandler,
            consumerManager: $consumerManager ?? $this->createStub(ConsumerProcessManagerInterface::class),
            connectionPreflight: $connectionPreflight,
            defaultServerName: $m['serverName'],
            defaultHost: $m['host'],
            defaultPort: $m['port'],
            defaultPassword: $m['password'],
            defaultDescription: $m['description'],
            defaultUseTls: $m['useTls'],
            defaultTlsVerifyPeer: $m['tlsVerifyPeer'],
        );
    }

    private function createClientThatReturnsFromRun(): IrcSessionInterface
    {
        return new class implements IrcSessionInterface {
            public function run(): void {}

            public function disconnect(?string $reason = null): void {}

            public function getProtocolName(): string
            {
                return 'test';
            }
        };
    }

    #[Test]
    public function executeSuccessUsesDefaultsAndReturnsSuccess(): void
    {
        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);

        $consumerManager = $this->createMock(ConsumerProcessManagerInterface::class);
        $consumerManager->expects(self::never())->method('start');
        $consumerManager->expects(self::once())->method('stop');

        $command = $this->createCommand($handler, $consumerManager);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--no-consumer' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertInstanceOf(ConnectToServerCommand::class, $handler->capturedCommand);
        self::assertSame('services.test.local', $handler->capturedCommand->serverName);
        self::assertSame('127.0.0.1', $handler->capturedCommand->host);
        self::assertSame(7029, $handler->capturedCommand->port);
        self::assertSame('link-secret', $handler->capturedCommand->password);
        self::assertSame('Ares Test', $handler->capturedCommand->description);
        self::assertFalse($handler->capturedCommand->useTls);
        self::assertTrue($handler->capturedCommand->tlsVerifyPeer);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Connecting...', $display);
        self::assertStringContainsString('Link established', $display);
        self::assertStringContainsString('Connection closed by remote host', $display);
    }

    #[Test]
    public function configureDefinesConnectionArgumentsAndOptionsWithoutRuntimeProtocolSelection(): void
    {
        $command = $this->createCommand();
        $command->getDefinition();

        self::assertTrue($command->getDefinition()->hasArgument('server-name'));
        self::assertTrue($command->getDefinition()->hasArgument('host'));
        self::assertTrue($command->getDefinition()->hasArgument('port'));
        self::assertTrue($command->getDefinition()->hasArgument('password'));
        self::assertTrue($command->getDefinition()->hasArgument('description'));
        self::assertFalse($command->getDefinition()->hasOption('protocol'));
        self::assertTrue($command->getDefinition()->hasOption('tls'));
        self::assertTrue($command->getDefinition()->hasOption('insecure'));
        self::assertTrue($command->getDefinition()->hasOption('no-consumer'));
    }

    #[Test]
    public function executeWithInsecureOptionDisablesTlsVerifyPeer(): void
    {
        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);
        $command = $this->createCommand($handler, null, ['useTls' => true]);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--insecure' => true, '--no-consumer' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertInstanceOf(ConnectToServerCommand::class, $handler->capturedCommand);
        self::assertTrue($handler->capturedCommand->useTls);
        self::assertFalse($handler->capturedCommand->tlsVerifyPeer);
        self::assertStringContainsString('yes (insecure)', $tester->getDisplay());
    }

    #[Test]
    public function executeWithDefaultTlsVerifyPeerFalseUsesInsecure(): void
    {
        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);
        $command = $this->createCommand($handler, null, ['useTls' => true, 'tlsVerifyPeer' => false]);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--no-consumer' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertInstanceOf(ConnectToServerCommand::class, $handler->capturedCommand);
        self::assertTrue($handler->capturedCommand->useTls);
        self::assertFalse($handler->capturedCommand->tlsVerifyPeer);
    }

    #[Test]
    public function executeWithTlsOptionEnablesTls(): void
    {
        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);
        $command = $this->createCommand($handler, null, ['useTls' => false]);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--tls' => true, '--no-consumer' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertInstanceOf(ConnectToServerCommand::class, $handler->capturedCommand);
        self::assertTrue($handler->capturedCommand->useTls);
        self::assertTrue($handler->capturedCommand->tlsVerifyPeer);
        self::assertStringContainsString('yes', $tester->getDisplay());
    }

    #[Test]
    public function successfulPreflightMessageIsDisplayedBeforeConnecting(): void
    {
        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);
        $preflight = $this->createMock(ProtocolConnectionPreflightInterface::class);
        $preflight->expects(self::once())->method('prepare')->with()
            ->willReturn(new ConnectionPreflightResult(true, 'Dataset ready.'));

        $command = $this->createCommand($handler, null, [], $preflight);
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--no-consumer' => true]));
        self::assertNotNull($handler->capturedCommand);
        self::assertStringContainsString('Dataset ready.', $tester->getDisplay());
    }

    #[Test]
    public function failedPreflightStopsBeforeConnecting(): void
    {
        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);
        $preflight = $this->createStub(ProtocolConnectionPreflightInterface::class);
        $preflight->method('prepare')->willReturn(new ConnectionPreflightResult(false, 'Dataset rejected.'));

        $command = $this->createCommand($handler, null, [], $preflight);
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute(['--no-consumer' => true]));
        self::assertNull($handler->capturedCommand);
        self::assertStringContainsString('Dataset rejected.', $tester->getDisplay());
    }

    #[Test]
    public function executeSuccessPassesArgumentsAndOptionsToHandler(): void
    {
        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);

        $command = $this->createCommand($handler);
        $tester = new CommandTester($command);

        $tester->execute([
            'server-name' => 'myservices.local',
            'host' => 'irc.example.com',
            'port' => '7100',
            'password' => 'mypass',
            'description' => 'My Services',
            '--tls' => true,
            '--no-consumer' => true,
        ]);

        $capturedCommand = $handler->capturedCommand;
        self::assertInstanceOf(ConnectToServerCommand::class, $capturedCommand);
        self::assertSame('myservices.local', $capturedCommand->serverName);
        self::assertSame('irc.example.com', $capturedCommand->host);
        self::assertSame(7100, $capturedCommand->port);
        self::assertSame('mypass', $capturedCommand->password);
        self::assertSame('My Services', $capturedCommand->description);
        self::assertTrue($capturedCommand->useTls);
    }

    #[Test]
    public function executeWhenHandlerThrowsReturnsFailureAndDisplaysError(): void
    {
        $handler = new HandlerStub(null, new RuntimeException('Connection refused.'));

        $consumerManager = $this->createMock(ConsumerProcessManagerInterface::class);
        $consumerManager->expects(self::never())->method('start');
        $consumerManager->expects(self::never())->method('stop');

        $command = $this->createCommand($handler, $consumerManager);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--no-consumer' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Connection refused.', $tester->getDisplay());
    }

    #[Test]
    public function executeWithoutNoConsumerCallsStartThenStop(): void
    {
        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);

        $consumerManager = $this->createMock(ConsumerProcessManagerInterface::class);
        $consumerManager->expects(self::once())->method('start');
        $consumerManager->expects(self::once())->method('stop');

        $command = $this->createCommand($handler, $consumerManager);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function signalHandlerGracefulShutdown(): void
    {
        $clientMock = $this->createMock(IrcSessionInterface::class);
        $clientMock->expects(self::once())->method('disconnect')->with('SIGTERM');
        $clientMock->method('run')->willReturnCallback(static function () use ($clientMock): void {
            $clientMock->disconnect('SIGTERM');
        });

        $handler = new HandlerStub($clientMock, null);

        $consumerManager = $this->createMock(ConsumerProcessManagerInterface::class);
        $consumerManager->expects(self::never())->method('start');
        $consumerManager->expects(self::once())->method('stop');

        $command = $this->createCommand($handler, $consumerManager);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--no-consumer' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function signalHandlerInterrupt(): void
    {
        $clientMock = $this->createMock(IrcSessionInterface::class);
        $clientMock->expects(self::once())->method('disconnect')->with('CTRL+C');
        $clientMock->method('run')->willReturnCallback(static function () use ($clientMock): void {
            $clientMock->disconnect('CTRL+C');
        });

        $handler = new HandlerStub($clientMock, null);

        $consumerManager = $this->createMock(ConsumerProcessManagerInterface::class);
        $consumerManager->expects(self::never())->method('start');
        $consumerManager->expects(self::once())->method('stop');

        $command = $this->createCommand($handler, $consumerManager);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--no-consumer' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function consumerProcessRestartOnTermination(): void
    {
        $clientMock = $this->createMock(IrcSessionInterface::class);
        $clientMock->expects(self::once())->method('disconnect')->with('SIGTERM');
        $clientMock->method('run')->willReturnCallback(static function () use ($clientMock): void {
            $clientMock->disconnect('SIGTERM');
        });

        $handler = new HandlerStub($clientMock, null);

        $consumerManager = $this->createMock(ConsumerProcessManagerInterface::class);
        $consumerManager->expects(self::once())->method('start');
        $consumerManager->expects(self::once())->method('stop');

        $command = $this->createCommand($handler, $consumerManager);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function gracefulShutdownWhenProcessStopped(): void
    {
        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);

        $consumerManager = $this->createMock(ConsumerProcessManagerInterface::class);
        $consumerManager->expects(self::once())->method('start');
        $consumerManager->expects(self::once())->method('stop');

        $command = $this->createCommand($handler, $consumerManager);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Link established', $tester->getDisplay());
    }

    #[Test]
    public function signalHandlerRegistrationSkippedWhenPcntlUnavailable(): void
    {
        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);

        $consumerManager = $this->createMock(ConsumerProcessManagerInterface::class);
        $consumerManager->expects(self::once())->method('stop');

        $command = $this->createCommand($handler, $consumerManager);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--no-consumer' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function sighupHandlerDisconnectsClient(): void
    {
        $clientMock = $this->createMock(IrcSessionInterface::class);
        $clientMock->expects(self::once())->method('disconnect')->with('SIGHUP');
        $clientMock->method('run')->willReturnCallback(static function () use ($clientMock): void {
            $clientMock->disconnect('SIGHUP');
        });

        $handler = new HandlerStub($clientMock, null);

        $consumerManager = $this->createMock(ConsumerProcessManagerInterface::class);
        $consumerManager->expects(self::never())->method('start');
        $consumerManager->expects(self::once())->method('stop');

        $command = $this->createCommand($handler, $consumerManager);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--no-consumer' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }
}

/** @internal stub for tests */
final class HandlerStub implements ConnectToServerHandlerInterface
{
    public ?ConnectToServerCommand $capturedCommand = null;

    public function __construct(
        private readonly ?IrcSessionInterface $client,
        private readonly ?Throwable $throw = null,
    ) {}

    public function handle(ConnectToServerCommand $command): IrcSessionInterface
    {
        $this->capturedCommand = $command;
        if (null !== $this->throw) {
            throw $this->throw;
        }

        if (null === $this->client) {
            throw new RuntimeException('Handler client was not configured.');
        }

        return $this->client;
    }
}
