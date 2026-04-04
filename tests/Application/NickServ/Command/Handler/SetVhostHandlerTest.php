<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\Handler\SetVhostHandler;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\NickServ\VhostValidator;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetVhostHandler::class)]
final class SetVhostHandlerTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
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
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $ircopRepo);
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

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $userLookup, $ircopRepo);
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

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $userLookup, $ircopRepo);
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

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $userLookup, $ircopRepo);
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

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $userLookup, $ircopRepo);
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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $ircopRepo);
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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $ircopRepo);
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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $ircopRepo);
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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $ircopRepo);
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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $ircopRepo);
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

        $role = $this->createStub(OperRole::class);
        $role->method('getForcedVhostPattern')->willReturn('admin.test');

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->expects(self::once())->method('findByNickId')->with(1)->willReturn($ircop);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $ircopRepo);
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

        $role = $this->createStub(OperRole::class);
        $role->method('getForcedVhostPattern')->willReturn(null);

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->expects(self::once())->method('findByNickId')->with(1)->willReturn($ircop);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('.suffix');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setUserVhost')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $ircopRepo);
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

        $role = $this->createStub(OperRole::class);
        $role->method('getForcedVhostPattern')->willReturn('admin.test');

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->expects(self::once())->method('findByNickId')->with(1)->willReturn($ircop);

        $validator = new VhostValidator();
        $displayResolver = new VhostDisplayResolver('');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver, $this->createStub(NetworkUserLookupPort::class), $ircopRepo);
        $handler->handle($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'test'), $account, 'test', true);

        self::assertSame(['set.vhost.forced'], $messages);
    }
}
