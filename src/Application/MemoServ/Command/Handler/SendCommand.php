<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\MemoServCommandInterface;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\MemoServSendThrottleRegistry;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\MemoServ\Entity\Memo;
use App\Domain\MemoServ\Exception\MemoDisabledException;
use App\Domain\MemoServ\Repository\MemoIgnoreRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_slice;
use function implode;
use function mb_strlen;
use function str_starts_with;
use function strtolower;

/**
 * SEND {nickname|#canal} <mensaje>.
 */
final readonly class SendCommand implements MemoServCommandInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private RegisteredChannelRepositoryInterface $channelRepository,
        private MemoRepositoryInterface $memoRepository,
        private MemoIgnoreRepositoryInterface $memoIgnoreRepository,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
        private MemoServSendThrottleRegistry $throttleRegistry,
        private ChanServAccessHelper $accessHelper,
        private NetworkUserLookupPort $userLookup,
        private TranslatorInterface $translator,
        private string $defaultLanguage,
        private int $maxMemosPerNick,
        private int $maxMemosPerChannel,
        private int $sendMinIntervalSeconds,
    ) {
    }

    public function getName(): string
    {
        return 'SEND';
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
        return 'send.syntax';
    }

    public function getHelpKey(): string
    {
        return 'send.help';
    }

    public function getOrder(): int
    {
        return 1;
    }

    public function getShortDescKey(): string
    {
        return 'send.short';
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

    public function execute(MemoServContext $context): void
    {
        $senderAccount = $context->senderAccount;
        if (null === $senderAccount || null === $context->sender) {
            $context->reply('error.not_identified');

            return;
        }

        $targetArg = $context->args[0] ?? '';
        $message = implode(' ', array_slice($context->args, 1));

        $errorKey = $this->validateSendParams($context, $message);
        if (null !== $errorKey) {
            return;
        }

        if (str_starts_with($targetArg, '#')) {
            $this->sendToChannel($context, $targetArg, $message, $senderAccount);
        } else {
            $this->sendToNick($context, $targetArg, $message, $senderAccount);
        }
    }

    private function validateSendParams(MemoServContext $context, string $message): ?string
    {
        if ('' === $message) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return 'syntax';
        }

        if (mb_strlen($message) > Memo::MESSAGE_MAX_LENGTH) {
            $context->reply('send.message_too_long', ['max' => Memo::MESSAGE_MAX_LENGTH]);

            return 'too_long';
        }

        $remaining = $this->throttleRegistry->getRemainingCooldownSeconds($context->sender->uid, $this->sendMinIntervalSeconds);
        $errorKey = $remaining > 0 ? 'throttled' : null;
        if ('throttled' === $errorKey) {
            $context->reply('send.throttled', ['seconds' => $remaining]);
        }

        return $errorKey;
    }

    private function sendToNick(MemoServContext $context, string $nickName, string $message, RegisteredNick $senderAccount): void
    {
        $recipient = $this->nickRepository->findByNick($nickName);
        if (null === $recipient) {
            $context->reply('send.nick_not_registered', ['nick' => $nickName]);

            return;
        }

        $errorKey = $this->validateSendToNickRecipient($context, $recipient, $senderAccount, $nickName);
        if (null !== $errorKey) {
            return;
        }

        $memo = new Memo($recipient->getId(), null, $senderAccount->getId(), $message);
        $this->memoRepository->save($memo);
        $this->throttleRegistry->recordSend($context->sender->uid);

        $context->reply('send.sent_nick', ['nick' => $recipient->getNickname()]);

        $this->notifyRecipientIfOnline($context, $recipient);
    }

    private function validateSendToNickRecipient(MemoServContext $context, RegisteredNick $recipient, RegisteredNick $senderAccount, string $nickName): ?string
    {
        if ($recipient->getId() === $senderAccount->getId()) {
            $context->reply('send.cannot_send_to_self');

            return 'self';
        }

        if (!$this->memoSettingsRepository->isEnabledForNick($recipient->getId())) {
            throw MemoDisabledException::forTarget($nickName);
        }

        $ignored = null !== $this->memoIgnoreRepository->findByTargetNickAndIgnored($recipient->getId(), $senderAccount->getId());
        $limitReached = $this->memoRepository->countByTargetNick($recipient->getId()) >= $this->maxMemosPerNick;
        $errorKey = null;

        if ($ignored) {
            $context->reply('send.ignored');
            $errorKey = 'ignored';
        } elseif ($limitReached) {
            $context->reply('send.limit_reached', ['target' => $nickName]);
            $errorKey = 'limit';
        }

        return $errorKey;
    }

    private function notifyRecipientIfOnline(MemoServContext $context, RegisteredNick $recipient): void
    {
        $recipientView = $this->userLookup->findByNick($recipient->getNickname());
        if (null === $recipientView) {
            return;
        }

        $unread = $this->memoRepository->countUnreadByTargetNick($recipient->getId());
        if (0 === $unread) {
            return;
        }

        $language = $recipient->getLanguage();
        $message = $this->translator->trans('notify.nick_pending', ['%count%' => $unread, '%bot%' => $context->getNotifier()->getNick()], 'memoserv', $language);
        $context->getNotifier()->sendNotice($recipientView->uid, $message);
    }

    private function sendToChannel(MemoServContext $context, string $channelName, string $message, RegisteredNick $senderAccount): void
    {
        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            $context->reply('send.channel_not_registered', ['channel' => $channelName]);

            return;
        }

        if (!$this->memoSettingsRepository->isEnabledForChannel($channel->getId())) {
            throw MemoDisabledException::forTarget($channelName);
        }

        $ignore = $this->memoIgnoreRepository->findByTargetChannelAndIgnored($channel->getId(), $senderAccount->getId());
        if (null !== $ignore) {
            $context->reply('send.ignored');

            return;
        }

        $count = $this->memoRepository->countByTargetChannel($channel->getId());
        if ($count >= $this->maxMemosPerChannel) {
            $context->reply('send.limit_reached', ['target' => $channelName]);

            return;
        }

        $memo = new Memo(null, $channel->getId(), $senderAccount->getId(), $message);
        $this->memoRepository->save($memo);
        $this->throttleRegistry->recordSend($context->sender->uid);

        $context->reply('send.sent_channel', ['channel' => $channel->getName()]);
    }
}
