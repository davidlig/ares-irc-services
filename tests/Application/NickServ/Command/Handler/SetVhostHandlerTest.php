<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\Handler\SetVhostHandler;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\NickServ\VhostValidator;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
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
            new \App\Application\NickServ\PendingVerificationRegistry(),
            new \App\Application\NickServ\RecoveryTokenRegistry(),
        );
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

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver);
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'OFF'), $account, 'OFF');

        self::assertSame(['set.vhost.cleared'], $messages);
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

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver);
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

        $handler = new SetVhostHandler($nickRepo, $validator, $displayResolver);
        $handler->handle($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $notifier, $translator, 'myvhost'), $account, 'myvhost');

        self::assertSame(['set.vhost.success'], $messages);
    }
}
