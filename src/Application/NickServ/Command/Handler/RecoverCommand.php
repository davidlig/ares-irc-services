<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Helper\EmailMasker;
use App\Application\Helper\SecureToken;
use App\Application\Mail\Message\SendEmail;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\Port\AsyncMessageDispatcherInterface;
use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickPasswordChangedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

use function count;
use function sprintf;

/**
 * RECOVER <nickname> [token].
 *
 * Without token: sends a recovery token to the account's email (masked hint shown).
 * With token: validates token, sets a new random password, shows IDENTIFY and SET PASSWORD commands.
 */
final readonly class RecoverCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly AsyncMessageDispatcherInterface $messageBus,
        private readonly TranslationInterface $translator,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
        private readonly EventBusInterface $eventDispatcher,
        private readonly int $recoverTokenTtlSeconds,
        private readonly int $recoverMinIntervalSeconds,
    ) {
    }

    public function getName(): string
    {
        return 'RECOVER';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'recover.syntax';
    }

    public function getHelpKey(): string
    {
        return 'recover.help';
    }

    public function getOrder(): int
    {
        return 7;
    }

    public function getShortDescKey(): string
    {
        return 'recover.short';
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

        $targetNick = $context->args[0];
        $account = $this->nickRepository->findByNick($targetNick);

        if (1 === count($context->args)) {
            $this->requestToken($context, $targetNick, $account);

            return;
        }

        $this->consumeToken($context, $targetNick, $context->args[1], $account);
    }

    private function requestToken(NickServContext $context, string $targetNick, ?RegisteredNick $account): void
    {
        $errorKey = $this->validateRecoverRequest($context, $targetNick, $account);
        if (null !== $errorKey) {
            $context->reply($errorKey['key'], $errorKey['params']);

            return;
        }

        $this->sendRecoveryToken($context, $targetNick, $account);
    }

    private function validateRecoverRequest(NickServContext $context, string $targetNick, ?RegisteredNick $account): ?array
    {
        if (null === $account) {
            return ['key' => 'recover.not_registered', 'params' => ['nickname' => $targetNick]];
        }

        $result = match (true) {
            $account->isPending() => ['key' => 'recover.pending', 'params' => ['nickname' => $targetNick]],
            $account->isSuspended() => ['key' => 'recover.suspended', 'params' => ['nickname' => $targetNick, 'reason' => $account->getReason() ?? '']],
            $account->isForbidden() => ['key' => 'recover.forbidden', 'params' => ['nickname' => $targetNick]],
            null === $account->getEmail() || '' === $account->getEmail() => ['key' => 'recover.no_email', 'params' => ['nickname' => $targetNick]],
            default => $this->validateRecoverThrottle($context, $targetNick),
        };

        return $result;
    }

    private function validateRecoverThrottle(NickServContext $context, string $targetNick): ?array
    {
        $registry = $context->getRecoveryTokenRegistry();
        $lastRecoverAt = $registry->getLastRecoverAt($targetNick);
        if (null === $lastRecoverAt || $this->recoverMinIntervalSeconds <= 0) {
            return null;
        }

        $nextAllowedAt = $lastRecoverAt->modify(sprintf('+%d seconds', $this->recoverMinIntervalSeconds));
        $now = new DateTimeImmutable();

        return $now < $nextAllowedAt
            ? ['key' => 'recover.throttled', 'params' => ['minutes' => (string) (int) ceil(($nextAllowedAt->getTimestamp() - $now->getTimestamp()) / 60)]]
            : null;
    }

    private function sendRecoveryToken(NickServContext $context, string $targetNick, RegisteredNick $account): void
    {
        $registry = $context->getRecoveryTokenRegistry();
        $token = SecureToken::hex(32);
        $expiresAt = new DateTimeImmutable(sprintf('+%d seconds', $this->recoverTokenTtlSeconds));
        $registry->store($targetNick, $token, $expiresAt);

        try {
            $locale = $account->getLanguage();
            $subject = $this->translator->trans('recovery_token_subject', ['%bot%' => $context->getNotifier()->getNick()], 'mail', $locale);
            $body = $this->translator->trans('recovery_token_body', [
                '%nickname%' => $targetNick,
                '%token%' => $token,
                '%bot%' => $context->getNotifier()->getNick(),
            ], 'mail', $locale);
            $this->messageBus->dispatch(new SendEmail($account->getEmail(), $subject, $body));
        } catch (Throwable $e) {
            $this->logger->error('NickServ RECOVER: failed to dispatch recovery email', [
                'nick' => $targetNick,
                'recipient' => $account->getEmail(),
                'exception' => $e,
            ]);
            $context->reply('error.mail_failed');

            return;
        }

        $registry->recordRecover($targetNick);
        $context->reply('recover.email_sent', ['email_hint' => EmailMasker::mask($account->getEmail())]);
    }

    private function consumeToken(NickServContext $context, string $targetNick, string $token, ?RegisteredNick $account): void
    {
        $errorKey = $this->validateRecoverConsume($context, $targetNick, $token, $account);
        if (null !== $errorKey) {
            $context->reply($errorKey['key'], $errorKey['params']);

            return;
        }

        $this->executeRecoverConsume($context, $targetNick, $account);
    }

    private function validateRecoverConsume(NickServContext $context, string $targetNick, string $token, ?RegisteredNick $account): ?array
    {
        if (null === $account) {
            return ['key' => 'recover.not_registered', 'params' => ['nickname' => $targetNick]];
        }

        $statusError = match (true) {
            $account->isPending() => ['key' => 'recover.pending', 'params' => ['nickname' => $targetNick]],
            $account->isSuspended() => ['key' => 'recover.suspended', 'params' => ['nickname' => $targetNick, 'reason' => $account->getReason() ?? '']],
            $account->isForbidden() => ['key' => 'recover.forbidden', 'params' => ['nickname' => $targetNick]],
            default => null,
        };

        if (null !== $statusError) {
            return $statusError;
        }

        $registry = $context->getRecoveryTokenRegistry();

        return $registry->consume($targetNick, $token)
            ? null
            : ['key' => 'recover.invalid_token', 'params' => ['nickname' => $targetNick]];
    }

    private function executeRecoverConsume(NickServContext $context, string $targetNick, RegisteredNick $account): void
    {
        $newPassword = SecureToken::hex(12);
        $account->changePasswordWithHasher($newPassword, $this->passwordHasher);
        $this->nickRepository->save($account);

        $ip = $this->decodeIp($context->sender->ipBase64);
        $host = sprintf('%s@%s', $context->sender->ident, $context->sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->eventDispatcher->dispatch(new NickPasswordChangedEvent(
            nickId: $account->getId(),
            nickname: $targetNick,
            changedByOwner: true,
            performedBy: $context->sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $identifyCmd = '/msg NickServ IDENTIFY ' . $targetNick . ' ' . $newPassword;
        $context->reply('recover.success_identify', ['identify_cmd' => $identifyCmd]);
        $context->reply('recover.success_then_change');
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
}
