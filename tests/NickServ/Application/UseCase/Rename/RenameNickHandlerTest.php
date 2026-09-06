<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Rename;

use App\NickServ\Application\Service\NickForceService;
use App\NickServ\Application\Service\NickProtectabilityResult;
use App\NickServ\Application\Service\NickTargetValidator;
use App\NickServ\Application\UseCase\Rename\RenameNick;
use App\NickServ\Application\UseCase\Rename\RenameNickHandler;
use App\NickServ\Application\UseCase\Rename\RenameNickOutcome;
use App\NickServ\Application\UseCase\Rename\RenameNickResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenameNickHandler::class)]
#[CoversClass(RenameNick::class)]
#[CoversClass(RenameNickResult::class)]
final class RenameNickHandlerTest extends TestCase
{
    #[Test]
    public function returnsNotOnlineWhenTargetUidIsNull(): void
    {
        $validator = $this->createMock(NickTargetValidator::class);
        $validator->expects(self::never())->method('validate');

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $handler = new RenameNickHandler($validator, $forceService);
        $result = $handler->handle(new RenameNick('offlineuser', null, null, null, null));

        self::assertSame(RenameNickOutcome::NotOnline, $result->outcome);
        self::assertSame('offlineuser', $result->targetNick);
    }

    #[Test]
    public function returnsCannotRenameRootWhenTargetIsRoot(): void
    {
        $validator = $this->createMock(NickTargetValidator::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with('rootuser')
            ->willReturn(NickProtectabilityResult::root('rootuser'));

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $handler = new RenameNickHandler($validator, $forceService);
        $result = $handler->handle(new RenameNick('rootuser', 'UID1', 'root', 'box', '127.0.0.1'));

        self::assertSame(RenameNickOutcome::CannotRenameRoot, $result->outcome);
        self::assertSame('rootuser', $result->targetNick);
    }

    #[Test]
    public function returnsCannotRenameOperWhenTargetIsOper(): void
    {
        $validator = $this->createMock(NickTargetValidator::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with('operuser')
            ->willReturn(NickProtectabilityResult::ircop('operuser'));

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $handler = new RenameNickHandler($validator, $forceService);
        $result = $handler->handle(new RenameNick('operuser', 'UID1', 'oper', 'box', '127.0.0.1'));

        self::assertSame(RenameNickOutcome::CannotRenameOper, $result->outcome);
        self::assertSame('operuser', $result->targetNick);
    }

    #[Test]
    public function returnsCannotRenameServiceWhenTargetIsService(): void
    {
        $validator = $this->createMock(NickTargetValidator::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with('NickServ')
            ->willReturn(NickProtectabilityResult::service('NickServ'));

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $handler = new RenameNickHandler($validator, $forceService);
        $result = $handler->handle(new RenameNick('NickServ', 'UID1', 'service', 'services.irc', '127.0.0.1'));

        self::assertSame(RenameNickOutcome::CannotRenameService, $result->outcome);
        self::assertSame('NickServ', $result->targetNick);
    }

    #[Test]
    public function renamesUserAndReturnsAuditDetailsOnSuccess(): void
    {
        $validator = $this->createMock(NickTargetValidator::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with('troublemaker')
            ->willReturn(NickProtectabilityResult::allowed('troublemaker', null));

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())
            ->method('forceGuestNick')
            ->with('UID99', null, 'ircop-rename');

        $handler = new RenameNickHandler($validator, $forceService, guestPrefix: 'Renamed-');
        $result = $handler->handle(new RenameNick('troublemaker', 'UID99', 'badguy', 'isp.net', '192.168.1.100'));

        self::assertSame(RenameNickOutcome::Success, $result->outcome);
        self::assertSame('troublemaker', $result->targetNick);
        self::assertSame('UID99', $result->targetUid);
        self::assertSame('badguy@isp.net', $result->targetHost);
        self::assertSame('192.168.1.100', $result->targetIp);
        self::assertSame('Renamed-XXXXXXX', $result->newNick);
    }

    #[Test]
    public function handlesNullHostAndIpOnSuccess(): void
    {
        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('user', null));

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())
            ->method('forceGuestNick')
            ->with('UID1', null, 'ircop-rename');

        $handler = new RenameNickHandler($validator, $forceService);
        $result = $handler->handle(new RenameNick('user', 'UID1', null, null, null));

        self::assertSame(RenameNickOutcome::Success, $result->outcome);
        self::assertSame('@', $result->targetHost);
        self::assertSame('', $result->targetIp);
        self::assertSame('Guest-XXXXXXX', $result->newNick);
    }
}
