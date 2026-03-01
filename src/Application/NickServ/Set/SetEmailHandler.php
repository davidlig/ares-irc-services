<?php

declare(strict_types=1);

namespace App\Application\NickServ\Set;

use App\Application\Helper\SecureToken;
use App\Application\Mail\Message\SendEmail;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\PendingEmailChangeRegistry;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use const FILTER_VALIDATE_EMAIL;

final readonly class SetEmailHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly PendingEmailChangeRegistry $pendingEmailChangeRegistry,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(NickServContext $context, RegisteredNick $account, string $value): void
    {
        $parts = explode(' ', $value, 2);
        $newEmail = trim($parts[0] ?? '');
        $token = isset($parts[1]) ? trim($parts[1]) : null;

        if ('' === $newEmail) {
            $context->reply('error.syntax', ['syntax' => $context->trans('set.email.syntax')]);

            return;
        }

        if (false === filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $context->reply('register.invalid_email');

            return;
        }

        if (null === $account->getEmail()) {
            $context->reply('error.not_identified');

            return;
        }

        if (null !== $token && '' !== $token) {
            $this->confirmEmailChange($context, $account, $newEmail, $token);

            return;
        }

        $this->requestEmailChange($context, $account, $newEmail);
    }

    private function requestEmailChange(NickServContext $context, RegisteredNick $account, string $newEmail): void
    {
        $nick = $account->getNickname();
        $existingByEmail = $this->nickRepository->findByEmail($newEmail);
        if (null !== $existingByEmail && strtolower($existingByEmail->getNickname()) !== strtolower($nick)) {
            $context->reply('register.email_already_used', ['email' => $newEmail]);

            return;
        }

        $currentEmail = $account->getEmail();
        if (null === $currentEmail) {
            $context->reply('error.not_identified');

            return;
        }

        $token = SecureToken::hex(32);
        $this->pendingEmailChangeRegistry->store($nick, $newEmail, $token);

        try {
            $locale = $context->getLanguage();
            $subject = $this->translator->trans('email_change_token_subject', [], 'mail', $locale);
            $body = $this->translator->trans('email_change_token_body', ['%new_email%' => $newEmail, '%token%' => $token], 'mail', $locale);
            $this->messageBus->dispatch(new SendEmail($currentEmail, $subject, $body));
        } catch (Throwable $e) {
            $this->logger->error('NickServ SET EMAIL: failed to dispatch token email', [
                'nick' => $nick,
                'recipient' => $currentEmail,
                'exception' => $e,
            ]);
            $context->reply('error.mail_failed');

            return;
        }

        $context->reply('set.email.pending_sent', [
            'current_email' => $currentEmail,
            'new_email' => $newEmail,
        ]);
    }

    private function confirmEmailChange(NickServContext $context, RegisteredNick $account, string $newEmail, string $token): void
    {
        $nick = $account->getNickname();
        if (!$this->pendingEmailChangeRegistry->consume($nick, $newEmail, $token)) {
            $context->reply('set.email.invalid_token');

            return;
        }

        $existingByEmail = $this->nickRepository->findByEmail($newEmail);
        if (null !== $existingByEmail && $existingByEmail->getId() !== $account->getId()) {
            $context->reply('register.email_already_used', ['email' => $newEmail]);

            return;
        }

        $account->changeEmail($newEmail);
        $this->nickRepository->save($account);
        $context->reply('set.email.success', ['email' => $newEmail]);
    }
}
