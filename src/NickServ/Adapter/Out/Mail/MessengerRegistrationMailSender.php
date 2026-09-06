<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Mail;

use App\Application\Port\TranslationInterface;
use App\NickServ\Application\Port\Out\RegistrationMailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class MessengerRegistrationMailSender implements RegistrationMailSender
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TranslationInterface $translator,
        private LoggerInterface $logger,
        private string $nickservNick,
    ) {}

    public function sendVerification(string $nickname, string $email, string $token, string $locale): void
    {
        try {
            $subject = $this->translator->trans(
                'register_verification_subject',
                ['%bot%' => $this->nickservNick],
                'mail',
                $locale,
            );
            $body = $this->translator->trans(
                'register_verification_body',
                ['%nickname%' => $nickname, '%token%' => $token, '%bot%' => $this->nickservNick],
                'mail',
                $locale,
            );
            $this->messageBus->dispatch(new RegistrationVerificationEmail($email, $subject, $body));
        } catch (Throwable $exception) {
            $this->logger->error('NickServ REGISTER: failed to dispatch verification email', [
                'nick' => $nickname,
                'recipient' => $email,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
