<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\CRegServ\Adapter\In\Irc\Command\AprobarCommand;
use App\CRegServ\Adapter\In\Irc\Command\DenegadosCommand;
use App\CRegServ\Adapter\In\Irc\Command\DenegarCommand;
use App\CRegServ\Adapter\In\Irc\Command\DropCommand;
use App\CRegServ\Adapter\In\Irc\Command\EstadoCommand;
use App\CRegServ\Adapter\In\Irc\Command\PendientesCommand;
use App\CRegServ\Adapter\In\Irc\Command\RegistraCommand;
use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Application\Security\CRegServPermission;
use App\OperServ\Application\Port\In\OperatorPermissionCatalog;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function sort;

/**
 * A mistyped service tag registers nothing and lint:container stays silent about it: the
 * class exists, it is simply never collected. Booting the real container here turns a
 * command that stopped being routable into a failing test instead of a silent
 * "unknown command" on the network.
 */
#[CoversNothing]
final class CRegServCommandWiringTest extends KernelTestCase
{
    /**
     * @return array<string, array{class-string<CRegServCommandInterface>, string}>
     */
    public static function routableCommandProvider(): array
    {
        return [
            'REGISTRA' => [RegistraCommand::class, 'REGISTRA'],
            'ESTADO' => [EstadoCommand::class, 'ESTADO'],
            'APROBAR' => [AprobarCommand::class, 'APROBAR'],
            'DENEGAR' => [DenegarCommand::class, 'DENEGAR'],
            'PENDIENTES' => [PendientesCommand::class, 'PENDIENTES'],
            'DENEGADOS' => [DenegadosCommand::class, 'DENEGADOS'],
            'DROP' => [DropCommand::class, 'DROP'],
        ];
    }

    /**
     * @param class-string<CRegServCommandInterface> $class
     */
    #[Test]
    #[DataProvider('routableCommandProvider')]
    public function everyPortedCommandIsCollectedByTheTaggedIterator(string $class, string $name): void
    {
        self::bootKernel();

        $commands = self::getContainer()->get(CRegServCommandRegistry::class);
        self::assertInstanceOf(CRegServCommandRegistry::class, $commands);

        self::assertInstanceOf($class, $commands->find($name));
    }

    #[Test]
    public function theRegistryHoldsExactlyTheSevenPortedCommands(): void
    {
        self::bootKernel();

        $commands = self::getContainer()->get(CRegServCommandRegistry::class);
        self::assertInstanceOf(CRegServCommandRegistry::class, $commands);

        $names = array_map(
            static fn (CRegServCommandInterface $command): string => $command->getName(),
            $commands->all(),
        );
        sort($names);

        self::assertSame(
            ['APROBAR', 'DENEGADOS', 'DENEGAR', 'DROP', 'ESTADO', 'PENDIENTES', 'REGISTRA'],
            $names,
        );
    }

    /**
     * @return array<string, array{string, class-string<CRegServCommandInterface>, string}>
     */
    public static function operOnlyCommandProvider(): array
    {
        return [
            'APROBAR' => ['APROBAR', AprobarCommand::class, CRegServPermission::APROBAR],
            'DENEGAR' => ['DENEGAR', DenegarCommand::class, CRegServPermission::DENEGAR],
            'PENDIENTES' => ['PENDIENTES', PendientesCommand::class, CRegServPermission::PENDIENTES],
            'DENEGADOS' => ['DENEGADOS', DenegadosCommand::class, CRegServPermission::DENEGADOS],
            'DROP' => ['DROP', DropCommand::class, CRegServPermission::DROP],
        ];
    }

    /**
     * The oper half of pulsar's table: APROBAR, DENEGAR, PENDIENTES and DENEGADOS are
     * guarded by is_services_oper and DROP by is_services_admin, which is an IRCop
     * permission here because ares has no services-admin tier of its own.
     *
     * @param class-string<CRegServCommandInterface> $class
     */
    #[Test]
    #[DataProvider('operOnlyCommandProvider')]
    public function theOperCommandsAreOperOnlyAndAskForAPermissionTheCatalogPublishes(
        string $name,
        string $class,
        string $permission,
    ): void {
        self::bootKernel();

        $commands = self::getContainer()->get(CRegServCommandRegistry::class);
        self::assertInstanceOf(CRegServCommandRegistry::class, $commands);

        $command = $commands->find($name);
        self::assertInstanceOf($class, $command);
        self::assertTrue($command->isOperOnly(), $name . ' must never be reachable by a plain user.');
        self::assertSame($permission, $command->getRequiredPermission());

        $permissions = self::getContainer()->get(OperatorPermissionCatalog::class);
        self::assertInstanceOf(OperatorPermissionCatalog::class, $permissions);
        self::assertContains(
            $permission,
            $permissions->getAllPermissions(),
            $name . ' must ask for a permission OperServ actually knows about.',
        );
    }

    /**
     * The user half: REGISTRA and ESTADO are for anyone with a registered nick, which is why
     * they are not oper-only and ask for IDENTIFIED rather than an IRCop permission.
     *
     * @param class-string<CRegServCommandInterface> $class
     */
    #[Test]
    #[DataProvider('userFacingCommandProvider')]
    public function theUserCommandsStayReachableWithoutBeingOper(
        string $name,
        string $class,
    ): void {
        self::bootKernel();

        $commands = self::getContainer()->get(CRegServCommandRegistry::class);
        self::assertInstanceOf(CRegServCommandRegistry::class, $commands);

        $command = $commands->find($name);
        self::assertInstanceOf($class, $command);
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
    }

    /**
     * @return array<string, array{string, class-string<CRegServCommandInterface>}>
     */
    public static function userFacingCommandProvider(): array
    {
        return [
            'REGISTRA' => ['REGISTRA', RegistraCommand::class],
            'ESTADO' => ['ESTADO', EstadoCommand::class],
        ];
    }

    #[Test]
    public function theFiveOperPermissionsArePublishedUnderTheCRegServService(): void
    {
        self::bootKernel();

        $permissions = self::getContainer()->get(OperatorPermissionCatalog::class);
        self::assertInstanceOf(OperatorPermissionCatalog::class, $permissions);

        $published = $permissions->getAllPermissions();

        self::assertContains(CRegServPermission::APROBAR, $published);
        self::assertContains(CRegServPermission::DENEGAR, $published);
        self::assertContains(CRegServPermission::PENDIENTES, $published);
        self::assertContains(CRegServPermission::DENEGADOS, $published);
        self::assertContains(CRegServPermission::DROP, $published);
    }
}
