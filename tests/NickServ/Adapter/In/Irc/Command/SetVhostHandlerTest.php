<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\Command\SetVhostHandler;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\ForbiddenVhostRepositoryInterface;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickVhostChangedEvent;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\Service\VhostValidator;
use App\NickServ\Domain\Entity\ForbiddenVhost;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Shared\Application\Port\EventBusInterface;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetVhostHandler::class)]
final class SetVhostHandlerTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        NickServNotifierInterface $notifier,
        TranslationInterface $translator,
        string $value,
    ): NickServContext {
        return new NickServContext(
            $sender,
            null,
            'SET',
            ['VHOST', $value],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider2 = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider3 = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider4 = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }

    #[Test]
    public function emptyOrOffClearsVhostAndRepliesCleared(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeVhost')->with(null);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'OFF'), $account, 'OFF');

        self::assertSame(['set.vhost.cleared'], $messages);
    }

    #[Test]
    public function ircopModeClearsVhostWhenTargetOnline(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeVhost')->with(null);
        $account->method('getNickname')->willReturn('TargetUser');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $targetUser = new SenderView('UID2', 'TargetUser', 'i', 'h', 'cloak', 'b64', false, false, 'SID1');
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('TargetUser')->willReturn($targetUser);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->expects(self::once())->method('setUserVhost')->with('UID2', '', 'SID1');

        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $userLookup, $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'OFF'), $account, 'OFF', true);

        self::assertSame(['set.vhost.cleared'], $messages);
    }

    #[Test]
    public function ircopModeSetsVhostWhenTargetOnline(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeVhost')->with('test');
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('TargetUser');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByVhost')->willReturn(null);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $targetUser = new SenderView('UID2', 'TargetUser', 'i', 'h', 'cloak', 'b64', false, false, 'SID1');
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('TargetUser')->willReturn($targetUser);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->expects(self::once())->method('setUserVhost')->with('UID2', 'test', 'SID1');

        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $userLookup, $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'test'), $account, 'test', true);

        self::assertSame(['set.vhost.success'], $messages);
    }

    #[Test]
    public function ircopModeClearsVhostWhenTargetOffline(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeVhost')->with(null);
        $account->method('getNickname')->willReturn('TargetUser');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('TargetUser')->willReturn(null);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->expects(self::never())->method('setUserVhost');

        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $userLookup, $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'OFF'), $account, 'OFF', true);

        self::assertSame(['set.vhost.cleared'], $messages);
    }

    #[Test]
    public function ircopModeSetsVhostWhenTargetOffline(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeVhost')->with('test');
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('TargetUser');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByVhost')->willReturn(null);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('TargetUser')->willReturn(null);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->expects(self::never())->method('setUserVhost');

        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $userLookup, $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'test'), $account, 'test', true);

        self::assertSame(['set.vhost.success'], $messages);
    }

    #[Test]
    public function invalidVhostRepliesInvalid(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $validator = new VhostValidator();
        // 'bad!' is invalid so normalize would return null in real usage
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'bad!'), $account, 'bad!');

        self::assertSame(['set.vhost.invalid'], $messages);
    }

    #[Test]
    public function validVhostSavesAndRepliesSuccess(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->expects(self::once())->method('changeVhost')->with('myvhost');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByVhost')->willReturn(null);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('.suffix');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setUserVhost')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'myvhost'), $account, 'myvhost');

        self::assertSame(['set.vhost.success'], $messages);
    }

    #[Test]
    public function takenVhostRepliesTaken(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $existingAccount = $this->createStub(RegisteredNick::class);
        $existingAccount->method('getId')->willReturn(2);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByVhost')->willReturn($existingAccount);
        $nickRepo->expects(self::never())->method('save');
        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('.suffix');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'myvhost'), $account, 'myvhost');

        self::assertSame(['set.vhost.taken'], $messages);
    }

    #[Test]
    public function emptyStringClearsVhost(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeVhost')->with(null);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, ''), $account, '');

        self::assertSame(['set.vhost.cleared'], $messages);
    }

    #[Test]
    public function ownVhostNotTaken(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->expects(self::once())->method('changeVhost')->with('myvhost');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByVhost')->willReturn($account);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('.suffix');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'myvhost'), $account, 'myvhost');

        self::assertSame(['set.vhost.success'], $messages);
    }

    #[Test]
    public function userWithForcedVhostCannotChangeIt(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->expects(self::never())->method('changeVhost');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::never())->method('save');

        $forcedVhostChecker = $this->createMock(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->expects(self::once())->method('hasForcedVhost')->with(1)->willReturn(true);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'myvhost'), $account, 'myvhost');

        self::assertSame(['set.vhost.forced'], $messages);
    }

    #[Test]
    public function userWithRoleButNoForcedVhostCanChangeIt(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->expects(self::once())->method('changeVhost')->with('myvhost');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByVhost')->willReturn(null);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $forcedVhostChecker = $this->createMock(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->expects(self::once())->method('hasForcedVhost')->with(1)->willReturn(false);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('.suffix');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setUserVhost')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'myvhost'), $account, 'myvhost');

        self::assertSame(['set.vhost.success'], $messages);
    }

    #[Test]
    public function ircopModeCannotModifyUserWithForcedVhost(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->expects(self::never())->method('changeVhost');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::never())->method('save');

        $forcedVhostChecker = $this->createMock(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->expects(self::once())->method('hasForcedVhost')->with(1)->willReturn(true);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $forcedVhostChecker, $this->createStub(ForbiddenVhostRepositoryInterface::class), $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'test'), $account, 'test', true);

        self::assertSame(['set.vhost.forced'], $messages);
    }

    #[Test]
    public function forbiddenVhostPatternRejectsVhost(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->expects(self::never())->method('changeVhost');

        $forbidden = $this->createMock(ForbiddenVhost::class);
        $forbidden->expects(self::once())->method('matches')->with('pirated.host.com')->willReturn(true);

        $forbiddenRepo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $forbiddenRepo->expects(self::once())->method('findAll')->willReturn([$forbidden]);

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::never())->method('save');

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $userLookup, $forcedVhostChecker, $forbiddenRepo, $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'pirated.host.com'), $account, 'pirated.host.com');

        self::assertSame(['set.vhost.invalid'], $messages);
    }

    #[Test]
    public function forbiddenVhostPatternAllowsCleanVhost(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->expects(self::once())->method('changeVhost')->with('clean.host.com');

        $forbidden = $this->createMock(ForbiddenVhost::class);
        $forbidden->expects(self::once())->method('matches')->with('clean.host.com')->willReturn(false);

        $forbiddenRepo = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $forbiddenRepo->expects(self::once())->method('findAll')->willReturn([$forbidden]);

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByVhost')->willReturn(null);
        $nickRepo->expects(self::once())->method('save');

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $userLookup, $forcedVhostChecker, $forbiddenRepo, $this->createStub(EventBusInterface::class));
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'clean.host.com'), $account, 'clean.host.com');

        self::assertSame(['set.vhost.success'], $messages);
    }

    #[Test]
    public function dispatchesNickVhostChangedEventOnSet(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getId')->willReturn(42);
        $account->method('getNickname')->willReturn('TestNick');
        $account->expects(self::once())->method('changeVhost')->with('myvhost');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByVhost')->willReturn(null);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('.suffix');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(static fn (object $event): bool => $event instanceof NickVhostChangedEvent
                && 42 === $event->nickId
                && 'TestNick' === $event->nickname
                && 'myvhost' === $event->vhost));

        $handler = new SetVhostHandler(
            $nickRepo,
            $validator,
            $displayResolver,
            $this->createStub(NetworkUserLookupPort::class),
            $forcedVhostChecker,
            $this->createStub(ForbiddenVhostRepositoryInterface::class),
            $eventDispatcher,
        );
        $handler->handle($this->createContext(new SenderView('UID1', 'TestNick', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'myvhost'), $account, 'myvhost');

        self::assertSame(['set.vhost.success'], $messages);
    }

    #[Test]
    public function dispatchesNickVhostChangedEventOnClear(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('getId')->willReturn(42);
        $account->method('getNickname')->willReturn('TestNick');
        $account->expects(self::once())->method('changeVhost')->with(null);

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $forcedVhostChecker = $this->createStub(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->method('hasForcedVhost')->willReturn(false);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(static fn (object $event): bool => $event instanceof NickVhostChangedEvent
                && 42 === $event->nickId
                && 'TestNick' === $event->nickname
                && null === $event->vhost));

        $handler = new SetVhostHandler(
            $nickRepo,
            $validator,
            $displayResolver,
            $this->createStub(NetworkUserLookupPort::class),
            $forcedVhostChecker,
            $this->createStub(ForbiddenVhostRepositoryInterface::class),
            $eventDispatcher,
        );
        $handler->handle($this->createContext(new SenderView('UID1', 'TestNick', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'OFF'), $account, 'OFF');

        self::assertSame(['set.vhost.cleared'], $messages);
    }
}
