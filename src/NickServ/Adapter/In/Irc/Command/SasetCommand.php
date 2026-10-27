<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Model\NickOperationActor;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Set\SetNickSetting;
use App\NickServ\Application\UseCase\Set\SetNickSettingHandlerInterface;
use App\NickServ\Application\UseCase\Set\SetNickSettingOption;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;

use function array_keys;
use function array_map;
use function array_slice;
use function base64_decode;
use function implode;
use function inet_ntop;
use function sprintf;
use function strtolower;
use function strtoupper;

final readonly class SasetCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private SetNickSettingHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'SASET';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('saset.syntax', [
            new Argument('nickname'),
            new Argument('option'),
        ], [
            'PASSWORD' => new CommandArgumentDefinition('saset.password.syntax', [new Argument('value', rest: true)]),
            'EMAIL' => new CommandArgumentDefinition('saset.email.syntax', [new Argument('value', rest: true)]),
            'LANGUAGE' => new CommandArgumentDefinition('saset.language.syntax', [new Argument('value', rest: true)]),
            'TIMEZONE' => new CommandArgumentDefinition('saset.timezone.syntax', [new Argument('value', rest: true)]),
            'PRIVATE' => new CommandArgumentDefinition('saset.private.syntax', [new Argument('value', rest: true)]),
            'MSG' => new CommandArgumentDefinition('saset.msg.syntax', [new Argument('value', rest: true)]),
            'VHOST' => new CommandArgumentDefinition('saset.vhost.syntax', [new Argument('value', optional: true, rest: true)]),
        ], unknownVariant: new CommandArgumentDefinition('saset.syntax', [new Argument('value', rest: true)]));
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
        return 'saset.help';
    }

    public function getExampleKey(): string
    {
        return 'saset.example';
    }

    public function getOrder(): int
    {
        return 5;
    }

    public function getShortDescKey(): string
    {
        return 'saset.short';
    }

    public function getSubCommandHelp(): array
    {
        $options = array_keys($this->getArgumentDefinition()->variants);

        return array_map(static fn (string $option): array => [
            'name' => $option,
            'desc_key' => 'saset.' . strtolower($option) . '.short',
            'help_key' => 'saset.' . strtolower($option) . '.help',
            'syntax_key' => 'saset.' . strtolower($option) . '.syntax',
            'example_key' => 'saset.' . strtolower($option) . '.example',
        ], $options);
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return NickServPermission::SASET;
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

        $targetNickname = $context->args[0];
        $rawOption = strtoupper($context->args[1]);
        $option = SetNickSettingOption::tryFrom($rawOption);
        if (null === $option) {
            $context->reply('saset.unknown_option', [
                'option' => $rawOption,
                'options' => implode(', ', array_keys($this->getArgumentDefinition()->variants)),
            ]);

            return CommandOutcome::rejected();
        }

        $value = implode(' ', array_slice($context->args, 2));
        $result = $this->handler->handle(new SetNickSetting(
            new NickOperationActor(
                $sender->nick,
                $context->senderAccount?->getId(),
                $sender->uid,
                $sender->serverSid,
                sprintf('%s@%s', $sender->ident, $sender->hostname),
                self::decodeIp($sender->ipBase64),
            ),
            $targetNickname,
            $option,
            $value,
            true,
            $context->getLanguage(),
        ));

        if (!SetNickSettingPresentation::present($context, $result)) {
            return CommandOutcome::rejected();
        }

        return CommandOutcome::success(new IrcopAuditData(
            target: $targetNickname,
            extra: ['option' => $rawOption, 'value' => match ($option) {
                SetNickSettingOption::Password => null,
                SetNickSettingOption::Email => $result->value,
                default => $value,
            }],
        ));
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
