<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\FounderChangeMailSender;
use App\ChanServ\Application\Port\Out\FounderChangeTokenGenerator;
use App\ChanServ\Application\Port\Out\FounderChangeTokenPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function base64_decode;
use function count;
use function inet_ntop;
use function sprintf;
use function strpos;
use function substr;
use function trim;

final readonly class SetFounderHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelAccessRepositoryInterface $accessRepository,
        private ChanUserAccountPort $accountPort,
        private FounderChangeTokenPort $founderTokenRegistry,
        private EventBusInterface $eventDispatcher,
        private FounderChangeTokenGenerator $tokenGenerator,
        private FounderChangeMailSender $mailSender,
        private int $founderTokenTtlSeconds = 3600,
        private int $founderMinIntervalSeconds = 600,
        private int $maxChannelsPerNick = 3,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function handle(ChanServContext $context, RegisteredChannel $channel, string $value): void
    {
        $newNickname = trim($value);
        if ('' === $newNickname) {
            $context->reply('set.founder.syntax');

            return;
        }

        $newAccount = $this->accountPort->findAccountByNick($newNickname);
        if (null === $newAccount) {
            $context->reply('error.nick_not_registered', ['%nickname%' => $newNickname]);

            return;
        }

        $validation = $this->validateFounderTransfer($context, $channel, $newNickname, $newAccount);
        if (null === $validation) {
            return;
        }

        $this->executeFounderTransfer($context, $channel, $newAccount, $newNickname, $validation);
    }

    private function validateFounderTransfer(ChanServContext $context, RegisteredChannel $channel, string $newNickname, ChanAccountView $newAccount): ?string
    {
        $founderTransferKey = $this->validateFounderAccount($context, $channel, $newNickname, $newAccount);
        if (null === $founderTransferKey) {
            return null;
        }

        $limitKey = $this->validateFounderLimit($context, $channel, $newNickname, $newAccount);
        if (null === $limitKey) {
            return null;
        }

        return $founderTransferKey;
    }

    private function validateFounderAccount(ChanServContext $context, RegisteredChannel $channel, string $newNickname, ChanAccountView $newAccount): ?string
    {
        if ($newAccount->suspended) {
            $context->reply('set.founder.suspended', ['%nickname%' => $newNickname]);

            return null;
        }
        if (!$newAccount->registered) {
            $context->reply('set.founder.must_be_registered', ['%nickname%' => $newNickname]);

            return null;
        }

        return $this->validateFounderSelfAndSuccessor($context, $channel, $newNickname, $newAccount);
    }

    private function validateFounderSelfAndSuccessor(ChanServContext $context, RegisteredChannel $channel, string $newNickname, ChanAccountView $newAccount): ?string
    {
        if ($newAccount->id === $channel->getFounderNickId()) {
            $context->reply('set.founder.cannot_be_self');

            return null;
        }
        if (null !== $channel->getSuccessorNickId() && $newAccount->id === $channel->getSuccessorNickId()) {
            $context->reply('set.founder.cannot_be_successor');

            return null;
        }

        return 'valid';
    }

    private function validateFounderLimit(ChanServContext $context, RegisteredChannel $channel, string $newNickname, ChanAccountView $newAccount): ?string
    {
        $existingChannelsByNewFounder = $this->channelRepository->findByFounderNickId($newAccount->id);
        if (count($existingChannelsByNewFounder) >= $this->maxChannelsPerNick) {
            $context->reply('set.founder.limit_exceeded', ['%nickname%' => $newNickname, '%max%' => (string) $this->maxChannelsPerNick]);

            return null;
        }

        return 'valid';
    }

    private function executeFounderTransfer(ChanServContext $context, RegisteredChannel $channel, ChanAccountView $newAccount, string $newNickname, string $validationKey): void
    {
        if ($context->isLevelFounder) {
            $this->directTransfer($context, $channel, $newAccount->id);

            return;
        }

        $this->executeEmailTokenFlow($context, $channel, $newAccount, $newNickname);
    }

    private function executeEmailTokenFlow(ChanServContext $context, RegisteredChannel $channel, ChanAccountView $newAccount, string $newNickname): void
    {
        $currentFounder = $this->accountPort->findAccountById($channel->getFounderNickId());
        $founderEmail = $currentFounder?->email;
        if (null === $founderEmail || '' === $founderEmail) {
            $context->reply('set.founder.no_email');

            return;
        }

        $args = $context->args;
        $tokenArg = isset($args[3]) ? trim($args[3]) : null;

        if (null === $tokenArg || '' === $tokenArg) {
            $this->requestToken($context, $channel, $newAccount->id, $newNickname, $founderEmail);

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

        $token = $this->tokenGenerator->generate();
        $expiresAt = new DateTimeImmutable(sprintf('+%d seconds', $this->founderTokenTtlSeconds));
        $this->founderTokenRegistry->store($channel->getId(), $newFounderNickId, $token, $expiresAt);
        $this->founderTokenRegistry->recordRequest($channel->getId());

        try {
            $this->mailSender->sendFounderChangeToken(
                $founderEmail,
                $channel->getName(),
                $newNickname,
                $token,
                $context->getNotifier()->getNick(),
                $context->getLanguage(),
            );
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

        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        $oldFounderNickId = $channel->getFounderNickId();
        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);
        $performedByNickId = $context->senderAccount?->id;

        $channel->changeFounder($newFounderNickId);
        $this->channelRepository->save($channel);

        $existingAccess = $this->accessRepository->findByChannelAndNick($channel->getId(), $newFounderNickId);
        if (null !== $existingAccess) {
            $this->accessRepository->remove($existingAccess);
        }

        $this->eventDispatcher->dispatch(new ChannelFounderChangedEvent(
            channelId: $channel->getId(),
            channelName: $channel->getName(),
            oldFounderNickId: $oldFounderNickId,
            newFounderNickId: $newFounderNickId,
            performedBy: $sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
            byOperator: false,
        ));

        $newAccount = $this->accountPort->findAccountById($newFounderNickId);
        $newFounderNick = $newAccount->nickname ?? (string) $newFounderNickId;
        $context->reply('set.founder.updated', ['%nickname%' => $newFounderNick]);

        $notice = $context->trans('set.founder.notice_channel', [
            '%from%' => $sender->nick,
            '%nickname%' => $newFounderNick,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
    }

    private function directTransfer(ChanServContext $context, RegisteredChannel $channel, int $newFounderNickId): void
    {
        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        $oldFounderNickId = $channel->getFounderNickId();
        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);
        $performedByNickId = $context->senderAccount?->id;

        $channel->changeFounder($newFounderNickId);
        $this->channelRepository->save($channel);

        $existingAccess = $this->accessRepository->findByChannelAndNick($channel->getId(), $newFounderNickId);
        if (null !== $existingAccess) {
            $this->accessRepository->remove($existingAccess);
        }

        $this->eventDispatcher->dispatch(new ChannelFounderChangedEvent(
            channelId: $channel->getId(),
            channelName: $channel->getName(),
            oldFounderNickId: $oldFounderNickId,
            newFounderNickId: $newFounderNickId,
            performedBy: $sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
            byOperator: $context->isLevelFounder,
        ));

        $newAccount = $this->accountPort->findAccountById($newFounderNickId);
        $newFounderNick = $newAccount->nickname ?? (string) $newFounderNickId;
        $context->reply('set.founder.updated', ['%nickname%' => $newFounderNick]);

        $notice = $context->trans('set.founder.notice_channel', [
            '%from%' => $sender->nick,
            '%nickname%' => $newFounderNick,
        ]);
        $context->getNotifier()->sendNoticeToChannel($channel->getName(), $notice);
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

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if (false === $at || $at < 2) {
            return '***@***';
        }

        return substr($email, 0, 2) . '***' . substr($email, $at);
    }
}
