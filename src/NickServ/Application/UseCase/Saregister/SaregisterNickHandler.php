<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\EmailDomainPolicy;
use App\NickServ\Application\Port\Out\NicknamePolicyInterface;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use App\NickServ\Application\Port\Out\RegistrationEventPublisher;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\Port\Out\SaregisterMailSender;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use LogicException;
use Throwable;

use function filter_var;
use function str_starts_with;

use const FILTER_VALIDATE_EMAIL;

final readonly class SaregisterNickHandler implements SaregisterNickHandlerInterface
{
    public function __construct(
        private RegisterNickRepository $nickRepository,
        private PasswordHasher $passwordHasher,
        private RescueTokenCodec $tokenGenerator,
        private Clock $clock,
        private RegistrationModeQuery $registrationMode,
        private RegistrationEventPublisher $eventPublisher,
        private NickHistoryService $history,
        private EmailDomainPolicy $emailDomainPolicy,
        private SaregisterMailSender $mailSender,
        private NicknamePolicyInterface $nicknamePolicy,
        private string $guestPrefix = 'Guest-',
    ) {}

    public function handle(SaregisterNick $command): SaregisterNickResult
    {
        if (!$this->nicknamePolicy->isValid($command->nickname)) {
            return SaregisterNickResult::invalidNickname($command->nickname);
        }

        if (str_starts_with($command->nickname, $this->guestPrefix)) {
            return SaregisterNickResult::guestPrefixForbidden($this->guestPrefix);
        }

        $mode = $this->registrationMode->current();
        if (RegistrationMode::Email === $mode) {
            if (null === $command->email || false === filter_var($command->email, FILTER_VALIDATE_EMAIL)) {
                return SaregisterNickResult::invalidEmail();
            }
            if ($this->emailDomainPolicy->isBlocked($command->email)) {
                return SaregisterNickResult::blockedEmailDomain();
            }
            if (null !== $this->nickRepository->findByEmail($command->email)) {
                return SaregisterNickResult::emailAlreadyUsed($command->email);
            }
        } elseif (null !== $command->email) {
            if (false === filter_var($command->email, FILTER_VALIDATE_EMAIL)) {
                return SaregisterNickResult::invalidEmail();
            }
            if ($this->emailDomainPolicy->isBlocked($command->email)) {
                return SaregisterNickResult::blockedEmailDomain();
            }
            if (null !== $this->nickRepository->findByEmail($command->email)) {
                return SaregisterNickResult::emailAlreadyUsed($command->email);
            }
        }

        $existing = $this->nickRepository->findByNick($command->nickname);
        if (null !== $existing) {
            return match ($existing->getStatus()) {
                NickStatus::Pending => SaregisterNickResult::alreadyPending($command->nickname),
                NickStatus::Forbidden => SaregisterNickResult::forbidden($command->nickname),
                NickStatus::PendingDeletion => SaregisterNickResult::pendingDeletion($command->nickname),
                default => SaregisterNickResult::alreadyRegistered($command->nickname),
            };
        }

        $token = $this->tokenGenerator->generate();
        $tokenHash = $this->tokenGenerator->hash($token) ?? throw new LogicException('Generated rescue token is invalid.');
        $passwordHash = $this->passwordHasher->hash($command->password);
        $now = $this->clock->now();

        $nick = RegisteredNick::createActive(
            nickname: $command->nickname,
            passwordHash: $passwordHash,
            email: $command->email,
            language: $command->language,
            registeredAt: $now,
            rescueTokenHash: $tokenHash,
        );

        $this->nickRepository->save($nick);

        $this->eventPublisher->publish(new NickPasswordHashAvailable(
            nickId: $nick->getId(),
            nickname: $nick->getNickname(),
            passwordHash: $nick->getPasswordHash(),
        ));

        $this->history->recordWithExplicitMetadata(
            nickId: $nick->getId(),
            action: 'SAREGISTER',
            performedBy: $command->actorNickname,
            performedByNickId: $command->actorAccountId,
            message: 'history.message.saregister',
            performedAt: $now,
            extraData: [],
        );

        if (RegistrationMode::Email === $mode) {
            try {
                $this->mailSender->sendRescueToken(
                    $command->nickname,
                    $command->email ?? throw new LogicException('Email registration requires an address.'),
                    $token,
                    $command->language,
                );
            } catch (Throwable) {
                return SaregisterNickResult::mailDeliveryFailed($command->nickname);
            }

            return SaregisterNickResult::registeredEmail($command->nickname, $command->email);
        }

        return SaregisterNickResult::registeredToken($command->nickname, $token);
    }
}
