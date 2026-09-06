<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Mail;

use App\Application\Port\TranslationInterface;
use App\NickServ\Application\Port\Out\RecoveryMailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class MessengerRecoveryMailSender implements RecoveryMailSender
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TranslationInterface $translator,
        private LoggerInterface $logger,
        private string $nickservNick,
    ) {}

    public function sendRecovery(string $nickname, string $email, string $token, string $locale): void
    {
        try {
            $subject = $this->translator->trans(
                'recovery_token_subject',
                ['%bot%' => $this->nickservNick],
                'mail',
                $locale,
            );
            $body = $this->translator->trans(
                'recovery_token_body',
                ['%nickname%' => $nickname, '%token%' => $token, '%bot%' => $this->nickservNick],
                'mail',
                $locale,
            );
            $this->messageBus->dispatch(new RegistrationVerificationEmail($email, $subject, $body));
        } catch (Throwable $exception) {
            $this->logger->error('NickServ RECOVER: failed to dispatch recovery email', [
                'nick' => $nickname,
                'recipient' => $email,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
