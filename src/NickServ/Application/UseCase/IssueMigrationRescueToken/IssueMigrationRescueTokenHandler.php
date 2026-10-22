<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\IssueMigrationRescueToken;

use App\NickServ\Application\Model\UserMessagePreference;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\Port\Out\RescueTokenNotifier;
use App\NickServ\Application\Port\Out\TemporaryRescueTokenStore;
use LogicException;
use OverflowException;

use function sprintf;

final readonly class IssueMigrationRescueTokenHandler implements IssueMigrationRescueTokenHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private RescueTokenCodec $codec,
        private TemporaryRescueTokenStore $temporaryTokens,
        private RescueTokenNotifier $notifier,
        private int $verificationTokenTtlSeconds = 3600,
    ) {}

    public function handle(IssueMigrationRescueToken $command): void
    {
        $account = $this->nickRepository->findById($command->accountId);
        if (null === $account || !$account->isRegistered() || null !== $account->getRescueTokenHash()) {
            return;
        }
        $this->temporaryTokens->prune($command->occurredAt);
        $token = $this->codec->generate();
        $hash = $this->codec->hash($token) ?? throw new LogicException('Generated rescue token is invalid.');
        try {
            $this->temporaryTokens->replace($command->accountId, $hash, $command->occurredAt->modify(sprintf('+%d seconds', $this->verificationTokenTtlSeconds)));
        } catch (OverflowException) {
            // Authentication remains valid; a later authentication may retry issuance.
            return;
        }
        $this->notifier->issued($command->senderUid, $token, $account->getLanguage(), $account->prefersPrivateMessages() ? UserMessagePreference::PrivateMessage : UserMessagePreference::Notice);
    }
}
