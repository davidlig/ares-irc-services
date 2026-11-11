<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceNojoin;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\NojoinActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Policy\ChannelAccessPolicy;

use function strtolower;

final readonly class EnforceChannelNojoinHandler implements EnforceChannelNojoinHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelLevelRepositoryInterface $levels,
        private ChannelAccessRepositoryInterface $access,
        private ChanUserAccountPort $accounts,
        private ChannelEntryNetworkQuery $network,
        private NojoinActions $actions,
        private ChannelAccessPolicy $accessPolicy,
        private string $defaultLanguage = 'en',
    ) {}

    public function handle(EnforceChannelNojoin $command): int
    {
        $channel = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $channel || $channel->isBlocked()) {
            return 0;
        }

        $member = $this->network->findMember($command->memberUid);
        if (null === $member) {
            return 0;
        }

        return $this->handleKnownChannel($channel, [$member]);
    }

    public function handleKnownChannel(RegisteredChannel $channel, array $members): int
    {
        if ($channel->isBlocked()) {
            return 0;
        }

        $override = $this->levels->findByChannelAndKey($channel->getId(), ChannelLevel::KEY_NOJOIN);
        $requiredAccess = $override?->getValue() ?? ChannelLevel::getDefault(ChannelLevel::KEY_NOJOIN);

        return $this->enforceMembers(
            $channel,
            $members,
            $requiredAccess,
            fn (string $nickname): ?ChanAccountView => $this->accounts->findAccountByNick($nickname),
            fn (int $nickId): ?int => $this->access->findByChannelAndNick($channel->getId(), $nickId)?->getLevel(),
        );
    }

    public function handleKnownChannelWithSnapshot(RegisteredChannel $channel, array $members, int $requiredAccess, array $accounts, array $access): int
    {
        if ($channel->isBlocked()) {
            return 0;
        }

        return $this->enforceMembers(
            $channel,
            $members,
            $requiredAccess,
            static fn (string $nickname): ?ChanAccountView => $accounts[strtolower($nickname)] ?? null,
            static fn (int $nickId): ?int => $access[$nickId] ?? null,
        );
    }

    /**
     * @param list<ChannelEntryMember>           $members
     * @param callable(string): ?ChanAccountView $findAccount
     * @param callable(int): ?int                $findAccess
     */
    private function enforceMembers(RegisteredChannel $channel, array $members, int $requiredAccess, callable $findAccount, callable $findAccess): int
    {
        if (0 > $requiredAccess) {
            return 0;
        }

        $enforced = 0;
        foreach ($members as $member) {
            if ($member->operator || $member->service) {
                continue;
            }

            $account = $findAccount($member->nickname);
            $identified = $member->identified && null !== $account;
            $founder = $identified && $channel->isFounder($account->id);
            $storedAccess = null;
            if ($identified && !$founder) {
                $storedAccess = $findAccess($account->id);
            }

            $effectiveAccess = $this->accessPolicy->effectiveLevel($identified, $founder, $storedAccess);
            if ($effectiveAccess->meets($requiredAccess)) {
                continue;
            }

            $language = null === $account ? $this->defaultLanguage : $account->language;
            $this->actions->kick(
                $channel->getName(),
                $member->uid,
                $member->nickname,
                $effectiveAccess->value,
                $requiredAccess,
                $language,
            );
            ++$enforced;
        }

        return $enforced;
    }
}
