<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Adapter\Out\Persistence\Doctrine\DoctrineChannelRegistrationAuditRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Application\Port\Out\RegistrationModeQuery;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\ChanServ\Application\UseCase\CleanupDroppedNick\CleanupDroppedNickData;
use App\ChanServ\Application\UseCase\CleanupDroppedNick\CleanupDroppedNickDataHandler;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannel;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandler;
use App\ChanServ\Domain\Entity\ChannelRegistrationAudit;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionNamedType;

#[CoversClass(CleanupDroppedNickDataHandler::class)]
#[CoversClass(RegisteredChannelDoctrineRepository::class)]
#[CoversClass(RegisterChannelHandler::class)]
final class ApprovalRestrictionCleanupTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function requesterCleanupOrphansRejectionsButPreservesRestrictionsAndAudit(): void
    {
        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $channels = new RegisteredChannelDoctrineRepository($this->entityManager);
        foreach ([['#finite', $at->modify('+24 hours')], ['#indefinite', null]] as [$name, $until]) {
            $request = RegisteredChannel::createPendingRegistration($at, $name, 7, 'description');
            $request->rejectRegistration('Reason', 9, 'Root', $at, $until);
            $channels->save($request);
            new DoctrineChannelRegistrationAuditRepository($this->entityManager)->save(
                ChannelRegistrationAudit::recordDecision($name, 7, 9, 'Root', false, 'Reason', $until, $at),
            );
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
        self::assertSame(2, $this->tableCount('channel_registration_audit'));

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
        self::assertSame(2, $this->tableCount('channel_registration_audit'));
    }

    private function tableCount(string $table): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
        if (!is_numeric($count)) {
            throw new LogicException('Expected a numeric table count.');
        }

        return (int) $count;
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
