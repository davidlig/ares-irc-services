<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Mail;

use App\NickServ\Application\Port\Out\SaregisterMailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

final readonly class MessengerSaregisterMailSender implements SaregisterMailSender
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private string $nickservNick,
    ) {}

    public function sendRescueToken(string $nickname, string $email, string $token, string $locale): void
    {
        try {
            $subject = $this->translator->trans(
                'saregister_token_subject',
                ['%bot%' => $this->nickservNick],
                'mail',
                $locale,
            );
            $body = $this->translator->trans(
                'saregister_token_body',
                ['%nickname%' => $nickname, '%token%' => $token, '%bot%' => $this->nickservNick],
                'mail',
                $locale,
            );
            $this->messageBus->dispatch(new RegistrationVerificationEmail($email, $subject, $body));
        } catch (Throwable $exception) {
            $this->logger->error('NickServ SAREGISTER: failed to dispatch rescue token email', [
                'nick' => $nickname,
                'recipient' => $email,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
