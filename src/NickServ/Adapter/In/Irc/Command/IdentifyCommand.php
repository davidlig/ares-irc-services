<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\Out\User\IrcNetworkUserMapper;
use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\Service\NickServClientKeyResolver;
use App\NickServ\Application\UseCase\Identify\IdentifyNick;
use App\NickServ\Application\UseCase\Identify\IdentifyNickHandlerInterface;
use App\NickServ\Application\UseCase\Identify\IdentifyNickOutcome;
use App\NickServ\Application\UseCase\Identify\IdentifyNickResult;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function ceil;
use function strcasecmp;

final readonly class IdentifyCommand implements NickServCommandInterface
{
    public function __construct(
        private IdentifyNickHandlerInterface $handler,
        private NetworkUserLookupPort $userLookup,
        private PendingNickRestoreRegistryInterface $pendingRegistry,
        private NickServClientKeyResolver $clientKeyResolver,
        private NicknameAuthenticationModeQuery $authenticationMode,
    ) {}

    public function getName(): string
    {
        return 'IDENTIFY';
    }

    public function getAliases(): array
    {
        return ['ID'];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        if ($this->usesNativeAuthentication()) {
            return new CommandArgumentDefinition('identify.native_syntax');
        }

        return new CommandArgumentDefinition('identify.syntax', [
            new Argument('nickname'),
            new Argument('password'),
        ]);
    }

    public function getMinArgs(): int
    {
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    public function getHelpKey(): string
    {
        return $this->usesNativeAuthentication() ? 'identify.native_help' : 'identify.help';
    }

    public function getExampleKey(): string
    {
        return $this->usesNativeAuthentication() ? 'identify.native_example' : 'identify.example';
    }

    public function getOrder(): int
    {
        return 2;
    }

    public function getShortDescKey(): string
    {
        return $this->usesNativeAuthentication() ? 'identify.native_short' : 'identify.short';
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

    public function execute(NickServContext $context): null
    {
        $sender = $context->sender;
        if (null === $sender) {
            return null;
        }

        if ($this->usesNativeAuthentication()) {
            $context->replyNativeAuthentication();

            return null;
        }

        $targetNick = $context->args[0];
        $password = $context->args[1];
        $clientKey = $this->clientKeyResolver->getClientKey(IrcNetworkUserMapper::map($sender));

        $result = $this->handler->handle(new IdentifyNick(
            nickname: $targetNick,
            password: $password,
            clientKey: $clientKey,
            senderUid: $sender->uid,
            senderNick: $sender->nick,
            senderIsIdentified: $sender->isIdentified,
        ));

        $this->present($context, $result, $sender);

        return null;
    }

    private function usesNativeAuthentication(): bool
    {
        return NicknameAuthenticationMode::NativeNick === $this->authenticationMode->current();
    }

    private function present(NickServContext $context, IdentifyNickResult $result, SenderView $sender): void
    {
        switch ($result->outcome) {
            case IdentifyNickOutcome::Success:
                $this->handleSuccess($context, $result, $sender, $result->requireNickname());
                break;
            case IdentifyNickOutcome::AlreadyIdentified:
                $context->replyEmphasized('identify.already_identified', ['nickname' => $result->nickname], Role::Information, ['nickname']);
                break;
            case IdentifyNickOutcome::LockedOut:
                $context->replyEmphasized('identify.locked_out', [
                    'minutes' => (string) (int) ceil($result->retryAfterSeconds / 60),
                ], Role::Error, ['minutes']);
                break;
            case IdentifyNickOutcome::NotRegistered:
                $context->replyMissingNick('error.nick_not_registered', $result->nickname ?? '');
                break;
            case IdentifyNickOutcome::Pending:
                $context->replyIdentifyPending($result->nickname);
                break;
            case IdentifyNickOutcome::Suspended:
                $context->replyEmphasized('identify.suspended', [
                    'nickname' => $result->nickname,
                    'reason' => $result->reason ?? '',
                ], Role::Error, ['nickname']);
                break;
            case IdentifyNickOutcome::Forbidden:
                $context->replyEmphasized('identify.forbidden', ['nickname' => $result->nickname], Role::Error, ['nickname']);
                break;
            case IdentifyNickOutcome::PendingDeletion:
                $context->replyEmphasized('identify.pending_deletion', ['nickname' => $result->nickname], Role::Error, ['nickname']);
                break;
            case IdentifyNickOutcome::InvalidCredentials:
                $context->replyEmphasized('identify.invalid_credentials', [], Role::Error, []);
                break;
        }
    }

    private function handleSuccess(NickServContext $context, IdentifyNickResult $result, SenderView $sender, string $nickname): void
    {
        $currentHolder = $this->userLookup->findByNick($nickname);
        if (null !== $currentHolder && $sender->uid !== $currentHolder->uid) {
            $killReason = $context->transIn(
                'identify.kill_reason',
                ['nickname' => $nickname, 'source' => $sender->nick],
                $result->accountLanguage ?? '',
            );
            $context->getNotifier()->killUser($currentHolder->uid, $killReason);
            $context->replyEmphasized('identify.ghost_released', ['nickname' => $nickname], Role::Information, ['nickname']);
        }

        if (0 !== strcasecmp($sender->nick, $nickname) || $this->pendingRegistry->peek($sender->uid)) {
            $context->getNotifier()->forceNick($sender->uid, $nickname);
        }

        $context->getNotifier()->setUserAccount($sender->uid, $nickname);

        if (null !== $result->displayVhost && '' !== $result->displayVhost) {
            $context->getNotifier()->setUserVhost($sender->uid, $result->displayVhost, $sender->serverSid);
        }

        $context->replyEmphasized('identify.success', ['nickname' => $nickname], Role::Success, ['nickname']);
    }
}
