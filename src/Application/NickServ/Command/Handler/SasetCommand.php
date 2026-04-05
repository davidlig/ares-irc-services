<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickProtectabilityStatus;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

use function array_slice;
use function count;
use function implode;
use function in_array;
use function strtoupper;

/**
 * SASET <nickname> <option> <value>.
 *
 * IRCop-only command to modify another user's NickServ settings.
 * Requires 'nickserv.saset' permission.
 * Target cannot be Root, IRCop, or Service nickname.
 */
final class SasetCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private const array SUPPORTED_OPTIONS = ['PASSWORD', 'EMAIL', 'LANGUAGE', 'TIMEZONE', 'PRIVATE', 'MSG', 'VHOST'];

    private const string PASSWORD_OPTION = 'PASSWORD';

    /** @var array<string, SetOptionHandlerInterface> */
    private array $handlers;

    private ?IrcopAuditData $auditData = null;

    public function __construct(
        SetPasswordHandler $setPasswordHandler,
        SetEmailHandler $setEmailHandler,
        SetLanguageHandler $setLanguageHandler,
        SetPrivateHandler $setPrivateHandler,
        SetMsgHandler $setMsgHandler,
        SetTimezoneHandler $setTimezoneHandler,
        SetVhostHandler $setVhostHandler,
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickTargetValidator $targetValidator,
    ) {
        $this->handlers = [
            'PASSWORD' => $setPasswordHandler,
            'EMAIL' => $setEmailHandler,
            'LANGUAGE' => $setLanguageHandler,
            'PRIVATE' => $setPrivateHandler,
            'MSG' => $setMsgHandler,
            'TIMEZONE' => $setTimezoneHandler,
            'VHOST' => $setVhostHandler,
        ];
    }

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
        return [
            [
                'name' => 'PASSWORD',
                'desc_key' => 'saset.password.short',
                'help_key' => 'saset.password.help',
                'syntax_key' => 'saset.password.syntax',
            ],
            [
                'name' => 'EMAIL',
                'desc_key' => 'saset.email.short',
                'help_key' => 'saset.email.help',
                'syntax_key' => 'saset.email.syntax',
            ],
            [
                'name' => 'LANGUAGE',
                'desc_key' => 'saset.language.short',
                'help_key' => 'saset.language.help',
                'syntax_key' => 'saset.language.syntax',
            ],
            [
                'name' => 'TIMEZONE',
                'desc_key' => 'saset.timezone.short',
                'help_key' => 'saset.timezone.help',
                'syntax_key' => 'saset.timezone.syntax',
            ],
            [
                'name' => 'PRIVATE',
                'desc_key' => 'saset.private.short',
                'help_key' => 'saset.private.help',
                'syntax_key' => 'saset.private.syntax',
            ],
            [
                'name' => 'MSG',
                'desc_key' => 'saset.msg.short',
                'help_key' => 'saset.msg.help',
                'syntax_key' => 'saset.msg.syntax',
            ],
            [
                'name' => 'VHOST',
                'desc_key' => 'saset.vhost.short',
                'help_key' => 'saset.vhost.help',
                'syntax_key' => 'saset.vhost.syntax',
            ],
        ];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'nickserv.saset';
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        if (count($context->args) < 3) {
            $context->reply('error.syntax', [
                'syntax' => $context->trans($this->getSyntaxKey()),
            ]);

            return;
        }

        $targetNick = $context->args[0];
        $option = strtoupper($context->args[1]);
        $value = implode(' ', array_slice($context->args, 2));

        if (!in_array($option, self::SUPPORTED_OPTIONS, true)) {
            $context->reply('saset.unknown_option', [
                'option' => $option,
                'options' => implode(', ', self::SUPPORTED_OPTIONS),
            ]);

            return;
        }

        $protectability = $this->targetValidator->validate($targetNick);

        if (!$protectability->isAllowed()) {
            $this->replyProtectabilityError($context, $protectability);

            return;
        }

        $targetAccount = $protectability->account;
        if (null === $targetAccount) {
            $context->reply('saset.not_registered', ['%nickname%' => $targetNick]);

            return;
        }

        $handler = $this->handlers[$option] ?? null;
        // @codeCoverageIgnoreStart
        if (null === $handler) {
            $context->reply('saset.unknown_option', [
                'option' => $option,
                'options' => implode(', ', self::SUPPORTED_OPTIONS),
            ]);

            return;
        }
        // @codeCoverageIgnoreEnd

        $handler->handle($context, $targetAccount, $value, true);

        $auditValue = self::PASSWORD_OPTION === $option ? null : $value;
        $this->auditData = new IrcopAuditData(
            target: $targetNick,
            extra: ['option' => $option, 'value' => $auditValue],
        );
    }

    private function replyProtectabilityError(NickServContext $context, NickProtectabilityResult $result): void
    {
        match ($result->status) {
            NickProtectabilityStatus::IsRoot => $context->reply('saset.cannot_modify_oper', ['%nickname%' => $result->nickname]),
            NickProtectabilityStatus::IsIrcop => $context->reply('saset.cannot_modify_oper', ['%nickname%' => $result->nickname]),
            NickProtectabilityStatus::IsService => $context->reply('saset.cannot_modify_oper', ['%nickname%' => $result->nickname]),
            // @codeCoverageIgnoreStart
            NickProtectabilityStatus::Allowed => null,
            // @codeCoverageIgnoreEnd
        };
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
