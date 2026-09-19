<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ManageManualRank;

use App\ChanServ\Application\Model\ChannelMember;
use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Model\MemberRankChange;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelRankActions;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRank;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRankHandler;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRankOutcome;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRankResult;
use App\ChanServ\Application\UseCase\ManageManualRank\ManualRankOperation;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\ChanServ\Domain\ValueObject\RankChangeAction;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function count;

#[CoversClass(ManageManualRank::class)]
#[CoversClass(ManageManualRankHandler::class)]
#[CoversClass(ManageManualRankResult::class)]
#[CoversClass(ManualRankOperation::class)]
final class ManageManualRankHandlerTest extends TestCase
{
    #[Test]
    public function rejectsUnknownChannel(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);

        $this->expectException(ChannelNotRegisteredException::class);
        $this->handler($channels)->handle($this->command());
    }

    #[Test]
    public function reportsMissingActorBeforeNetworkLookup(): void
    {
        $network = $this->createMock(ChannelRankNetworkQuery::class);
        $network->expects(self::never())->method('findChannel');

        $result = $this->handler($this->channels(), $network)->handle($this->command(actorId: null));

        self::assertSame(ManageManualRankOutcome::NotIdentified, $result->outcome);
    }

    #[Test]
    public function reportsUnavailableRankWhenChannelIsMissingFromNetworkOrModeUnsupported(): void
    {
        $network = $this->createStub(ChannelRankNetworkQuery::class);
        $network->method('findChannel')->willReturnOnConsecutiveCalls(null, $this->state(supported: [ChannelRank::Voice]));
        $handler = $this->handler($this->channels(), $network);

        self::assertSame(ManageManualRankOutcome::RankNotSupported, $handler->handle($this->command())->outcome);
        self::assertSame(ManageManualRankOutcome::RankNotSupported, $handler->handle($this->command())->outcome);
    }

    #[Test]
    public function enforcesTheOperationLevelUnlessFounderOverrideIsActive(): void
    {
        $levels = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levels->method('findByChannelAndKey')->willReturn(new ChannelLevel(11, ChannelLevel::KEY_ADMINDEADMIN, 400));
        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access->method('findByChannelAndNick')->willReturn(new ChannelAccess(11, 7, 300));
        $handler = $this->handler($this->channels(), $this->network(), access: $access, levels: $levels);

        $this->expectException(InsufficientAccessException::class);
        $handler->handle($this->command(founderOverride: false));
    }

    #[Test]
    public function reportsMissingTargetAndUnregisteredGrantTarget(): void
    {
        $network = $this->createStub(ChannelRankNetworkQuery::class);
        $network->method('findChannel')->willReturnOnConsecutiveCalls(
            $this->state(members: []),
            $this->state(members: [$this->member(registeredNickId: null)]),
        );
        $handler = $this->handler($this->channels(), $network);

        self::assertSame(ManageManualRankOutcome::TargetNotOnChannel, $handler->handle($this->command())->outcome);
        self::assertSame(ManageManualRankOutcome::TargetNickNotRegistered, $handler->handle($this->command())->outcome);
    }

    #[Test]
    public function secureChannelRequiresTheAutomaticRankLevelForGrant(): void
    {
        $channel = $this->channel();
        $channel->configureSecure(true);
        $levels = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levels->method('findByChannelAndKey')->willReturnCallback(static fn (int $channelId, string $key): ChannelLevel => new ChannelLevel(
            $channelId,
            $key,
            ChannelLevel::KEY_AUTOADMIN === $key ? 400 : 200,
        ));
        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access->method('findByChannelAndNick')->willReturn(new ChannelAccess(11, 8, 200));

        $result = $this->handler($this->channels($channel), $this->network(), access: $access, levels: $levels)
            ->handle($this->command(founderOverride: false));

        self::assertSame(ManageManualRankOutcome::SecureLevelRequired, $result->outcome);
        self::assertSame(400, $result->requiredLevel);
    }

    #[Test]
    public function revokeCannotAffectAnEqualOrHigherTargetButMayAffectSelf(): void
    {
        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        $access->method('findByChannelAndNick')->willReturnCallback(static fn (int $channelId, int $nickId): ChannelAccess => new ChannelAccess($channelId, $nickId, 300));
        $levels = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levels->method('findByChannelAndKey')->willReturnCallback(static fn (int $channelId, string $key): ChannelLevel => new ChannelLevel($channelId, $key, 200));
        $handler = $this->handler($this->channels(), $this->network(), access: $access, levels: $levels);

        self::assertSame(
            ManageManualRankOutcome::TargetAccessTooHigh,
            $handler->handle($this->command(operation: ManualRankOperation::Deadmin, founderOverride: false))->outcome,
        );

        $selfNetwork = $this->network([$this->member(registeredNickId: 7)]);
        self::assertSame(
            ManageManualRankOutcome::Applied,
            $this->handler($this->channels(), $selfNetwork, access: $access, levels: $levels)
                ->handle($this->command(operation: ManualRankOperation::Deadmin, founderOverride: false))->outcome,
        );
    }

    #[Test]
    public function appliesTypedRankChangeForAllowedGrant(): void
    {
        $actions = $this->createMock(ChannelRankActions::class);
        $actions->expects(self::once())->method('apply')->with(
            '#Channel',
            self::callback(static function (array $changes): bool {
                if (1 !== count($changes) || !$changes[0] instanceof MemberRankChange) {
                    return false;
                }

                return 'UID2' === $changes[0]->uid
                    && ChannelRank::Administrator === $changes[0]->change->rank
                    && RankChangeAction::Grant === $changes[0]->change->action;
            }),
        );

        $result = $this->handler($this->channels(), $this->network(), $actions)
            ->handle($this->command());

        self::assertSame(ManageManualRankOutcome::Applied, $result->outcome);
        self::assertSame('#Channel', $result->channelName);
        self::assertSame('Target', $result->targetNickname);
        self::assertSame(ChannelRank::Administrator, ManualRankOperation::Admin->rank());
        self::assertSame(RankChangeAction::Revoke, ManualRankOperation::Deadmin->action());
        self::assertSame(ChannelLevel::KEY_HALFOPDEHALFOP, ManualRankOperation::Halfop->requiredLevelKey());
        self::assertSame(ChannelLevel::KEY_AUTOOP, ManualRankOperation::Op->automaticLevelKey());
        self::assertSame(ChannelRank::HalfOperator, ManualRankOperation::Dehalfop->rank());
        self::assertSame(ChannelRank::Operator, ManualRankOperation::Deop->rank());
        self::assertSame(ChannelRank::Voice, ManualRankOperation::Voice->rank());
        self::assertSame(ChannelRank::Voice, ManualRankOperation::Devoice->rank());
        self::assertSame(ChannelLevel::KEY_OPDEOP, ManualRankOperation::Deop->requiredLevelKey());
    }

    #[Test]
    public function revokeAllowsAnUnregisteredTargetWhenFounderOverridesAccessChecks(): void
    {
        $actions = $this->createMock(ChannelRankActions::class);
        $actions->expects(self::once())->method('apply');

        $result = $this->handler(
            $this->channels(),
            $this->network([$this->member(registeredNickId: null)]),
            $actions,
        )->handle($this->command(operation: ManualRankOperation::Devoice));

        self::assertSame(ManageManualRankOutcome::Applied, $result->outcome);
    }

    private function command(
        ?int $actorId = 7,
        bool $founderOverride = true,
        ManualRankOperation $operation = ManualRankOperation::Admin,
    ): ManageManualRank {
        return new ManageManualRank('#Channel', 'Target', $actorId, $founderOverride, $operation);
    }

    private function handler(
        RegisteredChannelRepositoryInterface $channels,
        ?ChannelRankNetworkQuery $network = null,
        ?ChannelRankActions $actions = null,
        ?ChannelAccessRepositoryInterface $access = null,
        ?ChannelLevelRepositoryInterface $levels = null,
    ): ManageManualRankHandler {
        return new ManageManualRankHandler(
            $channels,
            $network ?? $this->network(),
            $actions ?? $this->createStub(ChannelRankActions::class),
            new ChanServAccessHelper(
                $access ?? $this->createStub(ChannelAccessRepositoryInterface::class),
                $levels ?? $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
    }

    private function channels(?RegisteredChannel $channel = null): RegisteredChannelRepositoryInterface
    {
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($channel ?? $this->channel());

        return $repository;
    }

    /** @param list<ChannelMember>|null $members */
    private function network(?array $members = null): ChannelRankNetworkQuery
    {
        $network = $this->createStub(ChannelRankNetworkQuery::class);
        $network->method('findChannel')->willReturn($this->state($members));

        return $network;
    }

    /**
     * @param list<ChannelMember>|null $members
     * @param list<ChannelRank>|null   $supported
     */
    private function state(?array $members = null, ?array $supported = null): ChannelRankNetworkState
    {
        return new ChannelRankNetworkState(
            '#Channel',
            $members ?? [$this->member()],
            $supported ?? [ChannelRank::Owner, ChannelRank::Administrator, ChannelRank::HalfOperator, ChannelRank::Operator, ChannelRank::Voice],
        );
    }

    private function member(?int $registeredNickId = 8): ChannelMember
    {
        return new ChannelMember('UID2', 'Target', true, false, false, $registeredNickId, []);
    }

    private function channel(): RegisteredChannel
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#Channel', 1, 'Description');
        $id = new ReflectionProperty(RegisteredChannel::class, 'id');
        $id->setValue($channel, 11);

        return $channel;
    }
}
