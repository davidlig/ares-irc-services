<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\MemoServ\Adapter\In\Irc\MemoNoticePresenter;
use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Application\UseCase\Send\SendMemo;
use App\MemoServ\Application\UseCase\Send\SendMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Send\SendMemoOutcome;
use App\MemoServ\Domain\Entity\Memo;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private TranslatorInterface $translator,
        private ?MemoNoticePresenter $presenter = null,
    ) {}

    public function getName(): string
    {
        return 'SEND';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('send.syntax', [new Argument('target'), new Argument('message', rest: true)]);
    }

    public function getMinArgs(): int
    {
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    public function getHelpKey(): string
    {
        return 'send.help';
    }

    public function getExampleKey(): string
    {
        return 'send.example';
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

    public function execute(MemoServContext $context): null
    {
        $senderAccount = $context->senderAccount;
        if (null === $senderAccount || null === $context->sender) {
            $context->replyError('error.not_identified');

            return null;
        }

        $validation = CommandArgumentValidator::validate($this->getArgumentDefinition(), $context->args);
        if (!$validation->isValid()) {
            $context->replyError('error.syntax', ['syntax' => $context->trans($validation->syntaxKey ?? $this->getSyntaxKey())]);

            return null;
        }

        $targetArg = $context->args[0] ?? '';
        $message = implode(' ', array_slice($context->args, 1));

        if (mb_strlen($message) > Memo::MESSAGE_MAX_LENGTH) {
            $context->reply('send.message_too_long', ['max' => $context->emphasize((string) Memo::MESSAGE_MAX_LENGTH)], Role::Error);

            return null;
        }

        $result = $this->sendMemoHandler->handle(new SendMemo(
            senderNickId: $senderAccount->id,
            senderUid: $context->sender->uid,
            target: $targetArg,
            message: $message,
            occurredAt: new DateTimeImmutable(),
        ));

        switch ($result->outcome) {
            case SendMemoOutcome::SentToNick:
                $targetName = $result->targetName ?? $targetArg;
                $context->reply('send.sent_nick', ['nickname' => $context->emphasize($targetName)], Role::Success);
                if (null !== $result->unreadCount && $result->unreadCount > 0) {
                    $recipientView = $this->userLookup->findByNick($targetName);
                    if (null !== $recipientView) {
                        $notice = null === $this->presenter
                            ? $this->translator->trans('notify.nick_pending', [
                                '%count%' => $result->unreadCount,
                                '%bot%' => $context->getNotifier()->getNick(),
                                '%list%' => 'LIST',
                            ], 'memoserv', $result->recipientLanguage ?? 'en')
                            : $this->presenter->nick($result->unreadCount, $context->getNotifier()->getNick(), $result->recipientLanguage ?? 'en');
                        $context->getNotifier()->sendNotice($recipientView->uid, $notice);
                    }
                }
                break;

            case SendMemoOutcome::SentToChannel:
                $context->reply('send.sent_channel', ['channel' => $context->emphasize($result->targetName ?? $targetArg)], Role::Success);
                break;

            case SendMemoOutcome::Throttled:
                $context->reply('send.throttled', ['seconds' => $context->emphasize((string) $result->cooldownRemainingSeconds)], Role::Warning);
                break;

            case SendMemoOutcome::CannotSendToSelf:
                $context->reply('send.cannot_send_to_self', [], Role::Error);
                break;

            case SendMemoOutcome::NickNotRegistered:
                $context->replyError('error.nick_not_registered', ['nick' => $result->targetName ?? $targetArg]);
                break;

            case SendMemoOutcome::ChannelNotRegistered:
                $context->replyError('error.channel_not_registered', ['channel' => $result->targetName ?? $targetArg]);
                break;

            case SendMemoOutcome::Ignored:
                $context->reply('send.ignored', [], Role::Error);
                break;

            case SendMemoOutcome::LimitReached:
                $context->reply('send.limit_reached', ['target' => $context->emphasize($result->targetName ?? $targetArg)], Role::Error);
                break;
        }

        return null;
    }
}
