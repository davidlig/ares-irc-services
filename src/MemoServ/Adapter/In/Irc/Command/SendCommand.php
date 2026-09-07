<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\Send\SendMemo;
use App\MemoServ\Application\UseCase\Send\SendMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Send\SendMemoOutcome;
use App\MemoServ\Domain\Entity\Memo;

use function array_slice;
use function implode;
use function mb_strlen;

/**
 * SEND {nickname|#canal} <mensaje>.
 */
final readonly class SendCommand implements MemoServCommandInterface
{
    public function __construct(
        private SendMemoHandlerInterface $sendMemoHandler,
        private NetworkUserLookupPort $userLookup,
        private TranslationInterface $translator,
    ) {}

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

    public function getRequiredPermission(): string
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

        if ('' === $message) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        if (mb_strlen($message) > Memo::MESSAGE_MAX_LENGTH) {
            $context->reply('send.message_too_long', ['max' => Memo::MESSAGE_MAX_LENGTH]);

            return;
        }

        $result = $this->sendMemoHandler->handle(new SendMemo(
            senderNickId: $senderAccount->id,
            senderUid: $context->sender->uid,
            target: $targetArg,
            message: $message,
        ));

        switch ($result->outcome) {
            case SendMemoOutcome::SentToNick:
                $targetName = $result->targetName ?? $targetArg;
                $context->reply('send.sent_nick', ['nick' => $targetName]);
                if (null !== $result->unreadCount && $result->unreadCount > 0) {
                    $recipientView = $this->userLookup->findByNick($targetName);
                    if (null !== $recipientView) {
                        $notice = $this->translator->trans(
                            'notify.nick_pending',
                            ['%count%' => $result->unreadCount, '%bot%' => $context->getNotifier()->getNick()],
                            'memoserv',
                            $result->recipientLanguage ?? 'en',
                        );
                        $context->getNotifier()->sendNotice($recipientView->uid, $notice);
                    }
                }
                break;

            case SendMemoOutcome::SentToChannel:
                $context->reply('send.sent_channel', ['channel' => $result->targetName ?? $targetArg]);
                break;

            case SendMemoOutcome::Throttled:
                $context->reply('send.throttled', ['seconds' => $result->cooldownRemainingSeconds]);
                break;

            case SendMemoOutcome::CannotSendToSelf:
                $context->reply('send.cannot_send_to_self');
                break;

            case SendMemoOutcome::NickNotRegistered:
                $context->reply('send.nick_not_registered', ['nick' => $result->targetName ?? $targetArg]);
                break;

            case SendMemoOutcome::ChannelNotRegistered:
                $context->reply('send.channel_not_registered', ['channel' => $result->targetName ?? $targetArg]);
                break;

            case SendMemoOutcome::Ignored:
                $context->reply('send.ignored');
                break;

            case SendMemoOutcome::LimitReached:
                $context->reply('send.limit_reached', ['target' => $result->targetName ?? $targetArg]);
                break;
        }
    }
}
