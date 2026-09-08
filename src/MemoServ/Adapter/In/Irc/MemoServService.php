<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\PublishedEvent\CommandExecutedEvent;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Domain\Exception\MemoDisabledException;
use App\Shared\Application\Port\EventBusInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function count;
use function is_string;
use function sprintf;
use function strtoupper;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Routes a raw command string (from a PRIVMSG to MemoServ) to the correct command handler.
 */
final readonly class MemoServService
{
    public function __construct(
        private MemoServCommandRegistry $commandRegistry,
        private MemoUserAccountPort $userAccountPort,
        private MemoServUserPresentationPreferences $languageResolver,
        private MemoServNotifierInterface $notifier,
        private MemoServUserPresentationPreferences $messageTypeResolver,
        private TranslationInterface $translator,
        private ServiceNicknameRegistry $serviceNicks,
        private MemoAuthorizationContextInterface $authorizationContext,
        private MemoAuthorizationCheckerInterface $authorizationChecker,
        private EventBusInterface $eventDispatcher,
        private string $defaultLanguage = 'en',
        private string $defaultTimezone = 'UTC',
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param string     $rawText Full text of the PRIVMSG (e.g. "SEND nick message")
     * @param SenderView $sender  The user who sent the message (from NetworkUserLookupPort)
     */
    public function dispatch(string $rawText, SenderView $sender): void
    {
        $parts = preg_split('/\s+/', trim($rawText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $cmdPart = array_shift($parts);
        $cmdName = strtoupper(is_string($cmdPart) ? $cmdPart : '');
        /** @var list<string> $args */
        $args = $parts;

        if ('' === $cmdName) {
            return;
        }

        $handler = $this->commandRegistry->find($cmdName);

        if (null === $handler) {
            $messageType = $this->messageTypeResolver->prefersPrivateMessages($sender->nick) ? 'PRIVMSG' : 'NOTICE';
            $this->notifier->sendMessage(
                $sender->uid,
                $this->translator->trans('unknown_command', ['%command%' => $cmdName, '%bot%' => $this->notifier->getNick()], 'memoserv', $this->defaultLanguage),
                $messageType
            );

            return;
        }

        $account = $this->userAccountPort->findAccountByNick($sender->nick);
        $language = $this->languageResolver->languageFor($sender->uid, $sender->nick, $account?->language);
        $timezone = $this->defaultTimezone;
        $messageType = $this->messageTypeResolver->prefersPrivateMessages($sender->nick) ? 'PRIVMSG' : 'NOTICE';

        $context = new MemoServContext(
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
            serviceNicks: $this->serviceNicks,
        );

        $this->authorizationContext->setCurrentUser($sender->uid, $sender->isIdentified, $sender->isOper);
        $this->dispatchToHandler($context, $handler, $sender, $cmdName);
    }

    private function dispatchToHandler(MemoServContext $context, MemoServCommandInterface $handler, SenderView $sender, string $cmdName): void
    {
        try {
            $requiredPermission = $handler->getRequiredPermission();
            if (null !== $requiredPermission && !$this->authorizationChecker->isGranted($requiredPermission, $context)) {
                $context->reply('IDENTIFIED' === $requiredPermission ? 'error.not_identified' : 'error.permission_denied');

                return;
            }

            if (count($context->args) < $handler->getMinArgs()) {
                $context->reply('error.syntax', [
                    'syntax' => $context->trans($handler->getSyntaxKey()),
                ]);

                return;
            }

            $this->logger->debug(sprintf(
                'MemoServ: %s executed %s [args: %d]',
                $sender->nick,
                $cmdName,
                count($context->args),
            ));

            $result = $handler->execute($context);
            $this->eventDispatcher->dispatch(new CommandExecutedEvent(
                command: $handler,
                serviceName: $this->notifier->getServiceKey(),
                operatorNick: $sender->nick,
                commandName: $cmdName,
                permission: $requiredPermission,
                outcome: $result instanceof CommandOutcome ? $result : null,
            ));
        } catch (MemoDisabledException $e) {
            $context->reply('send.service_disabled_for_target', ['target' => $e->target]);
        } catch (Throwable $e) {
            $this->logger->error('MemoServ dispatch error: ' . $e->getMessage(), [
                'exception' => $e,
                'sender' => $sender->uid,
            ]);
            throw $e;
        } finally {
            $this->authorizationContext->clear();
        }
    }
}
