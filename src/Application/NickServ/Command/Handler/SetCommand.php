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
 * SET [<nickname>] <option> <value>.
 *
 * Allows a registered and identified user to change their NickServ settings.
 * IRCops with 'nickserv.set' permission can modify other users' settings.
 * Delegates each option to a dedicated Set*Handler.
 *
 * Owner syntax:
 *   SET PASSWORD <new_password>
 *   SET EMAIL    <new_email>              — request change (token sent to current email)
 *   SET EMAIL    <new_email> <token>      — confirm change with token
 *   SET LANGUAGE <code>       (en | es | …)
 *   SET PRIVATE  ON|OFF
 *   SET MSG      ON|OFF   (ON = PRIVMSG, OFF = NOTICE)
 *   SET VHOST    <vhost>|OFF
 *   SET TIMEZONE <timezone>|OFF
 *
 * IRCop syntax:
 *   SET <nickname> <option> <value>
 *   - Password changed directly without email
 *   - Email changed directly without token validation
 *   - Cannot modify Root or IRCop nicknames
 */
final class SetCommand implements NickServCommandInterface, AuditableCommandInterface
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
        return [
            [
                'name' => 'PASSWORD',
                'desc_key' => 'set.password.short',
                'help_key' => 'set.password.help',
                'syntax_key' => 'set.password.syntax',
            ],
            [
                'name' => 'EMAIL',
                'desc_key' => 'set.email.short',
                'help_key' => 'set.email.help',
                'syntax_key' => 'set.email.syntax',
            ],
            [
                'name' => 'LANGUAGE',
                'desc_key' => 'set.language.short',
                'help_key' => 'set.language.help',
                'syntax_key' => 'set.language.syntax',
            ],
            [
                'name' => 'TIMEZONE',
                'desc_key' => 'set.timezone.short',
                'help_key' => 'set.timezone.help',
                'syntax_key' => 'set.timezone.syntax',
            ],
            [
                'name' => 'PRIVATE',
                'desc_key' => 'set.private.short',
                'help_key' => 'set.private.help',
                'syntax_key' => 'set.private.syntax',
            ],
            [
                'name' => 'MSG',
                'desc_key' => 'set.msg.short',
                'help_key' => 'set.msg.help',
                'syntax_key' => 'set.msg.syntax',
            ],
            [
                'name' => 'VHOST',
                'desc_key' => 'set.vhost.short',
                'help_key' => 'set.vhost.help',
                'syntax_key' => 'set.vhost.syntax',
            ],
        ];
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
        if (null === $context->sender) {
            return;
        }

        $mode = $this->detectMode($context->args);

        if ('error' === $mode['mode']) {
            $context->reply('set.unknown_option', [
                'option' => $mode['option'] ?? '',
                'options' => implode(', ', self::SUPPORTED_OPTIONS),
            ]);

            return;
        }

        if ('owner' === $mode['mode']) {
            $this->executeOwnerMode($context, $mode);

            return;
        }

        $this->executeIrcopMode($context, $mode);
    }

    /**
     * @param array<string> $args
     *
     * @return array{mode: string, option?: string, value?: string, targetNick?: string, error?: string}
     */
    private function detectMode(array $args): array
    {
        if (0 === count($args)) {
            return ['mode' => 'error', 'option' => ''];
        }

        $first = strtoupper($args[0]);

        // Si el primer argumento es una opción válida → modo propietario
        if (in_array($first, self::SUPPORTED_OPTIONS, true)) {
            return [
                'mode' => 'owner',
                'option' => $first,
                'value' => implode(' ', array_slice($args, 1)),
            ];
        }

        // Si hay al menos 2 argumentos y el segundo es una opción válida → modo IRCop
        if (count($args) >= 2) {
            $second = strtoupper($args[1]);
            if (in_array($second, self::SUPPORTED_OPTIONS, true)) {
                return [
                    'mode' => 'ircop',
                    'targetNick' => $args[0],
                    'option' => $second,
                    'value' => implode(' ', array_slice($args, 2)),
                ];
            }
        }

        return ['mode' => 'error', 'option' => $first];
    }

    /**
     * @param array{mode: string, option: string, value: string} $mode
     */
    private function executeOwnerMode(NickServContext $context, array $mode): void
    {
        $account = $context->senderAccount;
        if (null === $account) {
            $context->reply('error.not_identified');

            return;
        }

        $handler = $this->handlers[$mode['option']] ?? null;
        // @codeCoverageIgnoreStart
        if (null === $handler) {
            $context->reply('set.unknown_option', [
                'option' => $mode['option'],
                'options' => implode(', ', self::SUPPORTED_OPTIONS),
            ]);

            return;
        }
        // @codeCoverageIgnoreEnd

        $handler->handle($context, $account, $mode['value'], false);

        // Owner mode does not generate audit data
        $this->auditData = null;
    }

    /**
     * @param array{mode: string, targetNick: string, option: string, value: string} $mode
     */
    private function executeIrcopMode(NickServContext $context, array $mode): void
    {
        $targetNick = $mode['targetNick'];
        $protectability = $this->targetValidator->validate($targetNick);

        if (!$protectability->isAllowed()) {
            $this->replyProtectabilityError($context, $protectability);

            return;
        }

        $targetAccount = $protectability->account;
        if (null === $targetAccount) {
            $context->reply('set.not_registered_ircop', ['%nickname%' => $targetNick]);

            return;
        }

        $handler = $this->handlers[$mode['option']] ?? null;
        // @codeCoverageIgnoreStart
        if (null === $handler) {
            $context->reply('set.unknown_option', [
                'option' => $mode['option'],
                'options' => implode(', ', self::SUPPORTED_OPTIONS),
            ]);

            return;
        }
        // @codeCoverageIgnoreEnd

        $handler->handle($context, $targetAccount, $mode['value'], true);

        // Generate audit data for IRCop mode
        $auditValue = self::PASSWORD_OPTION === $mode['option'] ? null : $mode['value'];
        $this->auditData = new IrcopAuditData(
            target: $targetNick,
            extra: ['option' => $mode['option'], 'value' => $auditValue],
        );
    }

    private function replyProtectabilityError(NickServContext $context, NickProtectabilityResult $result): void
    {
        match ($result->status) {
            NickProtectabilityStatus::IsRoot => $context->reply('set.cannot_modify_oper', ['%nickname%' => $result->nickname]),
            NickProtectabilityStatus::IsIrcop => $context->reply('set.cannot_modify_oper', ['%nickname%' => $result->nickname]),
            NickProtectabilityStatus::IsService => $context->reply('set.cannot_modify_oper', ['%nickname%' => $result->nickname]),
            // @codeCoverageIgnoreStart
            NickProtectabilityStatus::Allowed => null, // Defensive: never called with Allowed
            // @codeCoverageIgnoreEnd
        };
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
