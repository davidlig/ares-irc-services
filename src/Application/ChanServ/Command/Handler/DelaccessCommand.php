<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Domain\ChanServ\Event\ChannelAccessChangedEvent;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;
use function strtolower;

/**
 * DELACCESS <#channel>.
 *
 * Allows an identified user to remove their own access entry from a channel.
 * Does not require any access level - just identification.
 * Founder cannot use this command (founder is not in access list).
 */
final readonly class DelaccessCommand implements ChanServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelAccessRepositoryInterface $accessRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function getName(): string
    {
        return 'DELACCESS';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'delaccess.syntax';
    }

    public function getHelpKey(): string
    {
        return 'delaccess.help';
    }

    public function getOrder(): int
    {
        return 9;
    }

    public function getShortDescKey(): string
    {
        return 'delaccess.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return 'IDENTIFIED';
    }

    public function allowsSuspendedChannel(): bool
    {
        return false;
    }

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return false;
    }

    public function execute(ChanServContext $context): void
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($channelName);
        }

        $senderAccount = $context->senderAccount;
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return;
        }

        if (!$context->isLevelFounder && $channel->isFounder($senderAccount->getId())) {
            $context->reply('delaccess.founder_not_in_access', ['%channel%' => $channelName]);

            return;
        }

        $existing = $this->accessRepository->findByChannelAndNick(
            $channel->getId(),
            $senderAccount->getId(),
        );

        if (null === $existing) {
            $context->reply('delaccess.not_in_list', ['%channel%' => $channelName]);

            return;
        }

        $this->accessRepository->remove($existing);

        $ip = $this->decodeIp($context->sender->ipBase64);
        $host = sprintf('%s@%s', $context->sender->ident, $context->sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();
        $nickname = $context->sender->nick;

        $this->eventDispatcher->dispatch(new ChannelAccessChangedEvent(
            channelId: $channel->getId(),
            channelName: $channelName,
            action: 'DEL',
            targetNickId: $senderAccount->getId(),
            targetNickname: $nickname,
            level: null,
            performedBy: $nickname,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $context->reply('delaccess.done', ['%channel%' => $channelName]);

        $channelNotice = $context->trans('delaccess.notice_channel', [
            '%nickname%' => $context->sender->nick,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $channelNotice);
    }

    private function decodeIp(string $ipBase64): string
    {
        if ('' === $ipBase64 || '*' === $ipBase64) {
            return '*';
        }

        $binary = base64_decode($ipBase64, true);

        if (false === $binary) {
            return $ipBase64;
        }

        $ip = inet_ntop($binary);

        return false !== $ip ? $ip : $ipBase64;
    }
}
