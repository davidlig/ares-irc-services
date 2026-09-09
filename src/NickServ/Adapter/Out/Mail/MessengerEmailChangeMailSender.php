<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Mail;

use App\NickServ\Application\Port\Out\EmailChangeMailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

final readonly class MessengerEmailChangeMailSender implements EmailChangeMailSender
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private string $nickservNick,
    ) {}

    public function sendVerification(
        string $currentEmail,
        string $nickname,
        string $newEmail,
        string $token,
        string $locale,
    ): void {
        try {
            $subject = $this->translator->trans(
                'email_change_token_subject',
                ['%bot%' => $this->nickservNick],
                'mail',
                $locale,
            );
            $body = $this->translator->trans(
                'email_change_token_body',
                ['%new_email%' => $newEmail, '%token%' => $token, '%bot%' => $this->nickservNick],
                'mail',
                $locale,
            );
            $this->messageBus->dispatch(new RegistrationVerificationEmail($currentEmail, $subject, $body));
        } catch (Throwable $exception) {
            $this->logger->error('NickServ SET EMAIL: failed to dispatch token email', [
                'nick' => $nickname,
                'recipient' => $currentEmail,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
