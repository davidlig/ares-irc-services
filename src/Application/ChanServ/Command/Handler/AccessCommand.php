<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

use function strtoupper;

/**
 * ACCESS <#channel> ADD|DEL|LIST [nickname] [level].
 *
 * LIST: requires ACCESSLIST level. ADD/DEL: require ACCESSCHANGE; level 1-499;
 * max 100 entries; founder not in list; user can only manage nicks with level < own.
 */
final readonly class AccessCommand implements ChanServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelAccessRepositoryInterface $accessRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private ChanServAccessHelper $accessHelper,
    ) {
    }

    public function getName(): string
    {
        return 'ACCESS';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'access.syntax';
    }

    public function getHelpKey(): string
    {
        return 'access.help';
    }

    public function getOrder(): int
    {
        return 8;
    }

    public function getShortDescKey(): string
    {
        return 'access.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'LIST', 'desc_key' => 'access.list.short', 'help_key' => 'access.list.help', 'syntax_key' => 'access.list.syntax'],
            ['name' => 'ADD', 'desc_key' => 'access.add.short', 'help_key' => 'access.add.help', 'syntax_key' => 'access.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'access.del.short', 'help_key' => 'access.del.help', 'syntax_key' => 'access.del.syntax'],
        ];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return 'IDENTIFIED';
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

        $sub = strtoupper($context->args[1] ?? '');
        switch ($sub) {
            case 'LIST':
                $this->doList($context, $channel, $channelName);
                break;
            case 'ADD':
                $this->doAdd($context, $channel, $channelName);
                break;
            case 'DEL':
                $this->doDel($context, $channel, $channelName);
                break;
            default:
                $context->reply('access.unknown_sub', ['%sub%' => $sub]);
        }
    }

    private function doList(ChanServContext $context, RegisteredChannel $channel, string $channelName): void
    {
        $this->accessHelper->requireLevel($channel, $context->senderAccount->getId(), ChannelLevel::KEY_ACCESSLIST, $channelName, 'ACCESS LIST');

        $entries = $this->accessRepository->listByChannel($channel->getId());

        if ([] === $entries) {
            $context->reply('access.list.empty', ['%channel%' => $channelName]);

            return;
        }

        $context->reply('access.list.header', ['%channel%' => $channelName]);

        $num = 1;
        foreach ($entries as $access) {
            $nick = $this->nickRepository->findById($access->getNickId());
            $nickName = null !== $nick ? $nick->getNickname() : (string) $access->getNickId();
            $context->reply('access.list.entry', [
                '%index%' => (string) $num,
                '%nick%' => $nickName,
                '%level%' => (string) $access->getLevel(),
            ]);
            ++$num;
        }
    }

    private function doAdd(ChanServContext $context, RegisteredChannel $channel, string $channelName): void
    {
        $this->accessHelper->requireLevel($channel, $context->senderAccount->getId(), ChannelLevel::KEY_ACCESSCHANGE, $channelName, 'ACCESS ADD');

        $data = $this->validateAddArgs($context);
        if (null === $data) {
            return;
        }

        $targetAccount = $this->nickRepository->findByNick($data['nickname']);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nick%' => $data['nickname']]);

            return;
        }

        if (!$this->ensureCanAddAccess($context, $channel, $channelName, $data['nickname'], $data['level'], $targetAccount)) {
            return;
        }

        $this->performAddAccess($channel, $channelName, $data['nickname'], $data['level'], $targetAccount, $context);
    }

    /** @return array{nickname: string, level: int}|null */
    private function validateAddArgs(ChanServContext $context): ?array
    {
        $nickname = trim($context->args[2] ?? '');
        $levelStr = trim($context->args[3] ?? '');
        if ('' === $nickname || '' === $levelStr) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return null;
        }

        $level = (int) $levelStr;
        if ($level < ChannelAccess::LEVEL_MIN || $level > ChannelAccess::LEVEL_MAX) {
            $context->reply('access.level_range', [
                '%min%' => (string) ChannelAccess::LEVEL_MIN,
                '%max%' => (string) ChannelAccess::LEVEL_MAX,
            ]);

            return null;
        }

        return ['nickname' => $nickname, 'level' => $level];
    }

    private function ensureCanAddAccess(
        ChanServContext $context,
        RegisteredChannel $channel,
        string $channelName,
        string $nickname,
        int $level,
        RegisteredNick $targetAccount,
    ): bool {
        $senderLevel = $this->accessHelper->effectiveAccessLevel($channel, $context->senderAccount->getId());
        if ($level >= $senderLevel) {
            $context->reply('access.cannot_manage_level');

            return false;
        }

        if ($channel->isFounder($targetAccount->getId())) {
            $context->reply('access.founder_not_in_list');

            return false;
        }

        $count = $this->accessRepository->countByChannel($channel->getId());
        $existing = $this->accessRepository->findByChannelAndNick($channel->getId(), $targetAccount->getId());
        if (null === $existing && $count >= ChannelAccess::MAX_ENTRIES_PER_CHANNEL) {
            $context->reply('access.max_entries', ['%max%' => (string) ChannelAccess::MAX_ENTRIES_PER_CHANNEL]);

            return false;
        }

        if (null !== $existing && !$this->accessHelper->canManageLevel($channel, $context->senderAccount->getId(), $existing->getLevel())) {
            $context->reply('access.cannot_manage_level');

            return false;
        }

        return true;
    }

    private function performAddAccess(
        RegisteredChannel $channel,
        string $channelName,
        string $nickname,
        int $level,
        RegisteredNick $targetAccount,
        ChanServContext $context,
    ): void {
        $existing = $this->accessRepository->findByChannelAndNick($channel->getId(), $targetAccount->getId());
        if (null !== $existing) {
            $existing->updateLevel($level);
            $this->accessRepository->save($existing);
        } else {
            $access = new ChannelAccess($channel->getId(), $targetAccount->getId(), $level);
            $this->accessRepository->save($access);
        }

        $context->reply('access.add.done', ['%nick%' => $nickname, '%level%' => (string) $level]);
        $channelNotice = $context->trans('access.add.notice_channel', [
            '%from%' => $context->sender->nick,
            '%to%' => $nickname,
            '%level%' => (string) $level,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $channelNotice);
    }

    private function doDel(ChanServContext $context, RegisteredChannel $channel, string $channelName): void
    {
        $this->accessHelper->requireLevel($channel, $context->senderAccount->getId(), ChannelLevel::KEY_ACCESSCHANGE, $channelName, 'ACCESS DEL');

        $nickname = trim($context->args[2] ?? '');
        if ('' === $nickname) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $targetAccount = $this->nickRepository->findByNick($nickname);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nick%' => $nickname]);

            return;
        }

        $existing = $this->accessRepository->findByChannelAndNick($channel->getId(), $targetAccount->getId());
        if (null === $existing) {
            $context->reply('access.del.not_in_list', ['%nick%' => $nickname]);

            return;
        }

        if (!$this->accessHelper->canManageLevel($channel, $context->senderAccount->getId(), $existing->getLevel())) {
            $context->reply('access.cannot_manage_level');

            return;
        }

        $this->accessRepository->remove($existing);
        $context->reply('access.del.done', ['%nick%' => $nickname]);
        $channelNotice = $context->trans('access.del.notice_channel', [
            '%from%' => $context->sender->nick,
            '%to%' => $nickname,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $channelNotice);
    }
}
