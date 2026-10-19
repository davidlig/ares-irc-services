<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\UseCase\ManageEmailVerification\EmailVerificationAction;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerification;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerificationHandlerInterface;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerificationOutcome;
use App\OperServ\Application\UseCase\ManageEmailVerification\ManageEmailVerificationResult;
use DateTimeImmutable;

use function strtoupper;

/**
 * EMAILVERIFY ON|OFF|STATUS; IRC parsing and presentation only.
 *
 * The legacy dBoTs3 package exposed the same switch as VREG, kept here as an alias.
 */
final readonly class EmailVerifyCommand implements OperServCommandInterface
{
    private const string ACTION_ON = 'ON';

    private const string ACTION_OFF = 'OFF';

    private const string ACTION_STATUS = 'STATUS';

    public function __construct(private ManageEmailVerificationHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'EMAILVERIFY';
    }

    public function getAliases(): array
    {
        return ['VREG'];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'emailverify.syntax';
    }

    public function getHelpKey(): string
    {
        return 'emailverify.help';
    }

    public function getExampleKey(): string
    {
        return 'emailverify.example';
    }

    public function getOrder(): int
    {
        return 45;
    }

    public function getShortDescKey(): string
    {
        return 'emailverify.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            [
                'name' => self::ACTION_ON,
                'desc_key' => 'emailverify.on.short',
                'help_key' => 'emailverify.on.help',
                'syntax_key' => 'emailverify.on.syntax',
                'example_key' => 'emailverify.on.example',
            ],
            [
                'name' => self::ACTION_OFF,
                'desc_key' => 'emailverify.off.short',
                'help_key' => 'emailverify.off.help',
                'syntax_key' => 'emailverify.off.syntax',
                'example_key' => 'emailverify.off.example',
            ],
            [
                'name' => self::ACTION_STATUS,
                'desc_key' => 'emailverify.status.short',
                'help_key' => 'emailverify.status.help',
                'syntax_key' => 'emailverify.status.syntax',
                'example_key' => 'emailverify.status.example',
            ],
        ];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return OperatorAuthorizationAttribute::ROOT;
    }

    public function execute(OperServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $action = match (strtoupper($context->args[0] ?? '')) {
            self::ACTION_ON => EmailVerificationAction::Enable,
            self::ACTION_OFF => EmailVerificationAction::Disable,
            self::ACTION_STATUS => EmailVerificationAction::Status,
            default => EmailVerificationAction::Unknown,
        };

        $result = $this->handler->handle(new ManageEmailVerification(
            action: $action,
            actor: $context->sender->nick,
            actorAccountId: $context->senderAccountId(),
            occurredAt: new DateTimeImmutable(),
        ));

        $this->present($context, $result);
    }

    private function present(OperServContext $context, ManageEmailVerificationResult $result): void
    {
        match ($result->outcome) {
            ManageEmailVerificationOutcome::Enabled => $context->reply('emailverify.enabled'),
            ManageEmailVerificationOutcome::Disabled => $context->reply('emailverify.disabled'),
            ManageEmailVerificationOutcome::AlreadyEnabled => $context->reply('emailverify.already_enabled'),
            ManageEmailVerificationOutcome::AlreadyDisabled => $context->reply('emailverify.already_disabled'),
            ManageEmailVerificationOutcome::StatusReported => $context->reply(
                $result->required ? 'emailverify.status_on' : 'emailverify.status_off',
            ),
            ManageEmailVerificationOutcome::UnknownAction => $context->reply(
                'emailverify.unknown_sub',
                ['syntax' => $context->trans('emailverify.syntax')],
            ),
        };
    }
}
