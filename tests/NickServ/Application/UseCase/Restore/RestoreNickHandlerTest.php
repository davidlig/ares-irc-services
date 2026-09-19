<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Restore;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Application\UseCase\Restore\RestoreNick;
use App\NickServ\Application\UseCase\Restore\RestoreNickHandler;
use App\NickServ\Application\UseCase\Restore\RestoreNickOutcome;
use App\NickServ\Application\UseCase\Restore\RestoreNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RestoreNickHandler::class)]
#[CoversClass(RestoreNick::class)]
#[CoversClass(RestoreNickResult::class)]
final class RestoreNickHandlerTest extends TestCase
{
    #[Test]
    public function returnsNotRegisteredWhenAccountNotFound(): void
    {
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('Unknown')->willReturn(null);
        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::never())->method('restoreNick');

        $handler = new RestoreNickHandler($repository, $dropService);
        $result = $handler->handle(new RestoreNick(nickname: 'Unknown', operatorNick: 'Oper'));

        self::assertSame(RestoreNickOutcome::NotRegistered, $result->outcome);
        self::assertSame('Unknown', $result->nickname);
    }

    #[Test]
    public function returnsNotPendingDeletionWhenAccountIsNotPendingDeletion(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(false);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ActiveNick')->willReturn($account);
        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::never())->method('restoreNick');

        $handler = new RestoreNickHandler($repository, $dropService);
        $result = $handler->handle(new RestoreNick(nickname: 'ActiveNick', operatorNick: 'Oper'));

        self::assertSame(RestoreNickOutcome::NotPendingDeletion, $result->outcome);
        self::assertSame('ActiveNick', $result->nickname);
    }

    #[Test]
    public function restoresNickAndReturnsSuccessWhenPendingDeletion(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('DeletedNick')->willReturn($account);
        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())->method('restoreNick')->with($account, 'Oper');

        $handler = new RestoreNickHandler($repository, $dropService);
        $result = $handler->handle(new RestoreNick(nickname: 'DeletedNick', operatorNick: 'Oper'));

        self::assertSame(RestoreNickOutcome::Success, $result->outcome);
        self::assertSame('DeletedNick', $result->nickname);
    }
}
