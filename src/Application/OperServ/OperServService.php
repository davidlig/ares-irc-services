<?php

declare(strict_types=1);

namespace App\Application\OperServ;

use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\AuditableCommandInterface;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\AuthorizationContextInterface;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\Port\SenderView;
use App\Application\Port\UserMessageTypeResolverInterface;
use App\Domain\IRC\Event\IrcopCommandExecutedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;
use function sprintf;

use const PREG_SPLIT_NO_EMPTY;

final readonly class OperServService
{
    public function __construct(
        private OperServCommandRegistry $commandRegistry,
        private RegisteredNickRepositoryInterface $nickRepository,
        private OperServNotifierInterface $notifier,
        private UserMessageTypeResolverInterface $messageTypeResolver,
        private TranslatorInterface $translator,
        private IrcopAccessHelper $accessHelper,
        private ServiceNicknameRegistry $serviceNicks,
        private AuthorizationContextInterface $authorizationContext,
        private AuthorizationCheckerInterface $authorizationChecker,
        private EventDispatcherInterface $eventDispatcher,
        private string $defaultLanguage = 'en',
        private string $defaultTimezone = 'UTC',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function dispatch(string $rawText, SenderView $sender): void
    {
        $parts = preg_split('/\s+/', trim($rawText), -1, PREG_SPLIT_NO_EMPTY);
        $cmdName = strtoupper(array_shift($parts) ?? '');
        $args = $parts;

        if ('' === $cmdName) {
            return;
        }

        $handler = $this->commandRegistry->find($cmdName);

        if (null === $handler) {
            $messageType = $this->messageTypeResolver->resolve($sender);
            $this->notifier->sendMessage(
                $sender->uid,
                $this->translator->trans('unknown_command', ['%command%' => $cmdName, '%bot%' => $this->notifier->getNick()], 'operserv', $this->defaultLanguage),
                $messageType
            );

            return;
        }

        $account = $this->nickRepository->findByNick($sender->nick);
        $language = $account?->getLanguage() ?? $this->defaultLanguage;
        $timezone = $account?->getTimezone() ?? $this->defaultTimezone;
        $messageType = $this->messageTypeResolver->resolve($sender);

        $context = new OperServContext(
            sender: $sender,
            senderAccount: $account,
            command: $cmdName,
            args: $args,
            notifier: $this->notifier,
            translator: $this->translator,
            language: $language,
            timezone: $timezone,
            messageType: $messageType,
            registry: $this->commandRegistry,
            accessHelper: $this->accessHelper,
            serviceNicks: $this->serviceNicks,
        );

        $this->authorizationContext->setCurrentUser($sender);

        try {
            $requiredPermission = $handler->getRequiredPermission();
            if (null !== $requiredPermission) {
                $isGranted = $this->authorizationChecker->isGranted($requiredPermission, $context);
                $this->logger->debug('OperServ authorization check', [
                    'nick' => $sender->nick,
                    'isIdentified' => $sender->isIdentified,
                    'isOper' => $sender->isOper,
                    'permission' => $requiredPermission,
                    'isGranted' => $isGranted,
                ]);
                if (!$isGranted) {
                    if ('IDENTIFIED' === $requiredPermission) {
                        $context->reply('error.not_identified');
                    } else {
                        $context->reply('error.permission_denied');
                    }

                    return;
                }
            }

            if (count($args) < $handler->getMinArgs()) {
                $context->reply('error.syntax', [
                    '%syntax%' => $context->trans($handler->getSyntaxKey()),
                ]);

                return;
            }

            $this->logger->debug(sprintf(
                'OperServ: %s executed %s [args: %d]',
                $sender->nick,
                $cmdName,
                count($args),
            ));

            $handler->execute($context);

            if (null !== $requiredPermission) {
                $auditData = $handler instanceof AuditableCommandInterface
                    ? $handler->getAuditData($context)
                    : null;

                $this->eventDispatcher->dispatch(new IrcopCommandExecutedEvent(
                    operatorNick: $sender->nick,
                    commandName: $cmdName,
                    permission: $requiredPermission,
                    target: $auditData?->target,
                    targetHost: $auditData?->targetHost,
                    targetIp: $auditData?->targetIp,
                    reason: $auditData?->reason,
                    extra: $auditData?->extra ?? [],
                ));
            }
        } finally {
            $this->authorizationContext->clear();
        }
    }
}
