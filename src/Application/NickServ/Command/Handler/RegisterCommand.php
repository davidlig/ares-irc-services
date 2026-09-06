<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Helper\SecureToken;
use App\Application\Mail\Message\SendEmail;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\NickServClientKeyResolver;
use App\Application\NickServ\RegisterThrottleRegistry;
use App\Application\Port\AsyncMessageDispatcherInterface;
use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickPasswordProvidedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use App\Irc\Application\Port\In\SenderView;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
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
        private RegisteredNickRepositoryInterface $nickRepository,
        private PasswordHasherInterface $passwordHasher,
        private RegisterThrottleRegistry $throttleRegistry,
        private NickServClientKeyResolver $clientKeyResolver,
        private AsyncMessageDispatcherInterface $messageBus,
        private EventBusInterface $eventDispatcher,
        private TranslationInterface $translator,
        private LoggerInterface $logger,
        private int $registerMinIntervalSeconds,
        private string $guestPrefix = 'Guest-',
    ) {}

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

        $errorKey = $this->validateRegister($context, $sender);
        if (null !== $errorKey) {
            if ('__handled__' === $errorKey) {
                return;
            }

            $context->reply(
                $errorKey,
                $this->getRegisterErrorParams($context, $errorKey),
            );

            return;
        }

        $this->executeRegister($context, $sender);
    }

    private function validateRegister(NickServContext $context, SenderView $sender): ?string
    {
        $clientKey = $this->clientKeyResolver->getClientKey($sender);
        $remaining = $this->throttleRegistry->getRemainingCooldownSeconds($clientKey, $this->registerMinIntervalSeconds);

        $this->logger->debug('REGISTER throttle', [
            'key_prefix' => str_contains($clientKey, ':') ? substr($clientKey, 0, strpos($clientKey, ':') + 1) : '?',
            'throttled' => $remaining > 0,
        ]);

        if ($remaining > 0) {
            $minutes = (int) ceil($remaining / 60);
            $context->reply('register.throttled', ['minutes' => (string) $minutes]);

            return '__handled__';
        }

        $nick = $sender->nick;

        if (str_starts_with($nick, $this->guestPrefix)) {
            return 'register.guest_prefix_forbidden';
        }

        return $this->validateRegisterDetails($context, $nick);
    }

    private function validateRegisterDetails(NickServContext $context, string $nick): ?string
    {
        return (function () use ($context, $nick): ?string {
            $email = $context->args[1];

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return 'register.invalid_email';
            }

            if (null !== $this->nickRepository->findByEmail($email)) {
                return 'register.email_already_used';
            }

            $existing = $this->nickRepository->findByNick($nick);
            if (null !== $existing) {
                $context->reply(match ($existing->getStatus()) {
                    NickStatus::Pending => 'register.already_pending',
                    NickStatus::Forbidden => 'register.forbidden',
                    NickStatus::PendingDeletion => 'register.pending_deletion',
                    default => 'register.already_registered',
                }, ['nickname' => $nick]);

                return '__handled__';
            }

            return null;
        })();
    }

    /** @return array<string, mixed> */
    private function getRegisterErrorParams(NickServContext $context, string $errorKey): array
    {
        return match ($errorKey) {
            'register.guest_prefix_forbidden' => ['prefix' => $this->guestPrefix],
            'register.email_already_used' => ['email' => $context->args[1]],
            default => [],
        };
    }

    private function executeRegister(NickServContext $context, SenderView $sender): void
    {
        $nick = $sender->nick;
        $password = $context->args[0];
        $email = $context->args[1];

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

        $this->eventDispatcher->dispatch(new NickPasswordProvidedEvent(
            null,
            $nick,
            $password,
            $hash,
        ));

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

        $this->throttleRegistry->recordAttempt($this->clientKeyResolver->getClientKey($sender));
        $context->reply('register.pending', ['email' => $email]);
    }
}
