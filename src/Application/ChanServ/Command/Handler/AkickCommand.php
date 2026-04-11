<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\Port\BurstCompletePort;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\ChannelAkick;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelAkickRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\ValueObject\UserMask;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateInterval;
use DateTimeImmutable;

use function array_slice;
use function count;
use function ctype_digit;
use function fnmatch;
use function preg_match;
use function sprintf;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * AKICK <#channel> ADD|DEL|LIST [mask] [reason] [expiry].
 *
 * ADD: add mask with optional reason and expiry (default permanent).
 * DEL: remove by mask or entry number.
 * LIST: show all AKICK entries.
 * Requires AKICK level (default 450).
 */
final readonly class AkickCommand implements ChanServCommandInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelAkickRepositoryInterface $akickRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private ChannelAccessRepositoryInterface $accessRepository,
        private ChanServAccessHelper $accessHelper,
        private ChannelLookupPort $channelLookup,
        private ?BurstCompletePort $burstCompletePort = null,
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

    public function allowsSuspendedChannel(): bool
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
                $context->reply('akick.unknown_sub', ['%sub%' => $sub]);
        }
    }

    private function applyAkickBansIfBurstComplete(ChanServContext $context, RegisteredChannel $channel, string $channelName): void
    {
        // Only apply bans if burst is complete and we have a burst complete port
        if (!$this->burstCompletePort || !$this->burstCompletePort->isComplete()) {
            return;
        }

        $view = $context->getChannelView($channelName);
        if (null === $view) {
            return;
        }

        // Get all AKICK entries for this channel
        $entries = $this->akickRepository->listByChannel($channel->getId());
        if ([] === $entries) {
            return;
        }

        // Apply bans for each AKICK entry
        foreach ($entries as $akick) {
            $this->applyAkickBanToChannelMembers($context, $akick, $view);
        }
    }

    private function applyAkickBanToChannelMembers(ChanServContext $context, ChannelAkick $akick, ChannelView $view): void
    {
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
            if ($akick->matches((string) $userMask)) {
                $notifier->setChannelModes($view->name, '+b', [$akick->getMask()]);
                $notifier->kickFromChannel($view->name, $uid, $reason);
            }
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
            $creatorName = $this->resolveCreatorName($akick->getCreatorNickId(), $context);
            $reason = $akick->getReason() ?? $context->trans('akick.list.no_reason');
            $expires = null !== $akick->getExpiresAt()
                ? $context->formatDate($akick->getExpiresAt())
                : $context->trans('akick.list.never_expires');

            $context->reply('akick.list.entry', [
                '%index%' => (string) $num,
                '%mask%' => sprintf("\x0304%s\x03", $akick->getMask()),
                '%reason%' => $reason,
                '%nickname%' => $creatorName,
                '%expiration%' => $expires,
            ]);
            ++$num;
        }

        // Apply bans for all AKICKs if burst is complete
        $this->applyAkickBansIfBurstComplete($context, $channel, $channelName);
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

        $protectedNick = $this->findProtectedUser($channel, $mask);
        if (null !== $protectedNick) {
            $context->reply('akick.protected_user', ['%nickname%' => $protectedNick]);

            return;
        }

        $count = $this->akickRepository->countByChannel($channel->getId());
        $existing = $this->akickRepository->findByChannelAndMask($channel->getId(), $mask);
        if (null === $existing && $count >= ChannelAkick::MAX_ENTRIES_PER_CHANNEL) {
            $context->reply('akick.max_entries', ['%max%' => (string) ChannelAkick::MAX_ENTRIES_PER_CHANNEL]);

            return;
        }

        if (null !== $existing) {
            if ($existing->isExpired()) {
                $this->akickRepository->remove($existing);
            } else {
                $context->reply('akick.add.already_exists', ['%mask%' => $mask]);

                return;
            }
        }

        $expiresAt = null;
        $reason = null;

        // Syntax: AKICK ADD <mask> [expiry] [reason...]
        // Valid expiry formats: 0 (permanent), \d+[dhm] (e.g., 7d, 12h, 30m)
        // If args[3] is provided but NOT a valid expiry, it's a syntax error
        if (count($context->args) >= 4) {
            $expiryStr = trim($context->args[3]);

            if ('' === $expiryStr) {
                // Empty string after mask - permanent AKICK, no reason
                $expiresAt = null;
            } elseif ('0' === strtolower($expiryStr)) {
                // Explicit permanent - args[4+] is the reason
                $expiresAt = null;
                if (count($context->args) >= 5) {
                    $reasonParts = array_slice($context->args, 4);
                    $reason = trim(implode(' ', $reasonParts));
                }
            } else {
                // Try to parse as expiry
                $expiresAt = $this->parseExpiry($expiryStr);
                if (null === $expiresAt) {
                    // Not a valid expiry format - syntax error
                    $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

                    return;
                }
                // Valid expiry - args[4+] is the reason
                if (count($context->args) >= 5) {
                    $reasonParts = array_slice($context->args, 4);
                    $reason = trim(implode(' ', $reasonParts));
                    if ('' === $reason) {
                        $reason = null;
                    }
                }
            }
        }

        $akick = ChannelAkick::create(
            $channel->getId(),
            $context->senderAccount->getId(),
            $mask,
            $reason,
            $expiresAt,
        );

        $this->akickRepository->save($akick);

        // Apply ban immediately if burst is complete and user is in channel
        if ($this->burstCompletePort && $this->burstCompletePort->isComplete()) {
            $view = $context->getChannelView($channelName);
            if (null !== $view) {
                $this->applyAkickBanToChannelMembers($context, $akick, $view);
            }
        }

        $context->reply('akick.add.done', ['%mask%' => $mask]);

        $noticeReason = null === $reason ? $context->trans('akick.list.no_reason') : $reason;
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

    private function buildUserMask(string $nick, string $ident, string $host): UserMask
    {
        return UserMask::fromParts($nick, $ident, $host);
    }

    /**
     * Check if the AKICK mask would match any protected user (founder, successor, or access list member).
     * Returns the nickname of the first protected user that matches, or null if none match.
     */
    private function findProtectedUser(RegisteredChannel $channel, string $mask): ?string
    {
        $exclamationPos = strpos($mask, '!');

        $nickPattern = substr($mask, 0, $exclamationPos);

        // If nick pattern is only wildcards (* or combinations like **), it's not targeting any specific nick
        // so we should not block it (host-based bans like *!*@*.isp.com should be allowed)
        $strippedPattern = str_replace('*', '', $nickPattern);
        if ('' === $strippedPattern) {
            return null;
        }

        $protectedNicks = $this->getProtectedNicks($channel);

        foreach ($protectedNicks as $protectedNick) {
            $testMask = strtolower($protectedNick . '!*@*');
            $pattern = strtolower($nickPattern . '!*@*');

            if (fnmatch($pattern, $testMask)) {
                return $protectedNick;
            }
        }

        return null;
    }

    /**
     * @return string[] List of protected nicknames (founder, successor, and access list members)
     */
    private function getProtectedNicks(RegisteredChannel $channel): array
    {
        $nicks = [];

        // Founder
        $founder = $this->nickRepository->findById($channel->getFounderNickId());
        if (null !== $founder) {
            $nicks[] = $founder->getNickname();
        }

        // Successor
        $successorId = $channel->getSuccessorNickId();
        if (null !== $successorId) {
            $successor = $this->nickRepository->findById($successorId);
            if (null !== $successor) {
                $nicks[] = $successor->getNickname();
            }
        }

        // Access list
        $accessList = $this->accessRepository->listByChannel($channel->getId());
        foreach ($accessList as $access) {
            $nick = $this->nickRepository->findById($access->getNickId());
            if (null !== $nick) {
                $nicks[] = $nick->getNickname();
            }
        }

        return $nicks;
    }

    /**
     * Resolve creator nickname for AKICK display.
     * Returns the nickname if found, or 'unknown' translation if creator was dropped.
     */
    private function resolveCreatorName(?int $creatorNickId, ChanServContext $context): string
    {
        if (null === $creatorNickId) {
            return $context->trans('akick.list.unknown_creator');
        }

        $creator = $this->nickRepository->findById($creatorNickId);

        return null !== $creator ? $creator->getNickname() : $context->trans('akick.list.unknown_creator');
    }
}
