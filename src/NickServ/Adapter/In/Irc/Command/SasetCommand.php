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

use function array_slice;
use function count;
use function sprintf;

final readonly class SasetCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    private const array SUPPORTED_OPTIONS = ['PASSWORD', 'EMAIL', 'LANGUAGE', 'TIMEZONE', 'PRIVATE', 'MSG', 'VHOST'];

    public function __construct(private SetNickSettingHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'SASET';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 3;
    }

    public function getSyntaxKey(): string
    {
        return 'saset.syntax';
    }

    public function getHelpKey(): string
    {
        return 'saset.help';
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
        return array_map(static fn (string $option): array => [
            'name' => $option,
            'desc_key' => 'saset.' . strtolower($option) . '.short',
            'help_key' => 'saset.' . strtolower($option) . '.help',
            'syntax_key' => 'saset.' . strtolower($option) . '.syntax',
        ], self::SUPPORTED_OPTIONS);
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

        if (count($context->args) < 3) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return CommandOutcome::rejected();
        }

        $targetNickname = $context->args[0];
        $rawOption = strtoupper($context->args[1]);
        $option = SetNickSettingOption::tryFrom($rawOption);
        if (null === $option) {
            $context->reply('saset.unknown_option', [
                'option' => $rawOption,
                'options' => implode(', ', self::SUPPORTED_OPTIONS),
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
            extra: ['option' => $rawOption, 'value' => SetNickSettingOption::Password === $option ? null : $value],
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
