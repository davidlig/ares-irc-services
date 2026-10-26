<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Model\NickOperationActor;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\Service\NickServClientKeyResolver;
use App\NickServ\Application\UseCase\Set\SetNickSetting;
use App\NickServ\Application\UseCase\Set\SetNickSettingHandlerInterface;
use App\NickServ\Application\UseCase\Set\SetNickSettingOption;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;

use function array_slice;
use function sprintf;

final readonly class SetCommand implements NickServCommandInterface
{
    public function __construct(private SetNickSettingHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'SET';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('set.syntax', [new Argument('option')], [
            'PASSWORD' => new CommandArgumentDefinition('set.password.syntax', [new Argument('value', rest: true)]),
            'EMAIL' => new CommandArgumentDefinition('set.email.syntax', [new Argument('value', rest: true)]),
            'LANGUAGE' => new CommandArgumentDefinition('set.language.syntax', [new Argument('value', rest: true)]),
            'TIMEZONE' => new CommandArgumentDefinition('set.timezone.syntax', [new Argument('value', rest: true)]),
            'PRIVATE' => new CommandArgumentDefinition('set.private.syntax', [new Argument('value', rest: true)]),
            'MSG' => new CommandArgumentDefinition('set.msg.syntax', [new Argument('value', rest: true)]),
            'VHOST' => new CommandArgumentDefinition('set.vhost.syntax', [new Argument('value', optional: true, rest: true)]),
        ], unknownVariant: new CommandArgumentDefinition('set.syntax', [new Argument('value', rest: true)]));
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
        return 'set.help';
    }

    public function getExampleKey(): string
    {
        return 'set.example';
    }

    public function getOrder(): int
    {
        return 4;
    }

    public function getShortDescKey(): string
    {
        return 'set.short';
    }

    public function getSubCommandHelp(): array
    {
        $options = array_keys($this->getArgumentDefinition()->variants);

        return array_map(static fn (string $option): array => [
            'name' => $option,
            'desc_key' => 'set.' . strtolower($option) . '.short',
            'help_key' => 'set.' . strtolower($option) . '.help',
            'syntax_key' => 'set.' . strtolower($option) . '.syntax',
            'example_key' => 'set.' . strtolower($option) . '.example',
        ], $options);
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return NickServPermission::IDENTIFIED_OWNER;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        $sender = $context->sender;
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $validation = CommandArgumentValidator::validate($this->getArgumentDefinition(), $context->args);
        if (null !== $validation->syntaxKey) {
            $context->reply('error.syntax', ['syntax' => $context->trans($validation->syntaxKey)]);

            return CommandOutcome::rejected();
        }

        $rawOption = strtoupper($context->args[0]);
        $option = SetNickSettingOption::tryFrom($rawOption);
        if (null === $option) {
            $context->reply('set.unknown_option', [
                'option' => $rawOption,
                'options' => implode(', ', array_keys($this->getArgumentDefinition()->variants)),
            ]);

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new SetNickSetting(
            new NickOperationActor(
                $sender->nick,
                $context->senderAccount?->getId(),
                $sender->uid,
                $sender->serverSid,
                sprintf('%s@%s', $sender->ident, $sender->hostname),
                self::decodeIp($sender->ipBase64),
            ),
            $context->senderAccount?->getNickname() ?? $sender->nick,
            $option,
            implode(' ', array_slice($context->args, 1)),
            false,
            $context->getLanguage(),
            clientKey: new NickServClientKeyResolver()->getClientKey(new NetworkUser($sender->uid, $sender->nick, $sender->ident, $sender->hostname, $sender->cloakedHost, $sender->ipBase64)),
        ));

        return SetNickSettingPresentation::present($context, $result)
            ? CommandOutcome::success()
            : CommandOutcome::rejected();
    }

    private static function decodeIp(string $ipBase64): string
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
