<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\Service\NickServClientKeyResolver;
use App\NickServ\Application\UseCase\Identify\IdentifyNick;
use App\NickServ\Application\UseCase\Identify\IdentifyNickHandlerInterface;
use App\NickServ\Application\UseCase\Identify\IdentifyNickOutcome;
use App\NickServ\Application\UseCase\Identify\IdentifyNickResult;

use function assert;
use function ceil;
use function strcasecmp;

final readonly class IdentifyCommand implements NickServCommandInterface
{
    public function __construct(
        private IdentifyNickHandlerInterface $handler,
        private NetworkUserLookupPort $userLookup,
        private PendingNickRestoreRegistryInterface $pendingRegistry,
        private NickServClientKeyResolver $clientKeyResolver,
    ) {}

    public function getName(): string
    {
        return 'IDENTIFY';
    }

    public function getAliases(): array
    {
        return ['ID'];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'identify.syntax';
    }

    public function getHelpKey(): string
    {
        return 'identify.help';
    }

    public function getOrder(): int
    {
        return 2;
    }

    public function getShortDescKey(): string
    {
        return 'identify.short';
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
        $password = $context->args[1];
        $clientKey = $this->clientKeyResolver->getClientKey($sender);

        $result = $this->handler->handle(new IdentifyNick(
            nickname: $targetNick,
            password: $password,
            clientKey: $clientKey,
            senderUid: $sender->uid,
            senderNick: $sender->nick,
            senderIsIdentified: $sender->isIdentified,
        ));

        $this->present($context, $result);
    }

    private function present(NickServContext $context, IdentifyNickResult $result): void
    {
        switch ($result->outcome) {
            case IdentifyNickOutcome::Success:
                $this->handleSuccess($context, $result);
                break;
            case IdentifyNickOutcome::AlreadyIdentified:
                $context->reply('identify.already_identified', ['nickname' => $result->nickname]);
                break;
            case IdentifyNickOutcome::LockedOut:
                $context->reply('identify.locked_out', [
                    'minutes' => (string) (int) ceil($result->retryAfterSeconds / 60),
                ]);
                break;
            case IdentifyNickOutcome::NotRegistered:
                $context->reply('identify.not_registered', ['nickname' => $result->nickname]);
                break;
            case IdentifyNickOutcome::Pending:
                $context->reply('identify.pending', ['nickname' => $result->nickname]);
                break;
            case IdentifyNickOutcome::Suspended:
                $context->reply('identify.suspended', [
                    'nickname' => $result->nickname,
                    'reason' => $result->reason ?? '',
                ]);
                break;
            case IdentifyNickOutcome::Forbidden:
                $context->reply('identify.forbidden', ['nickname' => $result->nickname]);
                break;
            case IdentifyNickOutcome::PendingDeletion:
                $context->reply('identify.pending_deletion', ['nickname' => $result->nickname]);
                break;
            case IdentifyNickOutcome::InvalidCredentials:
                $context->reply('identify.invalid_credentials');
                break;
        }
    }

    private function handleSuccess(NickServContext $context, IdentifyNickResult $result): void
    {
        $sender = $context->sender;
        assert(null !== $sender && null !== $result->nickname);

        $currentHolder = $this->userLookup->findByNick($result->nickname);
        if (null !== $currentHolder && $sender->uid !== $currentHolder->uid) {
            $killReason = $context->transIn(
                'identify.kill_reason',
                ['nickname' => $result->nickname, 'source' => $sender->nick],
                $result->accountLanguage ?? '',
            );
            $context->getNotifier()->killUser($currentHolder->uid, $killReason);
            $context->reply('identify.ghost_released', ['nickname' => $result->nickname]);
        }

        if (0 !== strcasecmp($sender->nick, $result->nickname) || $this->pendingRegistry->peek($sender->uid)) {
            $context->getNotifier()->forceNick($sender->uid, $result->nickname);
        }

        $context->getNotifier()->setUserAccount($sender->uid, $result->nickname);

        if (null !== $result->displayVhost && '' !== $result->displayVhost) {
            $context->getNotifier()->setUserVhost($sender->uid, $result->displayVhost, $sender->serverSid);
        }

        $context->reply('identify.success', ['nickname' => $result->nickname]);
    }
}
