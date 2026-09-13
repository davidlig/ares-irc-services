<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
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

final readonly class SetCommand implements NickServCommandInterface
{
    private const array SUPPORTED_OPTIONS = ['PASSWORD', 'EMAIL', 'LANGUAGE', 'TIMEZONE', 'PRIVATE', 'MSG', 'VHOST'];

    public function __construct(private SetNickSettingHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'SET';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'set.syntax';
    }

    public function getHelpKey(): string
    {
        return 'set.help';
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
        return array_map(static fn (string $option): array => [
            'name' => $option,
            'desc_key' => 'set.' . strtolower($option) . '.short',
            'help_key' => 'set.' . strtolower($option) . '.help',
            'syntax_key' => 'set.' . strtolower($option) . '.syntax',
        ], self::SUPPORTED_OPTIONS);
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

        if (count($context->args) < 2 && !$this->isBareVhostClear($context->args)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return CommandOutcome::rejected();
        }

        $rawOption = strtoupper($context->args[0]);
        $option = SetNickSettingOption::tryFrom($rawOption);
        if (null === $option) {
            $context->reply('set.unknown_option', [
                'option' => $rawOption,
                'options' => implode(', ', self::SUPPORTED_OPTIONS),
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
        ));

        return SetNickSettingPresentation::present($context, $result)
            ? CommandOutcome::success()
            : CommandOutcome::rejected();
    }

    /** @param array<string> $args */
    private function isBareVhostClear(array $args): bool
    {
        return 1 === count($args) && SetNickSettingOption::Vhost->value === strtoupper($args[0]);
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
