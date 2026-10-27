<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\RegistrationModeQuery;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\ChanServ\Application\UseCase\CleanupDroppedNick\CleanupDroppedNickData;
use App\ChanServ\Application\UseCase\CleanupDroppedNick\CleanupDroppedNickDataHandler;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannel;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionClass;
use ReflectionNamedType;

#[CoversClass(CleanupDroppedNickDataHandler::class)]
#[CoversClass(RegisteredChannelDoctrineRepository::class)]
#[CoversClass(RegisterChannelHandler::class)]
#[CoversClass(ChanDropService::class)]
final class ApprovalRestrictionCleanupTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function mappedRegistrationSchemaDoesNotHaveDedicatedAuditStorage(): void
    {
        self::assertFalse($this->entityManager->getConnection()->createSchemaManager()->tablesExist(['channel_registration_audit']));
    }

    #[Test]
    public function recoverableDropAndRestorePreserveRegistrationUntilDefinitiveDrop(): void
    {
        $at = new DateTimeImmutable('2026-10-27T12:00:00+00:00');
        $channels = new RegisteredChannelDoctrineRepository($this->entityManager);
        $channel = RegisteredChannel::register($at, '#test', 7, 'description');
        $channels->save($channel);
        $drop = $this->construct(ChanDropService::class, [
            'channelRepository' => $channels,
            'transactionBoundary' => $this->transactionBoundary(),
        ]);

        $drop->softDropChannel($channel, $at);
        self::assertTrue($channel->isPendingDeletion());
        $drop->restoreChannel($channel, $at);
        self::assertFalse($channel->isPendingDeletion());
        $drop->hardDropChannel($channel, $at);
        self::assertNull($channels->findByChannelName('#test'));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function expiredRejectionReplacementStartsFreshAndFailurePreservesRejection(bool $fail): void
    {
        $at = new DateTimeImmutable('2026-10-27T12:00:00+00:00');
        $channels = new RegisteredChannelDoctrineRepository($this->entityManager);
        $channel = RegisteredChannel::createPendingRegistration($at->modify('-2 days'), '#test', 7, 'description');
        $channel->rejectRegistration('Reason', 9, 'Root', $at->modify('-2 days'), $at->modify('-1 day'));
        $channels->save($channel);
        $mode = $this->createStub(RegistrationModeQuery::class);
        $mode->method('current')->willReturn(RegistrationMode::Approval);
        $register = $this->construct(RegisterChannelHandler::class, [
            'channelRepository' => $channels,
            'activator' => $this->construct(ChannelRegistrationActivator::class),
            'registrationMode' => $mode,
            'transactionBoundary' => $this->transactionBoundary(),
        ]);
        $command = new RegisterChannel('#TEST', 'New request', 8, 'Other', true, false, true, true, $at);
        $connection = $this->entityManager->getConnection();
        $beforeChannel = $connection->fetchAllAssociative('SELECT * FROM registered_channels');
        if ($fail) {
            $connection->executeStatement("CREATE TRIGGER prevent_insert BEFORE INSERT ON registered_channels BEGIN SELECT RAISE(ABORT, 'Replacement failed'); END");
        }
        try {
            $result = $register->handle($command);
        } catch (DriverException) {
            self::assertTrue($fail);
            self::assertSame($beforeChannel, $connection->fetchAllAssociative('SELECT * FROM registered_channels'));

            return;
        }

        self::assertFalse($fail, 'The replacement must fail when insertion is rejected.');
        self::assertSame('AwaitingApproval', $result->outcome->name);
        $replacement = $channels->findByChannelName('#test');
        self::assertNotNull($replacement);
        self::assertTrue($replacement->isPendingRegistration());
        self::assertSame(8, $replacement->getFounderNickId());
    }

    private function transactionBoundary(): ChanTransactionBoundary
    {
        $boundary = $this->createStub(ChanTransactionBoundary::class);
        $boundary->method('transactional')->willReturnCallback(fn (callable $operation): mixed => $this->entityManager->wrapInTransaction($operation));

        return $boundary;
    }

    #[Test]
    public function requesterCleanupOrphansRejectionsButPreservesRestrictions(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $channels = new RegisteredChannelDoctrineRepository($this->entityManager);
        foreach ([['#finite', $at->modify('+24 hours')], ['#indefinite', null]] as [$name, $until]) {
            $request = RegisteredChannel::createPendingRegistration($at, $name, 7, 'description');
            $request->rejectRegistration('Reason', 9, 'Root', $at, $until);
            $channels->save($request);
        }
        $this->flushAndClear();

        $cleanup = $this->construct(CleanupDroppedNickDataHandler::class, ['channelRepository' => $channels]);
        $cleanup->handle(new CleanupDroppedNickData(7, $at));
        $this->flushAndClear();

        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT name, founder_nick_id, status, registration_rejection_reason, registration_restricted_until FROM registered_channels ORDER BY name');
        self::assertCount(2, $rows);
        self::assertSame(['#finite', '#indefinite'], array_column($rows, 'name'));
        $founderIds = array_map(static function (mixed $value): int {
            if (!is_numeric($value)) {
                throw new LogicException('Expected numeric founder identifiers.');
            }

            return (int) $value;
        }, array_column($rows, 'founder_nick_id'));
        self::assertSame([0, 0], $founderIds);
        self::assertSame(['rejected', 'rejected'], array_column($rows, 'status'));
        self::assertSame(['Reason', 'Reason'], array_column($rows, 'registration_rejection_reason'));
        self::assertNotNull($rows[0]['registration_restricted_until']);
        self::assertNull($rows[1]['registration_restricted_until']);

        $mode = $this->createStub(RegistrationModeQuery::class);
        $mode->method('current')->willReturn(RegistrationMode::Approval);
        $register = $this->construct(RegisterChannelHandler::class, [
            'channelRepository' => $channels,
            'activator' => $this->construct(ChannelRegistrationActivator::class),
            'registrationMode' => $mode,
        ]);
        $before = hash('sha256', serialize($this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels ORDER BY name')));
        foreach (['#finite', '#indefinite'] as $name) {
            $result = $register->handle(new RegisterChannel($name, 'description', 8, 'Other', true, false, true, true, $at));
            self::assertSame('RegistrationRestricted', $result->outcome->name);
        }
        $this->flushAndClear();
        $after = hash('sha256', serialize($this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels ORDER BY name')));
        self::assertSame($before, $after);
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
