<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Helper\SecureToken;
use App\Application\Mail\Message\SendEmail;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\NickServClientKeyResolver;
use App\Application\NickServ\RegisterThrottleRegistry;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function sprintf;
use function str_starts_with;

use const FILTER_VALIDATE_EMAIL;

/**
 * REGISTER <password> <email>.
 *
 * Starts the registration flow for the user's current nickname.
 * The account is created in PENDING status; the user must run VERIFY <token>
 * to activate it. A verification token is sent by email.
 */
final readonly class RegisterCommand implements NickServCommandInterface
{
    private const int TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly RegisterThrottleRegistry $throttleRegistry,
        private readonly NickServClientKeyResolver $clientKeyResolver,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly int $registerMinIntervalSeconds,
        private readonly string $guestPrefix = 'Guest-',
    ) {
    }

    public function getName(): string
    {
        return 'REGISTER';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'register.syntax';
    }

    public function getHelpKey(): string
    {
        return 'register.help';
    }

    public function getOrder(): int
    {
        return 1;
    }

    public function getShortDescKey(): string
    {
        return 'register.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return null;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): void
    {
        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        $clientKey = $this->clientKeyResolver->getClientKey($sender);
        $remaining = $this->throttleRegistry->getRemainingCooldownSeconds($clientKey, $this->registerMinIntervalSeconds);

        $this->logger->debug('REGISTER throttle', [
            'key_prefix' => str_contains($clientKey, ':') ? substr($clientKey, 0, strpos($clientKey, ':') + 1) : '?',
            'throttled' => $remaining > 0,
        ]);

        if ($remaining > 0) {
            $minutes = (int) ceil($remaining / 60);
            $context->reply('register.throttled', ['minutes' => (string) $minutes]);

            return;
        }

        $nick = $sender->nick;
        $password = $context->args[0];
        $email = $context->args[1];

        if (str_starts_with($nick, $this->guestPrefix)) {
            $context->reply('register.guest_prefix_forbidden', ['prefix' => $this->guestPrefix]);

            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $context->reply('register.invalid_email');

            return;
        }

        $existingByEmail = $this->nickRepository->findByEmail($email);
        if (null !== $existingByEmail) {
            $context->reply('register.email_already_used', ['email' => $email]);

            return;
        }

        $existing = $this->nickRepository->findByNick($nick);

        if (null !== $existing) {
            $context->reply(match ($existing->getStatus()) {
                NickStatus::Pending => 'register.already_pending',
                NickStatus::Forbidden => 'register.forbidden',
                default => 'register.already_registered',
            }, ['nickname' => $nick]);

            return;
        }

        $hash = $this->passwordHasher->hash($password);
        $expiresAt = new DateTimeImmutable(sprintf('+%d seconds', self::TOKEN_TTL_SECONDS));
        $token = SecureToken::hex(32);

        $registered = RegisteredNick::createPending(
            nickname: $nick,
            passwordHash: $hash,
            email: $email,
            language: $context->getLanguage(),
            expiresAt: $expiresAt,
        );

        $this->nickRepository->save($registered);

        $context->getPendingVerificationRegistry()->store($nick, $token, $expiresAt);

        try {
            $locale = $context->getLanguage();
            $subject = $this->translator->trans('register_verification_subject', ['%bot%' => $context->getNotifier()->getNick()], 'mail', $locale);
            $body = $this->translator->trans('register_verification_body', ['%nickname%' => $nick, '%token%' => $token, '%bot%' => $context->getNotifier()->getNick()], 'mail', $locale);
            $this->messageBus->dispatch(new SendEmail($email, $subject, $body));
        } catch (Throwable $e) {
            $this->logger->error('NickServ REGISTER: failed to dispatch verification email', [
                'nick' => $nick,
                'recipient' => $email,
                'exception' => $e,
            ]);
            $context->reply('error.mail_failed');

            return;
        }

        $this->throttleRegistry->recordAttempt($clientKey);
        $context->reply('register.pending', ['email' => $email]);
    }
}
