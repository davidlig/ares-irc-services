<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\ChannelAkick;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Repository\ChannelAkickRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateInterval;
use DateTimeImmutable;

use function count;
use function ctype_digit;
use function preg_match;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * AKICK <#channel> ADD|DEL|LIST|ENFORCE [mask] [reason] [expiry].
 *
 * ADD: add mask with optional reason and expiry (default permanent).
 * DEL: remove by mask or entry number.
 * LIST: show all AKICK entries.
 * ENFORCE: kick all users currently in channel matching the mask.
 * Requires AKICK level (default 450).
 */
final readonly class AkickCommand implements ChanServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelAkickRepositoryInterface $akickRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private ChanServAccessHelper $accessHelper,
        private ChannelLookupPort $channelLookup,
    ) {
    }

    public function getName(): string
    {
        return 'AKICK';
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
        return 'akick.syntax';
    }

    public function getHelpKey(): string
    {
        return 'akick.help';
    }

    public function getOrder(): int
    {
        return 9;
    }

    public function getShortDescKey(): string
    {
        return 'akick.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'akick.add.short', 'help_key' => 'akick.add.help', 'syntax_key' => 'akick.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'akick.del.short', 'help_key' => 'akick.del.help', 'syntax_key' => 'akick.del.syntax'],
            ['name' => 'LIST', 'desc_key' => 'akick.list.short', 'help_key' => 'akick.list.help', 'syntax_key' => 'akick.list.syntax'],
            ['name' => 'ENFORCE', 'desc_key' => 'akick.enforce.short', 'help_key' => 'akick.enforce.help', 'syntax_key' => 'akick.enforce.syntax'],
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
            case 'ENFORCE':
                $this->doEnforce($context, $channel, $channelName);
                break;
            default:
                $context->reply('akick.unknown_sub', ['%sub%' => $sub]);
        }
    }

    private function doList(ChanServContext $context, RegisteredChannel $channel, string $channelName): void
    {
        $this->accessHelper->requireLevel($channel, $context->senderAccount->getId(), ChannelLevel::KEY_AKICK, $channelName, 'AKICK LIST');

        $entries = $this->akickRepository->listByChannel($channel->getId());

        if ([] === $entries) {
            $context->reply('akick.list.empty', ['%channel%' => $channelName]);

            return;
        }

        $context->reply('akick.list.header', ['%channel%' => $channelName]);

        $num = 1;
        foreach ($entries as $akick) {
            $creator = $this->nickRepository->findById($akick->getCreatorNickId());
            $creatorName = null !== $creator ? $creator->getNickname() : (string) $akick->getCreatorNickId();
            $reason = $akick->getReason() ?? $context->trans('akick.list.no_reason');
            $expires = null !== $akick->getExpiresAt()
                ? $context->formatDate($akick->getExpiresAt())
                : $context->trans('akick.list.never_expires');

            $context->reply('akick.list.entry', [
                '%index%' => (string) $num,
                '%mask%' => $akick->getMask(),
                '%reason%' => $reason,
                '%nick%' => $creatorName,
                '%expiration%' => $expires,
            ]);
            ++$num;
        }
    }

    private function doAdd(ChanServContext $context, RegisteredChannel $channel, string $channelName): void
    {
        $this->accessHelper->requireLevel($channel, $context->senderAccount->getId(), ChannelLevel::KEY_AKICK, $channelName, 'AKICK ADD');

        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $mask = trim($context->args[2] ?? '');
        if ('' === $mask) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        if (!ChannelAkick::isValidMask($mask)) {
            $context->reply('akick.invalid_mask');

            return;
        }

        if (!ChannelAkick::isSafeMask($mask)) {
            $context->reply('akick.dangerous_mask', ['%mask%' => $mask]);

            return;
        }

        $count = $this->akickRepository->countByChannel($channel->getId());
        $existing = $this->akickRepository->findByChannelAndMask($channel->getId(), $mask);
        if (null === $existing && $count >= ChannelAkick::MAX_ENTRIES_PER_CHANNEL) {
            $context->reply('akick.max_entries', ['%max%' => (string) ChannelAkick::MAX_ENTRIES_PER_CHANNEL]);

            return;
        }

        if (null !== $existing) {
            $context->reply('akick.add.already_exists', ['%mask%' => $mask]);

            return;
        }

        $reason = null;
        $expiresAt = null;

        if (count($context->args) >= 4) {
            $reason = trim($context->args[3]);
            if ('' === $reason) {
                $reason = null;
            }
        }

        if (count($context->args) >= 5) {
            $expiryStr = trim($context->args[4]);
            $expiresAt = $this->parseExpiry($expiryStr);
        }

        $akick = ChannelAkick::create(
            $channel->getId(),
            $context->senderAccount->getId(),
            $mask,
            $reason,
            $expiresAt,
        );

        $this->akickRepository->save($akick);

        $this->setChannelBan($context->getNotifier(), $channelName, $mask);

        $this->enforceOnCurrentMembers($context, $channelName, $mask, $akick);

        $context->reply('akick.add.done', ['%mask%' => $mask]);

        $noticeReason = $reason ?? 'No reason';
        $channelNotice = $context->trans('akick.add.notice_channel', [
            '%from%' => $context->sender->nick,
            '%mask%' => $mask,
            '%reason%' => $noticeReason,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $channelNotice);
    }

    private function doDel(ChanServContext $context, RegisteredChannel $channel, string $channelName): void
    {
        $this->accessHelper->requireLevel($channel, $context->senderAccount->getId(), ChannelLevel::KEY_AKICK, $channelName, 'AKICK DEL');

        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $item = trim($context->args[2] ?? '');
        if ('' === $item) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $akick = $this->findAkickByItem($channel->getId(), $item);

        if (null === $akick) {
            $context->reply('akick.del.not_found', ['%mask%' => $item]);

            return;
        }

        $mask = $akick->getMask();
        $this->akickRepository->remove($akick);

        $context->reply('akick.del.done', ['%mask%' => $mask]);

        $channelNotice = $context->trans('akick.del.notice_channel', [
            '%from%' => $context->sender->nick,
            '%mask%' => $mask,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channelName, $channelNotice);
    }

    private function doEnforce(ChanServContext $context, RegisteredChannel $channel, string $channelName): void
    {
        $this->accessHelper->requireLevel($channel, $context->senderAccount->getId(), ChannelLevel::KEY_AKICK, $channelName, 'AKICK ENFORCE');

        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $item = trim($context->args[2] ?? '');
        if ('' === $item) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $akick = $this->findAkickByItem($channel->getId(), $item);

        if (null === $akick) {
            $context->reply('akick.enforce.not_found', ['%mask%' => $item]);

            return;
        }

        if ($akick->isExpired()) {
            $this->akickRepository->remove($akick);
            $context->reply('akick.enforce.expired', ['%mask%' => $item]);

            return;
        }

        $view = $context->getChannelView($channelName);
        if (null === $view) {
            $context->reply('error.channel_not_registered');

            return;
        }

        $kickedCount = $this->kickMatchingMembers($context, $view, $akick);

        if (0 === $kickedCount) {
            $context->reply('akick.enforce.no_match', ['%mask%' => $akick->getMask()]);
        } else {
            $context->reply('akick.enforce.done', [
                '%count%' => (string) $kickedCount,
                '%mask%' => $akick->getMask(),
            ]);
        }
    }

    private function findAkickByItem(int $channelId, string $item): ?ChannelAkick
    {
        if (ctype_digit($item)) {
            $num = (int) $item;
            if ($num < 1) {
                return null;
            }
            $entries = $this->akickRepository->listByChannel($channelId);
            $idx = $num - 1;

            return $entries[$idx] ?? null;
        }

        return $this->akickRepository->findByChannelAndMask($channelId, $item);
    }

    private function parseExpiry(string $expiryStr): ?DateTimeImmutable
    {
        $expiryStr = strtolower(trim($expiryStr));
        if ('' === $expiryStr || 'never' === $expiryStr || '0' === $expiryStr) {
            return null;
        }

        $matches = [];
        if (!preg_match('/^(\d+)([dhm])$/', $expiryStr, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        $unit = $matches[2];

        $intervalSpec = match ($unit) {
            'd' => "P{$value}D",
            'h' => "PT{$value}H",
            'm' => "PT{$value}M",
        };

        return (new DateTimeImmutable())->add(new DateInterval($intervalSpec));
    }

    private function setChannelBan(ChanServNotifierInterface $notifier, string $channelName, string $mask): void
    {
        $notifier->setChannelModes($channelName, '+b', [$mask]);
    }

    private function enforceOnCurrentMembers(ChanServContext $context, string $channelName, string $mask, ChannelAkick $akick): void
    {
        $view = $context->getChannelView($channelName);
        if (null === $view) {
            return;
        }

        $this->kickMatchingMembers($context, $view, $akick);
    }

    private function kickMatchingMembers(ChanServContext $context, ChannelView $view, ChannelAkick $akick): int
    {
        $kicked = 0;
        $notifier = $context->getNotifier();
        $userLookup = $context->getUserLookup();
        $reason = $akick->getReason() ?? 'AKICK: ' . $akick->getMask();

        foreach ($view->members as $member) {
            $uid = $member['uid'];
            $user = $userLookup->findByUid($uid);
            if (null === $user) {
                continue;
            }

            $userMask = $this->buildUserMask($user->nick, $user->ident, $user->hostname);
            if ($akick->matches($userMask)) {
                $notifier->kickFromChannel($view->name, $uid, $reason);
                ++$kicked;
            }
        }

        return $kicked;
    }

    private function buildUserMask(string $nick, string $ident, string $host): string
    {
        return $nick . '!' . $ident . '@' . $host;
    }
}
