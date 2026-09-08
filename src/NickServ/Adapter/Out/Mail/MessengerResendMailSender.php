<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Mail;

use App\NickServ\Application\Port\Out\ResendMailSender;
use App\Shared\Application\Port\TranslationInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class MessengerResendMailSender implements ResendMailSender
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TranslationInterface $translator,
        private LoggerInterface $logger,
        private string $nickservNick,
    ) {}

    public function sendResend(string $nickname, string $email, string $token, string $locale): void
    {
        try {
            $subject = $this->translator->trans(
                'resend_verification_subject',
                ['%bot%' => $this->nickservNick],
                'mail',
                $locale,
            );
            $body = $this->translator->trans(
                'resend_verification_body',
                ['%nickname%' => $nickname, '%token%' => $token, '%bot%' => $this->nickservNick],
                'mail',
                $locale,
            );
            $this->messageBus->dispatch(new RegistrationVerificationEmail($email, $subject, $body));
        } catch (Throwable $exception) {
            $this->logger->error('NickServ RESEND: failed to dispatch verification email', [
                'nick' => $nickname,
                'recipient' => $email,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
