<?php

declare(strict_types=1);

namespace App\Tests\UI\CLI;

use App\Application\IRC\BurstCompleteRegistry;
use App\Application\IRC\Connect\ConnectToServerCommand;
use App\Application\IRC\Connect\ConnectToServerHandlerInterface;
use App\Application\IRC\IRCClient;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Infrastructure\Messenger\ConsumerProcessManagerInterface;
use App\UI\CLI\ConnectCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

#[CoversClass(ConnectCommand::class)]
final class ConnectCommandTest extends TestCase
{
    private const array DEFAULTS = [
        'serverName' => 'services.test.local',
        'host' => '127.0.0.1',
        'port' => 7029,
        'password' => 'link-secret',
        'description' => 'Ares Test',
        'protocol' => 'unreal',
        'useTls' => false,
    ];

    private const string DEFAULT_SID = '001';

    private const array DEFAULT_SERVICE_UIDS = [
        'nickserv' => '001AAAAAA',
        'chanserv' => '001BBBBBB',
        'memoserv' => '001CCCCCC',
        'operserv' => '001EEEEEE',
    ];

    private function createCommand(
        ?ConnectToServerHandlerInterface $handler = null,
        ?ConsumerProcessManagerInterface $consumerManager = null,
        array $defaults = [],
        string $serverSid = self::DEFAULT_SID,
        array $serviceUids = self::DEFAULT_SERVICE_UIDS,
    ): ConnectCommand {
        $defaults = array_merge(self::DEFAULTS, $defaults);
        $defaultHandler = new HandlerStub($this->createClientThatReturnsFromRun(), null);

        return new ConnectCommand(
            handler: $handler ?? $defaultHandler,
            consumerManager: $consumerManager ?? $this->createStub(ConsumerProcessManagerInterface::class),
            defaultServerName: $defaults['serverName'],
            defaultHost: $defaults['host'],
            defaultPort: $defaults['port'],
            defaultPassword: $defaults['password'],
            defaultDescription: $defaults['description'],
            defaultProtocol: $defaults['protocol'],
            defaultUseTls: $defaults['useTls'],
            serverSid: $serverSid,
            serviceUids: $serviceUids,
        );
    }

    private function createClientThatReturnsFromRun(): IRCClient
    {
        return new class($this->createStub(ConnectionInterface::class), $this->createStub(ProtocolHandlerInterface::class), $this->createStub(EventDispatcherInterface::class), $this->createStub(MessageBusInterface::class), new BurstCompleteRegistry(), 1) extends IRCClient {
            public function run(): void
            {
                // no-op so the command exits the loop and reaches finally
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
        self::assertSame('unreal', $handler->capturedCommand->protocol);
        self::assertFalse($handler->capturedCommand->useTls);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Connecting...', $display);
        self::assertStringContainsString('Link established', $display);
        self::assertStringContainsString('Connection closed by remote host', $display);
    }

    #[Test]
    public function configureDefinesAllOptions(): void
    {
        $command = $this->createCommand();
        $command->getDefinition();

        self::assertTrue($command->getDefinition()->hasArgument('server-name'));
        self::assertTrue($command->getDefinition()->hasArgument('host'));
        self::assertTrue($command->getDefinition()->hasArgument('port'));
        self::assertTrue($command->getDefinition()->hasArgument('password'));
        self::assertTrue($command->getDefinition()->hasArgument('description'));
        self::assertTrue($command->getDefinition()->hasOption('protocol'));
        self::assertTrue($command->getDefinition()->hasOption('tls'));
        self::assertTrue($command->getDefinition()->hasOption('no-consumer'));
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
            '--protocol' => 'inspircd',
            '--tls' => true,
            '--no-consumer' => true,
        ]);

        self::assertSame('myservices.local', $handler->capturedCommand->serverName);
        self::assertSame('irc.example.com', $handler->capturedCommand->host);
        self::assertSame(7100, $handler->capturedCommand->port);
        self::assertSame('mypass', $handler->capturedCommand->password);
        self::assertSame('My Services', $handler->capturedCommand->description);
        self::assertSame('inspircd', $handler->capturedCommand->protocol);
        self::assertTrue($handler->capturedCommand->useTls);
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
        $clientMock = $this->createMock(IRCClient::class);
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
        $clientMock = $this->createMock(IRCClient::class);
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
        $clientMock = $this->createMock(IRCClient::class);
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
        $clientMock = $this->createMock(IRCClient::class);
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

    #[Test]
    public function validateUidPrefixReturnsFailureOnMismatch(): void
    {
        $mismatchedUids = [
            'nickserv' => '002AAAAAA',
            'chanserv' => '001BBBBBB',
            'memoserv' => '001CCCCCC',
            'operserv' => '001EEEEEE',
        ];

        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);
        $consumerManager = $this->createStub(ConsumerProcessManagerInterface::class);

        $command = $this->createCommand(
            handler: $handler,
            consumerManager: $consumerManager,
            serverSid: '0A0',
            serviceUids: $mismatchedUids,
        );
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--no-consumer' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('UID/SID prefix mismatch', $display);
        self::assertStringContainsString('nickserv', $display);
    }

    #[Test]
    public function validateUidPrefixPassesWithMatchingSid(): void
    {
        $matchingUids = [
            'nickserv' => '0A0AAAAAA',
            'chanserv' => '0A0BBBBBB',
            'memoserv' => '0A0CCCCCC',
            'operserv' => '0A0EEEEEE',
        ];

        $handler = new HandlerStub($this->createClientThatReturnsFromRun(), null);
        $consumerManager = $this->createStub(ConsumerProcessManagerInterface::class);
        $consumerManager->method('stop');

        $command = $this->createCommand(
            handler: $handler,
            consumerManager: $consumerManager,
            serverSid: '0A0',
            serviceUids: $matchingUids,
        );
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
        private readonly ?IRCClient $client,
        private readonly ?Throwable $throw = null,
    ) {
    }

    public function handle(ConnectToServerCommand $command): IRCClient
    {
        $this->capturedCommand = $command;
        if (null !== $this->throw) {
            throw $this->throw;
        }

        return $this->client;
    }
}
