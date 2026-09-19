<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Drop;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Application\Service\NickProtectabilityResult;
use App\NickServ\Application\Service\NickTargetValidator;
use App\NickServ\Application\UseCase\Drop\DropNick;
use App\NickServ\Application\UseCase\Drop\DropNickHandler;
use App\NickServ\Application\UseCase\Drop\DropNickOutcome;
use App\NickServ\Application\UseCase\Drop\DropNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DropNickHandler::class)]
#[CoversClass(DropNick::class)]
#[CoversClass(DropNickResult::class)]
final class DropNickHandlerTest extends TestCase
{
    #[Test]
    public function returnsCannotDropSelfWhenTargetMatchesOperator(): void
    {
        $handler = new DropNickHandler(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
        );

        $result = $handler->handle(new DropNick(targetNick: 'MyNick', operatorNick: 'mynick', occurredAt: new DateTimeImmutable()));

        self::assertSame(DropNickOutcome::CannotDropSelf, $result->outcome);
    }

    #[Test]
    public function returnsNotRegisteredWhenAccountNotFound(): void
    {
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('Unknown')->willReturn(null);

        $handler = new DropNickHandler(
            $repository,
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
        );

        $result = $handler->handle(new DropNick(targetNick: 'Unknown', operatorNick: 'Oper', occurredAt: new DateTimeImmutable()));

        self::assertSame(DropNickOutcome::NotRegistered, $result->outcome);
        self::assertSame('Unknown', $result->nickname);
    }

    #[Test]
    public function returnsPendingDeletionWhenAlreadyPendingAndNotForced(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('PendingNick')->willReturn($account);

        $handler = new DropNickHandler(
            $repository,
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
        );

        $result = $handler->handle(new DropNick(targetNick: 'PendingNick', operatorNick: 'Oper', occurredAt: new DateTimeImmutable(), force: false));

        self::assertSame(DropNickOutcome::PendingDeletion, $result->outcome);
        self::assertSame('PendingNick', $result->nickname);
    }

    #[Test]
    public function returnsForcePermissionDeniedWhenPendingDeletionAndForceNotAllowed(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('PendingNick')->willReturn($account);

        $handler = new DropNickHandler(
            $repository,
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
        );

        $result = $handler->handle(new DropNick(
            targetNick: 'PendingNick',
            operatorNick: 'Oper',
            occurredAt: new DateTimeImmutable(),
            force: true,
            forceAllowed: false,
        ));

        self::assertSame(DropNickOutcome::ForcePermissionDenied, $result->outcome);
    }

    #[Test]
    public function executesHardDropWhenPendingDeletionAndForceAllowed(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('PendingNick')->willReturn($account);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())->method('hardDropNick')->with($account, self::isInstanceOf(DateTimeImmutable::class), 'manual-force', 'Oper');

        $handler = new DropNickHandler(
            $repository,
            $this->createStub(NickTargetValidator::class),
            $dropService,
        );

        $result = $handler->handle(new DropNick(
            targetNick: 'PendingNick',
            operatorNick: 'Oper',
            occurredAt: new DateTimeImmutable(),
            force: true,
            forceAllowed: true,
        ));

