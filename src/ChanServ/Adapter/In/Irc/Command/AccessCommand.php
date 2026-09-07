<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelAccessChangedEvent;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\Irc\Application\Port\In\SenderView;

use function sprintf;
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
        private ChanUserAccountPort $accountPort,
        private ChanServAccessHelper $accessHelper,
        private EventBusInterface $eventDispatcher,
    ) {}

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

    public function getRequiredPermission(): string
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
        return true;
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

        $sender = $context->sender;
        $senderAccount = $context->senderAccount;
        if (null === $sender || null === $senderAccount) {
            $context->reply('error.not_identified');

            return;
        }

        $sub = strtoupper($context->args[1] ?? '');
        switch ($sub) {
            case 'LIST':
                $this->doList($context, $channel, $channelName, $senderAccount);
                break;
            case 'ADD':
                $this->doAdd($context, $channel, $channelName, $sender, $senderAccount);
                break;
            case 'DEL':
                $this->doDel($context, $channel, $channelName, $sender, $senderAccount);
                break;
            default:
                $context->reply('access.unknown_sub', ['%sub%' => $sub]);
        }
    }

    private function doList(ChanServContext $context, RegisteredChannel $channel, string $channelName, ChanAccountView $senderAccount): void
    {
        if (!$context->isLevelFounder) {
            $this->accessHelper->requireLevel($channel, (int) $senderAccount->id, ChannelLevel::KEY_ACCESSLIST, $channelName, 'ACCESS LIST');
        }

        $entries = $this->accessRepository->listByChannel($channel->getId());

        if ([] === $entries) {
            $context->reply('access.list.empty', ['%channel%' => $channelName]);

            return;
        }

        $context->reply('access.list.header', ['%channel%' => $channelName]);

        $num = 1;
        foreach ($entries as $access) {
            $nick = $this->accountPort->findAccountById($access->getNickId());
            $nickName = null !== $nick ? $nick->nickname : (string) $access->getNickId();
            $context->reply('access.list.entry', [
                '%index%' => (string) $num,
                '%nickname%' => $nickName,
                '%level%' => (string) $access->getLevel(),
            ]);
            ++$num;
        }
    }

    private function doAdd(ChanServContext $context, RegisteredChannel $channel, string $channelName, SenderView $sender, ChanAccountView $senderAccount): void
    {
        if (!$context->isLevelFounder) {
            $this->accessHelper->requireLevel($channel, (int) $senderAccount->id, ChannelLevel::KEY_ACCESSCHANGE, $channelName, 'ACCESS ADD');
        }

        $data = $this->validateAddArgs($context);
        if (null === $data) {
            return;
        }

        $targetAccount = $this->accountPort->findAccountByNick($data['nickname']);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nickname%' => $data['nickname']]);

            return;
        }

        if (!$this->ensureCanAddAccess($context, $channel, $channelName, $data['nickname'], $data['level'], $targetAccount, $senderAccount)) {
            return;
        }

        $this->performAddAccess($channel, $channelName, $data['nickname'], $data['level'], $targetAccount, $context, $sender, $senderAccount);
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
        ChanAccountView $targetAccount,
        ChanAccountView $senderAccount,
    ): bool {
        if ($context->isLevelFounder) {
            return $this->ensureCanAddAccessAsFounder($context, $channel, $targetAccount);
        }

        return $this->ensureCanAddAccessAsMember($context, $channel, $level, $targetAccount, $senderAccount);
    }

    private function ensureCanAddAccessAsFounder(
        ChanServContext $context,
        RegisteredChannel $channel,
        ChanAccountView $targetAccount,
    ): bool {
        if ($channel->isFounder((int) $targetAccount->id)) {
            $context->reply('access.founder_not_in_list');

            return false;
        }

        return $this->ensureCanAddAccessMaxEntriesFounder($context, $channel, $targetAccount);
    }

    private function ensureCanAddAccessMaxEntriesFounder(
        ChanServContext $context,
        RegisteredChannel $channel,
        ChanAccountView $targetAccount,
    ): bool {
        $count = $this->accessRepository->countByChannel((int) $channel->getId());
        $existing = $this->accessRepository->findByChannelAndNick((int) $channel->getId(), (int) $targetAccount->id);
        if (null === $existing && $count >= ChannelAccess::MAX_ENTRIES_PER_CHANNEL) {
            $context->reply('access.max_entries', ['%max%' => (string) ChannelAccess::MAX_ENTRIES_PER_CHANNEL]);

            return false;
        }

        return true;
    }

    private function ensureCanAddAccessAsMember(
        ChanServContext $context,
        RegisteredChannel $channel,
        int $level,
        ChanAccountView $targetAccount,
        ChanAccountView $senderAccount,
    ): bool {
        $senderLevel = $this->accessHelper->effectiveAccessLevel($channel, (int) $senderAccount->id, true);
        if ($level >= $senderLevel) {
            $context->reply('access.cannot_manage_level');

            return false;
        }

        if ($channel->isFounder((int) $targetAccount->id)) {
            $context->reply('access.founder_not_in_list');

            return false;
        }

        return $this->ensureCanAddAccessMaxEntries($context, $channel, $targetAccount, $senderAccount);
    }

    private function ensureCanAddAccessMaxEntries(
        ChanServContext $context,
        RegisteredChannel $channel,
        ChanAccountView $targetAccount,
        ChanAccountView $senderAccount,
    ): bool {
        $count = $this->accessRepository->countByChannel((int) $channel->getId());
        $existing = $this->accessRepository->findByChannelAndNick((int) $channel->getId(), (int) $targetAccount->id);
        if (null === $existing && $count >= ChannelAccess::MAX_ENTRIES_PER_CHANNEL) {
            $context->reply('access.max_entries', ['%max%' => (string) ChannelAccess::MAX_ENTRIES_PER_CHANNEL]);

            return false;
        }

        if (null !== $existing && !$this->accessHelper->canManageLevel($channel, (int) $senderAccount->id, $existing->getLevel())) {
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
        ChanAccountView $targetAccount,
        ChanServContext $context,
        SenderView $sender,
        ChanAccountView $senderAccount,
    ): void {
        $existing = $this->accessRepository->findByChannelAndNick((int) $channel->getId(), (int) $targetAccount->id);
        if (null !== $existing) {
            $existing->updateLevel($level);
            $this->accessRepository->save($existing);
        } else {
            $access = new ChannelAccess((int) $channel->getId(), (int) $targetAccount->id, $level);
            $this->accessRepository->save($access);
        }

        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);
        $performedByNickId = $senderAccount->id;

        $this->eventDispatcher->dispatch(new ChannelAccessChangedEvent(
            channelId: (int) $channel->getId(),
            channelName: $channelName,
            action: 'ADD',
            targetNickId: (int) $targetAccount->id,
            targetNickname: $nickname,
            level: $level,
            performedBy: $sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $context->reply('access.add.done', ['%nickname%' => $nickname, '%level%' => (string) $level]);
        $channelNotice = $context->trans('access.add.notice_channel', [
            '%from%' => $sender->nick,
            '%to%' => $nickname,
            '%level%' => (string) $level,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $channelNotice);
    }

    private function doDel(ChanServContext $context, RegisteredChannel $channel, string $channelName, SenderView $sender, ChanAccountView $senderAccount): void
    {
        if (!$context->isLevelFounder) {
            $this->accessHelper->requireLevel($channel, (int) $senderAccount->id, ChannelLevel::KEY_ACCESSCHANGE, $channelName, 'ACCESS DEL');
        }

        $validationResult = $this->validateDelAccess($context, $channel, $senderAccount);
        if (null === $validationResult) {
            return;
        }

        [$nickname, $targetAccount, $existing] = $validationResult;
        $this->performDelAccess($context, $channel, $channelName, $nickname, $targetAccount, $existing, $sender, $senderAccount);
    }

    /** @return array{string, ChanAccountView, ChannelAccess}|null */
    private function validateDelAccess(ChanServContext $context, RegisteredChannel $channel, ChanAccountView $senderAccount): ?array
    {
        $nickname = trim($context->args[2] ?? '');
        if ('' === $nickname) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return null;
        }

        $targetAccount = $this->accountPort->findAccountByNick($nickname);
        if (null === $targetAccount) {
            $context->reply('error.nick_not_registered', ['%nickname%' => $nickname]);

            return null;
        }

        return $this->validateDelAccessExisting($context, $channel, $nickname, $targetAccount, $senderAccount);
    }

    /** @return array{string, ChanAccountView, ChannelAccess}|null */
    private function validateDelAccessExisting(
        ChanServContext $context,
        RegisteredChannel $channel,
        string $nickname,
        ChanAccountView $targetAccount,
        ChanAccountView $senderAccount,
    ): ?array {
        $existing = $this->accessRepository->findByChannelAndNick((int) $channel->getId(), (int) $targetAccount->id);
        if (null === $existing) {
            $context->reply('access.del.not_in_list', ['%nickname%' => $nickname]);

            return null;
        }

        if (!$context->isLevelFounder && !$this->accessHelper->canManageLevel($channel, (int) $senderAccount->id, $existing->getLevel())) {
            $context->reply('access.cannot_manage_level');

            return null;
        }

        return [$nickname, $targetAccount, $existing];
    }

    private function performDelAccess(
        ChanServContext $context,
        RegisteredChannel $channel,
        string $channelName,
        string $nickname,
        ChanAccountView $targetAccount,
        ChannelAccess $existing,
        SenderView $sender,
        ChanAccountView $senderAccount,
    ): void {
        $this->accessRepository->remove($existing);

        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);
        $performedByNickId = $senderAccount->id;

        $this->eventDispatcher->dispatch(new ChannelAccessChangedEvent(
            channelId: (int) $channel->getId(),
            channelName: $channelName,
            action: 'DEL',
            targetNickId: (int) $targetAccount->id,
            targetNickname: $nickname,
            level: null,
            performedBy: $sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $context->reply('access.del.done', ['%nickname%' => $nickname]);
        $channelNotice = $context->trans('access.del.notice_channel', [
            '%from%' => $sender->nick,
            '%to%' => $nickname,
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
