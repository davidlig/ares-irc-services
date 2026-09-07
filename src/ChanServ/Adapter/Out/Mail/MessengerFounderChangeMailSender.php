<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Mail;

use App\Application\Port\TranslationInterface;
use App\ChanServ\Application\Port\Out\FounderChangeMailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function sprintf;

final readonly class MessengerFounderChangeMailSender implements FounderChangeMailSender
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TranslationInterface $translator,
        private LoggerInterface $logger,
    ) {}

    public function sendFounderChangeToken(
        string $email,
        string $channelName,
        string $newNickname,
        string $token,
        string $botNick,
        string $locale,
    ): void {
        try {
            $command = sprintf('SET %s FOUNDER %s %s', $channelName, $newNickname, $token);
            $subject = $this->translator->trans('founder_change_token_subject', ['%channel%' => $channelName], 'mail', $locale);
            $body = $this->translator->trans('founder_change_token_body', [
                '%channel%' => $channelName,
                '%new_nick%' => $newNickname,
                '%token%' => $token,
                '%command%' => $command,
                '%bot%' => $botNick,
            ], 'mail', $locale);

            $this->messageBus->dispatch(new FounderChangeEmail($email, $subject, $body));
        } catch (Throwable $exception) {
            $this->logger->error('ChanServ SET FOUNDER: failed to send email', [
                'channel' => $channelName,
                'recipient' => $email,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