        self::assertSame(DropNickOutcome::HardDropSuccess, $result->outcome);
        self::assertSame('PendingNick', $result->nickname);
    }

    #[Test]
    public function returnsSuspendedWhenAccountIsSuspended(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isSuspended')->willReturn(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('SuspendedNick')->willReturn($account);

        $handler = new DropNickHandler(
            $repository,
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
        );

        $result = $handler->handle(new DropNick(targetNick: 'SuspendedNick', operatorNick: 'Oper', occurredAt: new DateTimeImmutable()));

        self::assertSame(DropNickOutcome::Suspended, $result->outcome);
        self::assertSame('SuspendedNick', $result->nickname);
    }

    #[Test]
    public function returnsForbiddenWhenAccountIsForbidden(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ForbiddenNick')->willReturn($account);

        $handler = new DropNickHandler(
            $repository,
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
        );

        $result = $handler->handle(new DropNick(targetNick: 'ForbiddenNick', operatorNick: 'Oper', occurredAt: new DateTimeImmutable()));

        self::assertSame(DropNickOutcome::Forbidden, $result->outcome);
        self::assertSame('ForbiddenNick', $result->nickname);
    }

    #[Test]
    public function returnsCannotDropRootWhenProtectabilityDeniesRoot(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('RootNick')->willReturn($account);

        $validator = $this->createMock(NickTargetValidator::class);
        $validator->expects(self::once())->method('validate')->with('RootNick')
            ->willReturn(NickProtectabilityResult::root('RootNick'));

        $handler = new DropNickHandler($repository, $validator, $this->createStub(NickDropService::class));
        $result = $handler->handle(new DropNick(targetNick: 'RootNick', operatorNick: 'Oper', occurredAt: new DateTimeImmutable()));

        self::assertSame(DropNickOutcome::CannotDropRoot, $result->outcome);
        self::assertSame('RootNick', $result->nickname);
    }

    #[Test]
    public function returnsCannotDropOperWhenProtectabilityDeniesOper(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('OperNick')->willReturn($account);

        $validator = $this->createMock(NickTargetValidator::class);
        $validator->expects(self::once())->method('validate')->with('OperNick')
            ->willReturn(NickProtectabilityResult::ircop('OperNick'));

        $handler = new DropNickHandler($repository, $validator, $this->createStub(NickDropService::class));
        $result = $handler->handle(new DropNick(targetNick: 'OperNick', operatorNick: 'Oper', occurredAt: new DateTimeImmutable()));

        self::assertSame(DropNickOutcome::CannotDropOper, $result->outcome);
        self::assertSame('OperNick', $result->nickname);
    }

    #[Test]
    public function returnsCannotDropServiceWhenProtectabilityDeniesService(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('NickServ')->willReturn($account);

        $validator = $this->createMock(NickTargetValidator::class);
        $validator->expects(self::once())->method('validate')->with('NickServ')
            ->willReturn(NickProtectabilityResult::service('NickServ'));

        $handler = new DropNickHandler($repository, $validator, $this->createStub(NickDropService::class));
        $result = $handler->handle(new DropNick(targetNick: 'NickServ', operatorNick: 'Oper', occurredAt: new DateTimeImmutable()));

        self::assertSame(DropNickOutcome::CannotDropService, $result->outcome);
        self::assertSame('NickServ', $result->nickname);
    }

    #[Test]
    public function returnsForcePermissionDeniedWhenActiveAndForceNotAllowed(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ActiveNick')->willReturn($account);

        $validator = $this->createMock(NickTargetValidator::class);
        $validator->expects(self::once())->method('validate')->with('ActiveNick')
            ->willReturn(NickProtectabilityResult::allowed('ActiveNick', $account));

        $handler = new DropNickHandler($repository, $validator, $this->createStub(NickDropService::class));
        $result = $handler->handle(new DropNick(
            targetNick: 'ActiveNick',
            operatorNick: 'Oper',
            occurredAt: new DateTimeImmutable(),
            force: true,
            forceAllowed: false,
        ));

        self::assertSame(DropNickOutcome::ForcePermissionDenied, $result->outcome);
    }

    #[Test]
    public function executesHardDropWhenActiveAndForceAllowed(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ActiveNick')->willReturn($account);

        $validator = $this->createMock(NickTargetValidator::class);
        $validator->expects(self::once())->method('validate')->with('ActiveNick')
            ->willReturn(NickProtectabilityResult::allowed('ActiveNick', $account));

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())->method('hardDropNick')->with($account, self::isInstanceOf(DateTimeImmutable::class), 'manual-force', 'Oper');

        $handler = new DropNickHandler($repository, $validator, $dropService);
        $result = $handler->handle(new DropNick(
            targetNick: 'ActiveNick',
            operatorNick: 'Oper',
            occurredAt: new DateTimeImmutable(),
            force: true,
            forceAllowed: true,
        ));

        self::assertSame(DropNickOutcome::HardDropSuccess, $result->outcome);
        self::assertSame('ActiveNick', $result->nickname);
    }

    #[Test]
    public function executesSoftDropWhenActiveAndNotForced(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ActiveNick')->willReturn($account);

        $validator = $this->createMock(NickTargetValidator::class);
        $validator->expects(self::once())->method('validate')->with('ActiveNick')
            ->willReturn(NickProtectabilityResult::allowed('ActiveNick', $account));

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())->method('softDropNick')->with($account, self::isInstanceOf(DateTimeImmutable::class), 'Oper');

        $handler = new DropNickHandler($repository, $validator, $dropService);
        $result = $handler->handle(new DropNick(targetNick: 'ActiveNick', operatorNick: 'Oper', occurredAt: new DateTimeImmutable()));

        self::assertSame(DropNickOutcome::SoftDropSuccess, $result->outcome);
        self::assertSame('ActiveNick', $result->nickname);
    }
}
