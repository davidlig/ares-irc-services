<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\ChanServService;
use App\ChanServ\Adapter\In\Irc\Command\DropCommand;
use App\ChanServ\Adapter\In\Irc\Command\SuspendCommand;
use App\ChanServ\Adapter\In\Irc\Command\UnsuspendCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\Service\ChannelSuspensionService;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycleHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\SenderView;
use App\OperServ\Adapter\Out\Security\ConfiguredRootIdentityRegistry;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Application\Security\OperatorPermissionPolicy;
use App\OperServ\Application\Security\RootAuthorizationPolicy;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;
use function class_exists;
use function interface_exists;
use function serialize;

#[CoversClass(ManageChannelLifecycleHandler::class)]
final class ApprovalLifecycleIsolationTest extends TestCase
{
    #[Test]
    public function rootCannotSuspendAndUnsuspendPendingOrRejectedRequests(): void
    {
        foreach ([
            RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#probe', 7, 'description'),
            $this->rejectedChannel(),
        ] as $channel) {
            $before = serialize($channel);
            [$messages, $saved] = $this->route($channel, ['SUSPEND #probe 0 test', 'UNSUSPEND #probe']);

            self::assertSame($before, serialize($channel));
            self::assertSame([], $saved);
            self::assertSame(['error.channel_not_registered', 'error.channel_not_registered'], $messages);
        }
    }

    #[Test]
    public function pendingDropDoesNotThrowOrActivateTheRequest(): void
    {
        $channel = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#probe', 7, 'description');
        $before = serialize($channel);
        [$messages, $saved] = $this->route($channel, ['DROP #probe']);

        self::assertSame($before, serialize($channel));
        self::assertSame([], $saved);
        self::assertSame(['error.channel_not_registered'], $messages);
    }

    #[Test]
    public function activeSuspendAndUnsuspendKeepTheirExistingBehavior(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#probe', 7, 'description');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 41);
        [$messages, $saved] = $this->route($channel, ['SUSPEND #probe 0 test', 'UNSUSPEND #probe']);

        self::assertSame('active', $channel->getStatus()->value);
        self::assertCount(2, $saved);
        self::assertSame(['suspend.success', 'unsuspend.success'], $messages);
    }

    private function rejectedChannel(): RegisteredChannel
    {
        $channel = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#probe', 7, 'description');
        $channel->rejectRegistration('Reason', 9, 'Root', new DateTimeImmutable(), null);

        return $channel;
    }

    /**
     * @param list<string> $commands
     *
     * @return array{list<string>, list<string>}
     */
    private function route(RegisteredChannel $channel, array $commands): array
    {
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($channel);
        $saved = [];
        $repository->method('save')->willReturnCallback(static function (RegisteredChannel $savedChannel) use (&$saved): void {
            $saved[] = serialize($savedChannel);
        });
        $drop = $this->construct(ChanDropService::class, ['channelRepository' => $repository]);
        $lifecycle = new ManageChannelLifecycleHandler(
            $repository,
            $drop,
            $this->construct(ChannelForbiddenService::class, ['channelRepository' => $repository, 'dropService' => $drop]),
            $this->construct(ChannelSuspensionService::class),
            $this->createStub(EventBusInterface::class),
            $this->construct(ChannelHistoryService::class),
        );
        $policy = new OperatorPermissionPolicy(
            new RootAuthorizationPolicy(new ConfiguredRootIdentityRegistry('Root')),
            $this->createStub(OperatorRoleAccess::class),
        );
        $checker = $this->createStub(ChanAuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static function (string $permission, ChanServContext $context) use ($policy): bool {
            if (null === $context->sender) {
                return false;
            }
            if ('IDENTIFIED' === $permission) {
                return $context->sender->isIdentified;
            }

            return $policy->decide(new OperatorActor(
                $context->sender->nick,
                $context->getSenderAccountId(),
                $context->sender->isIdentified,
                $context->sender->isOper,
            ), $permission)->granted;
        });
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $modes = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modes->method('getSupport')->willReturn(new NullChannelModeSupport());
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturn(new ChanAccountView(9, 'Root', 'en'));
        $service = $this->construct(ChanServService::class, [
            'commandRegistry' => new ChanServCommandRegistry([new SuspendCommand($lifecycle), new UnsuspendCommand($lifecycle), new DropCommand($lifecycle, $checker)]),
            'channelRepository' => $repository,
            'notifier' => $notifier,
            'translator' => $translator,
            'authorizationChecker' => $checker,
            'modeSupportProvider' => $modes,
            'accountPort' => $accounts,
        ]);
        $sender = new SenderView('U1', 'Root', 'ident', 'host', 'cloak', '127.0.0.1', isIdentified: true);
        foreach ($commands as $command) {
            $service->dispatch($command, $sender);
        }

        return [$messages, $saved];
    }

    /**
     * @template T of object
     *
     * @param class-string<T>       $class
     * @param array<string, object> $overrides
     *
     * @return T
     */
    private function construct(string $class, array $overrides = []): object
    {
        $reflection = new ReflectionClass($class);
        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (array_key_exists($parameter->getName(), $overrides)) {
                $arguments[] = $overrides[$parameter->getName()];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                $type = $parameter->getType();
                if (!$type instanceof ReflectionNamedType) {
                    throw new LogicException('Test helper requires named constructor parameter types.');
                }
                $typeName = $type->getName();
                if (!class_exists($typeName) && !interface_exists($typeName)) {
                    throw new LogicException('Test helper requires class constructor parameter types.');
                }
                $arguments[] = $this->createStub($typeName);
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
