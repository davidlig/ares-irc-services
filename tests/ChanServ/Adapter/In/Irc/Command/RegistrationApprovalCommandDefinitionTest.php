<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\Command\CregCommand;
use App\ChanServ\Adapter\In\Irc\Command\RegmodeCommand;
use App\ChanServ\Adapter\In\Irc\Command\StatusCommand;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\ListPendingRegistrations\ListPendingRegistrationsHandler;
use App\ChanServ\Application\UseCase\RegistrationMode\RegistrationModeHandlerInterface;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationHandlerInterface;
use App\ChanServ\Application\UseCase\ShowRegistrationStatus\ShowRegistrationStatusHandler;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CregCommand::class)]
#[CoversClass(RegmodeCommand::class)]
#[CoversClass(StatusCommand::class)]
final class RegistrationApprovalCommandDefinitionTest extends TestCase
{
    #[Test]
    public function cregValidatesEachSubcommandAndRequiresARejectionReason(): void
    {
        $command = new CregCommand(
            new ListPendingRegistrationsHandler(
                $this->createStub(RegisteredChannelRepositoryInterface::class),
                $this->createStub(ChanUserAccountPort::class),
            ),
            $this->createStub(ReviewRegistrationHandlerInterface::class),
        );
        $definition = $command->getArgumentDefinition();

        self::assertNull(CommandArgumentValidator::validate($definition, ['PENDING'])->syntaxKey);
        self::assertNull(CommandArgumentValidator::validate($definition, ['PENDING', '2'])->syntaxKey);
        self::assertNull(CommandArgumentValidator::validate($definition, ['ACCEPT', '#test'])->syntaxKey);
        self::assertNull(CommandArgumentValidator::validate($definition, ['REJECT', '#test', 'Needs', 'a', 'topic'])->syntaxKey);
        self::assertSame('creg.reject.syntax', CommandArgumentValidator::validate($definition, ['REJECT', '#test', '   '])->syntaxKey);
        self::assertSame('creg.accept.syntax', CommandArgumentValidator::validate($definition, ['ACCEPT'])->syntaxKey);
    }

    #[Test]
    public function regmodeAndStatusDeclareContextualChannelSyntax(): void
    {
        $regmode = new RegmodeCommand($this->createStub(RegistrationModeHandlerInterface::class));
        $status = new StatusCommand(new ShowRegistrationStatusHandler($this->createStub(RegisteredChannelRepositoryInterface::class)));

        self::assertNull(CommandArgumentValidator::validate($regmode->getArgumentDefinition(), ['APPROVAL'])->syntaxKey);
        self::assertNull(CommandArgumentValidator::validate($regmode->getArgumentDefinition(), ['STATUS'])->syntaxKey);
        self::assertSame('regmode.syntax', CommandArgumentValidator::validate($regmode->getArgumentDefinition(), ['UNKNOWN'])->syntaxKey);
        self::assertNull(CommandArgumentValidator::validate($status->getArgumentDefinition(), ['#test'])->syntaxKey);
        self::assertSame('status.syntax', CommandArgumentValidator::validate($status->getArgumentDefinition(), ['test'])->syntaxKey);
    }
}
