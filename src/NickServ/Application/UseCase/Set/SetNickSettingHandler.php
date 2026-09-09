<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Set;

use App\NickServ\Application\Event\NickEmailChangedEvent;
use App\NickServ\Application\Event\NickPasswordChangedEvent;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\EmailChangeMailSender;
use App\NickServ\Application\Port\Out\ForbiddenVhostRepositoryInterface;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\PendingEmailChangeStore;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\SessionLanguageTracker;
use App\NickServ\Application\Port\Out\VerificationTokenGenerator;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\PublishedEvent\NickVhostChangedEvent;
use App\NickServ\Application\Service\NickProtectabilityStatus;
use App\NickServ\Application\Service\NickTargetValidator;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\Service\VhostValidator;
use App\NickServ\Domain\Entity\RegisteredNick;
use InvalidArgumentException;
use Throwable;

use function explode;
use function filter_var;
use function in_array;
use function strtolower;
use function strtoupper;
use function trim;

use const FILTER_VALIDATE_EMAIL;

final readonly class SetNickSettingHandler implements SetNickSettingHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickTargetValidator $targetValidator,
        private PasswordHasher $passwordHasher,
        private NickServEventPublisher $eventPublisher,
        private Clock $clock,
        private PendingEmailChangeStore $pendingEmailChanges,
        private VerificationTokenGenerator $tokenGenerator,
        private EmailChangeMailSender $mailSender,
        private SessionLanguageTracker $sessionLanguages,
        private VhostValidator $vhostValidator,
        private VhostDisplayResolver $displayResolver,
        private NickNetworkUserLookup $networkUsers,
        private NickNetworkActions $networkActions,
        private ForcedVhostCheckerInterface $forcedVhostChecker,
        private ForbiddenVhostRepositoryInterface $forbiddenVhosts,
    ) {}

    public function handle(SetNickSetting $input): SetNickSettingResult
    {
        if ($input->operatorMode) {
            $protected = $this->validateOperatorTarget($input);
            if (null !== $protected) {
                return $protected;
            }
        }

        $account = $this->nickRepository->findByNick($input->targetNickname);
        if (null === $account) {
            if (!$input->operatorMode && SetNickSettingOption::Language === $input->option) {
                return $this->changeSessionLanguage($input);
            }

            return $this->result(SetNickSettingOutcome::TargetNotRegistered, $input);
        }

        return match ($input->option) {
            SetNickSettingOption::Password => $this->changePassword($input, $account),
            SetNickSettingOption::Email => $this->changeEmail($input, $account),
            SetNickSettingOption::Language => $this->changeLanguage($input, $account),
            SetNickSettingOption::Timezone => $this->changeTimezone($input, $account),
            SetNickSettingOption::PrivateMode => $this->changePrivateMode($input, $account),
            SetNickSettingOption::MessageMode => $this->changeMessageMode($input, $account),
            SetNickSettingOption::Vhost => $this->changeVhost($input, $account),
        };
    }

    private function validateOperatorTarget(SetNickSetting $input): ?SetNickSettingResult
    {
        $result = $this->targetValidator->validate($input->targetNickname);
        if ($result->isAllowed()) {
            return null;
        }

        $outcome = match ($result->status) {
            NickProtectabilityStatus::IsRoot => SetNickSettingOutcome::TargetIsRoot,
            NickProtectabilityStatus::IsIrcop => SetNickSettingOutcome::TargetIsIrcop,
            default => SetNickSettingOutcome::TargetIsService,
        };

        return $this->result($outcome, $input, $result->nickname);
    }

    private function changePassword(SetNickSetting $input, RegisteredNick $account): SetNickSettingResult
    {
        if ('' === $input->value) {
            return $this->result(SetNickSettingOutcome::MissingValue, $input);
        }

        $account->changePassword($this->passwordHasher->hash($input->value));
        $this->nickRepository->save($account);
        $this->eventPublisher->publish(new NickPasswordHashAvailable(
            $account->getId(),
            $account->getNickname(),
            $account->getPasswordHash(),
        ));
        $this->eventPublisher->publish(new NickPasswordChangedEvent(
            nickId: $account->getId(),
            nickname: $account->getNickname(),
            changedByOwner: !$input->operatorMode,
            performedBy: $input->actor->nickname,
            performedByNickId: $input->actor->accountId,
            performedByIp: $input->actor->ip,
            performedByHost: $input->actor->host,
            occurredAt: $this->clock->now(),
        ));

        return $this->result(SetNickSettingOutcome::Changed, $input);
    }

    private function changeEmail(SetNickSetting $input, RegisteredNick $account): SetNickSettingResult
    {
        [$newEmail, $token] = $this->parseEmailValue($input->value);
        if ('' === $newEmail) {
            return $this->result(SetNickSettingOutcome::MissingValue, $input);
        }

        if (false === filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->result(SetNickSettingOutcome::InvalidEmail, $input, value: $newEmail);
        }

        $currentEmail = $account->getEmail();
        if (null === $currentEmail) {
            return $this->result(SetNickSettingOutcome::NoCurrentEmail, $input);
        }

        if ($input->operatorMode) {
            return $this->applyEmailChange($input, $account, $newEmail, false);
        }

        if (null !== $token && '' !== $token) {
            if (!$this->pendingEmailChanges->consume($account->getNickname(), $newEmail, $token, $this->clock->now())) {
                return $this->result(SetNickSettingOutcome::InvalidEmailToken, $input);
            }

            return $this->applyEmailChange($input, $account, $newEmail, true);
        }

        if ($this->emailBelongsToAnotherAccount($newEmail, $account)) {
            return $this->result(SetNickSettingOutcome::EmailAlreadyUsed, $input, value: $newEmail);
        }

        $token = $this->tokenGenerator->generate();
        $this->pendingEmailChanges->store($account->getNickname(), $newEmail, $token, $this->clock->now());

        try {
            $this->mailSender->sendVerification(
                $currentEmail,
                $account->getNickname(),
                $newEmail,
                $token,
                $input->locale,
            );
        } catch (Throwable) {
            return $this->result(SetNickSettingOutcome::MailDeliveryFailed, $input);
        }

        return new SetNickSettingResult(
            SetNickSettingOutcome::EmailConfirmationSent,
            $input->option,
            $input->targetNickname,
            currentEmail: $currentEmail,
            newEmail: $newEmail,
        );
    }

    /** @return array{string, string|null} */
    private function parseEmailValue(string $value): array
    {
        $parts = explode(' ', $value, 2);

        return [trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : null];
    }

    private function applyEmailChange(
        SetNickSetting $input,
        RegisteredNick $account,
        string $newEmail,
        bool $changedByOwner,
    ): SetNickSettingResult {
        if ($this->emailBelongsToAnotherAccount($newEmail, $account)) {
            return $this->result(SetNickSettingOutcome::EmailAlreadyUsed, $input, value: $newEmail);
        }

        $oldEmail = $account->getEmail();
        $account->changeEmail($newEmail);
        $this->nickRepository->save($account);
        $this->eventPublisher->publish(new NickEmailChangedEvent(
            nickId: $account->getId(),
            nickname: $account->getNickname(),
            oldEmail: $oldEmail,
            newEmail: $newEmail,
            changedByOwner: $changedByOwner,
            performedBy: $input->actor->nickname,
            performedByNickId: $input->actor->accountId,
            performedByIp: $input->actor->ip,
            performedByHost: $input->actor->host,
            occurredAt: $this->clock->now(),
        ));

        return $this->result(SetNickSettingOutcome::Changed, $input, value: $newEmail);
    }

    private function emailBelongsToAnotherAccount(string $email, RegisteredNick $account): bool
    {
        $existing = $this->nickRepository->findByEmail($email);

        return null !== $existing && $existing->getId() !== $account->getId();
    }

    private function changeLanguage(SetNickSetting $input, RegisteredNick $account): SetNickSettingResult
    {
        if ('' === $input->value) {
            return $this->result(SetNickSettingOutcome::MissingValue, $input);
        }

        try {
            $account->changeLanguage($input->value);
        } catch (InvalidArgumentException) {
            return $this->result(SetNickSettingOutcome::InvalidLanguage, $input);
        }

        $this->nickRepository->save($account);

        return $this->result(SetNickSettingOutcome::Changed, $input, value: $account->getLanguage());
    }

    private function changeSessionLanguage(SetNickSetting $input): SetNickSettingResult
    {
        $language = strtolower($input->value);
        if ('' === $language) {
            return $this->result(SetNickSettingOutcome::MissingValue, $input);
        }

        if (!in_array($language, RegisteredNick::SUPPORTED_LANGUAGES, true)) {
            return $this->result(SetNickSettingOutcome::InvalidLanguage, $input);
        }

        $this->sessionLanguages->register($input->actor->uid, $language);

        return $this->result(SetNickSettingOutcome::SessionLanguageChanged, $input, value: $language);
    }

    private function changeTimezone(SetNickSetting $input, RegisteredNick $account): SetNickSettingResult
    {
        $timezone = trim($input->value);
        if ('' === $timezone) {
            return $this->result(SetNickSettingOutcome::MissingValue, $input);
        }

        if (0 === strcasecmp($timezone, 'OFF')) {
            $account->changeTimezone(null);
            $this->nickRepository->save($account);

            return $this->result(SetNickSettingOutcome::Changed, $input);
        }

        try {
            $account->changeTimezone($timezone);
        } catch (InvalidArgumentException) {
            return $this->result(SetNickSettingOutcome::InvalidTimezone, $input, value: $timezone);
        }

        $this->nickRepository->save($account);

        return $this->result(SetNickSettingOutcome::Changed, $input, value: $account->getTimezone());
    }

    private function changePrivateMode(SetNickSetting $input, RegisteredNick $account): SetNickSettingResult
    {
        return $this->changeFlag($input, $account, true);
    }

    private function changeMessageMode(SetNickSetting $input, RegisteredNick $account): SetNickSettingResult
    {
        return $this->changeFlag($input, $account, false);
    }

    private function changeFlag(SetNickSetting $input, RegisteredNick $account, bool $private): SetNickSettingResult
    {
        $flag = strtoupper($input->value);
        if (!in_array($flag, ['ON', 'OFF'], true)) {
            return $this->result(SetNickSettingOutcome::InvalidFlag, $input);
        }

        if ($private) {
            $account->switchPrivate('ON' === $flag);
        } else {
            $account->switchMsg('ON' === $flag);
        }
        $this->nickRepository->save($account);

        return $this->result(SetNickSettingOutcome::Changed, $input, value: $flag);
    }

    private function changeVhost(SetNickSetting $input, RegisteredNick $account): SetNickSettingResult
    {
        if ($this->forcedVhostChecker->hasForcedVhost($account->getId())) {
            return $this->result(SetNickSettingOutcome::ForcedVhost, $input);
        }

        $normalizedInput = trim($input->value);
        if ('' === $normalizedInput || 'OFF' === strtoupper($normalizedInput)) {
            $account->changeVhost(null);
            $this->nickRepository->save($account);
            $this->applyVhostToNetwork($input, $account, '');
            $this->eventPublisher->publish(new NickVhostChangedEvent($account->getId(), $account->getNickname(), null));

            return $this->result(SetNickSettingOutcome::Changed, $input);
        }

        $normalized = $this->vhostValidator->normalize($normalizedInput);
        if (null === $normalized || $this->isForbiddenVhost($normalized)) {
            return $this->result(SetNickSettingOutcome::InvalidVhost, $input);
        }

        $existing = $this->nickRepository->findByVhost($normalized);
        if (null !== $existing && $existing->getId() !== $account->getId()) {
            return $this->result(SetNickSettingOutcome::VhostTaken, $input);
        }

        $account->changeVhost($normalized);
        $this->nickRepository->save($account);
        $displayVhost = $this->displayResolver->getDisplayVhost($normalized);
        $this->applyVhostToNetwork($input, $account, $displayVhost);
        $this->eventPublisher->publish(new NickVhostChangedEvent($account->getId(), $account->getNickname(), $normalized));

        return $this->result(SetNickSettingOutcome::Changed, $input, value: $displayVhost);
    }

    private function isForbiddenVhost(string $vhost): bool
    {
        return array_any(
            $this->forbiddenVhosts->findAll(),
            static fn ($forbidden): bool => $forbidden->matches($vhost),
        );
    }

    private function applyVhostToNetwork(SetNickSetting $input, RegisteredNick $account, string $vhost): void
    {
        if ($input->operatorMode) {
            $user = $this->networkUsers->findByNick($account->getNickname());
            if (null !== $user) {
                $this->networkActions->setUserVhost($user->uid, $vhost, $user->serverSid);
            }

            return;
        }

        $this->networkActions->setUserVhost($input->actor->uid, $vhost, $input->actor->serverSid);
    }

    private function result(
        SetNickSettingOutcome $outcome,
        SetNickSetting $input,
        ?string $targetNickname = null,
        ?string $value = null,
    ): SetNickSettingResult {
        return new SetNickSettingResult(
            $outcome,
            $input->option,
            $targetNickname ?? $input->targetNickname,
            $value,
        );
    }
}
