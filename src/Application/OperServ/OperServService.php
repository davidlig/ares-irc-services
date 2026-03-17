<?php

declare(strict_types=1);

namespace App\Application\OperServ;

use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\Port\SenderView;
use App\Application\Port\UserMessageTypeResolverInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
        );

        if ($handler->isOperOnly() && !$this->isOper($sender, $account)) {
            $context->reply('error.oper_only');

            return;
        }

        $requiredPermission = $handler->getRequiredPermission();
        if (null !== $requiredPermission) {
            if (!$context->isRoot() && !$this->accessHelper->hasPermission(
                $account?->getId() ?? 0,
                $sender->nick,
                $requiredPermission
            )) {
                $context->reply('error.permission_denied');

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
    }

    private function isOper(SenderView $sender, ?RegisteredNick $account): bool
    {
        if ($sender->isOper) {
            return true;
        }

        if (null === $account) {
            return false;
        }

        return $this->accessHelper->isRoot($sender->nick)
            || null !== $this->accessHelper->getIrcopByNickId($account->getId());
    }
}
