<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Event\ChannelFounderChangedEvent;
use App\Application\ChanServ\FounderChangeTokenRegistry;
use App\Application\Helper\SecureToken;
use App\Application\Mail\Message\SendEmail;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function sprintf;

final readonly class SetFounderHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelAccessRepositoryInterface $accessRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private FounderChangeTokenRegistry $founderTokenRegistry,
        private EventDispatcherInterface $eventDispatcher,
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
        private int $founderTokenTtlSeconds = 3600,
        private int $founderMinIntervalSeconds = 600,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $newNickname = trim($value);
        if ('' === $newNickname) {
            $context->reply('set.founder.syntax');

            return;
        }

        $newAccount = $this->nickRepository->findByNick($newNickname);
        if (null === $newAccount) {
            $context->reply('error.nick_not_registered', ['%nick%' => $newNickname]);

            return;
        }
        if (NickStatus::Suspended === $newAccount->getStatus()) {
            $context->reply('set.founder.suspended', ['%nick%' => $newNickname]);

            return;
        }
        if (NickStatus::Registered !== $newAccount->getStatus()) {
            $context->reply('set.founder.must_be_registered', ['%nick%' => $newNickname]);

            return;
        }
        if ($newAccount->getId() === $channel->getFounderNickId()) {
            $context->reply('set.founder.cannot_be_self');

            return;
        }
        if (null !== $channel->getSuccessorNickId() && $newAccount->getId() === $channel->getSuccessorNickId()) {
            $context->reply('set.founder.cannot_be_successor');

            return;
        }

        $currentFounder = $this->nickRepository->findById($channel->getFounderNickId());
        $founderEmail = $currentFounder?->getEmail();
        if (null === $founderEmail || '' === $founderEmail) {
            $context->reply('set.founder.no_email');

            return;
        }

        $args = $context->args;
        $tokenArg = isset($args[3]) ? trim($args[3]) : null;

        if (null === $tokenArg || '' === $tokenArg) {
            $this->requestToken($context, $channel, $newAccount->getId(), $newNickname, $founderEmail);

            return;
        }

        $this->consumeToken($context, $channel, $tokenArg);
    }

    private function requestToken(
        ChanServContext $context,
        RegisteredChannel $channel,
        int $newFounderNickId,
        string $newNickname,
        string $founderEmail,
    ): void {
        $lastAt = $this->founderTokenRegistry->getLastRequestAt($channel->getId());
        if (null !== $lastAt && $this->founderMinIntervalSeconds > 0) {
            $nextAllowed = $lastAt->modify(sprintf('+%d seconds', $this->founderMinIntervalSeconds));
            if (new DateTimeImmutable() < $nextAllowed) {
                $context->reply('set.founder.throttled');

                return;
            }
        }

        $token = SecureToken::hex(32);
        $expiresAt = new DateTimeImmutable(sprintf('+%d seconds', $this->founderTokenTtlSeconds));
        $this->founderTokenRegistry->store($channel->getId(), $newFounderNickId, $token, $expiresAt);
        $this->founderTokenRegistry->recordRequest($channel->getId());

        $locale = $context->getLanguage();
        $channelName = $channel->getName();
        $command = sprintf('SET %s FOUNDER %s %s', $channelName, $newNickname, $token);
        $subject = $this->translator->trans('founder_change_token_subject', ['%channel%' => $channelName], 'mail', $locale);
        $body = $this->translator->trans('founder_change_token_body', [
            '%channel%' => $channelName,
            '%new_nick%' => $newNickname,
            '%token%' => $token,
            '%command%' => $command,
        ], 'mail', $locale);

        try {
            $this->messageBus->dispatch(new SendEmail($founderEmail, $subject, $body));
        } catch (Throwable $e) {
            $this->logger->error('ChanServ SET FOUNDER: failed to send email', ['exception' => $e]);
            $context->reply('error.mail_failed');

            return;
        }

        $context->reply('set.founder.token_sent', ['%email_hint%' => $this->maskEmail($founderEmail)]);
    }

    private function consumeToken(ChanServContext $context, RegisteredChannel $channel, string $token): void
    {
        $newFounderNickId = $this->founderTokenRegistry->consume($channel->getId(), $token);
        if (null === $newFounderNickId) {
            $context->reply('set.founder.invalid_token');

            return;
        }
        if ($channel->getFounderNickId() === $newFounderNickId) {
            $context->reply('set.founder.cannot_be_self');

            return;
        }
        if (null !== $channel->getSuccessorNickId() && $channel->getSuccessorNickId() === $newFounderNickId) {
            $context->reply('set.founder.cannot_be_successor');

            return;
        }

        $channel->changeFounder($newFounderNickId);
        $this->channelRepository->save($channel);

        $existingAccess = $this->accessRepository->findByChannelAndNick($channel->getId(), $newFounderNickId);
        if (null !== $existingAccess) {
            $this->accessRepository->remove($existingAccess);
        }

        $this->eventDispatcher->dispatch(new ChannelFounderChangedEvent($channel->getName()));

        $newAccount = $this->nickRepository->findById($newFounderNickId);
        $newFounderNick = $newAccount?->getNickname() ?? (string) $newFounderNickId;
        $context->reply('set.founder.updated', ['%nick%' => $newFounderNick]);

        $notice = $context->trans('set.founder.notice_channel', [
            '%from%' => $context->sender->nick,
            '%nick%' => $newFounderNick,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
    }

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if (false === $at || $at < 2) {
            return '***@***';
        }

        return substr($email, 0, 2) . '***' . substr($email, $at);
    }
}
