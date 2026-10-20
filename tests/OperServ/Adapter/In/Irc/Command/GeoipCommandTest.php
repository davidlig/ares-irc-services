<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Adapter\In\Irc\Command\GeoipCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\UseCase\ManageGeoip\GeoipAction;
use App\OperServ\Application\UseCase\ManageGeoip\GeoipCountryListEntry;
use App\OperServ\Application\UseCase\ManageGeoip\ManageGeoip;
use App\OperServ\Application\UseCase\ManageGeoip\ManageGeoipHandlerInterface;
use App\OperServ\Application\UseCase\ManageGeoip\ManageGeoipOutcome;
use App\OperServ\Application\UseCase\ManageGeoip\ManageGeoipResult;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(GeoipCommand::class)]
#[CoversClass(ManageGeoip::class)]
#[CoversClass(ManageGeoipResult::class)]
#[CoversClass(GeoipCountryListEntry::class)]
final class GeoipCommandTest extends TestCase
{
    #[Test]
    public function exposesCommandMetadata(): void
    {
        $command = new GeoipCommand(new RecordingGeoipCommandHandler());

        self::assertSame('GEOIP', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('geoip.syntax', $command->getSyntaxKey());
        self::assertSame('geoip.help', $command->getHelpKey());
        self::assertSame(25, $command->getOrder());
        self::assertSame('geoip.short', $command->getShortDescKey());
        self::assertSame(['ADD', 'DEL', 'LIST'], array_column($command->getSubCommandHelp(), 'name'));
        self::assertTrue($command->isOperOnly());
        self::assertSame('operserv.geoip', $command->getRequiredPermission());
    }

    #[Test]
    public function getExampleKeyReturnsCorrectKey(): void
    {
        $command = new GeoipCommand(new RecordingGeoipCommandHandler());

        self::assertSame('geoip.example', $command->getExampleKey());
    }

    #[Test]
    public function mapsAddDeleteListAndUnknownArguments(): void
    {
        $handler = new RecordingGeoipCommandHandler();
        $command = new GeoipCommand($handler);
        $command->execute($this->context(['ADD', ' es ', 'Not allowed', 'in this network']));
        $command->execute($this->context(['DEL', ' ES ']));
        $command->execute($this->context(['LIST']));
        $command->execute($this->context(['unexpected']));

        self::assertSame(
            [GeoipAction::Add, GeoipAction::Delete, GeoipAction::List, GeoipAction::Unknown],
            array_map(static fn (ManageGeoip $input): GeoipAction => $input->action, $handler->inputs),
        );
        self::assertSame('Oper', $handler->inputs[0]->actor);
        self::assertSame(42, $handler->inputs[0]->actorAccountId);
        self::assertSame('es', $handler->inputs[0]->countryCode);
        self::assertSame('Not allowed in this network', $handler->inputs[0]->reason);
        self::assertSame('ES', $handler->inputs[1]->countryCode);
        self::assertNull($handler->inputs[1]->reason);
        self::assertNull($handler->inputs[2]->countryCode);
        self::assertNull($handler->inputs[2]->reason);
    }

    #[Test]
    public function usesTheDefaultReasonWhenTheOperatorOmitsIt(): void
    {
        $handler = new RecordingGeoipCommandHandler();
        $notifier = new RecordingGeoipCommandNotifier();

        new GeoipCommand($handler)->execute($this->context(['ADD', 'ES'], $notifier));

        self::assertSame('geoip.default_reason', $handler->inputs[0]->reason);
        self::assertArrayHasKey('geoip.default_reason', $notifier->translations->parameters);
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('simplePresentations')]
    public function presentsSemanticOutcomes(ManageGeoipResult $result, array $arguments, string $expectedKey): void
    {
        $notifier = new RecordingGeoipCommandNotifier();
        $command = new GeoipCommand(new RecordingGeoipCommandHandler($result));

        $command->execute($this->context($arguments, $notifier));

        self::assertSame($expectedKey, $notifier->messages[0]);
    }

    /** @return iterable<string, array{ManageGeoipResult, list<string>, string}> */
    public static function simplePresentations(): iterable
    {
        yield 'unknown action' => [new ManageGeoipResult(ManageGeoipOutcome::UnknownAction), ['what'], 'geoip.unknown_sub'];
        yield 'invalid add' => [new ManageGeoipResult(ManageGeoipOutcome::InvalidRequest), ['ADD'], 'error.syntax'];
        yield 'invalid delete' => [new ManageGeoipResult(ManageGeoipOutcome::InvalidRequest), ['DEL'], 'error.syntax'];
        yield 'unknown country' => [new ManageGeoipResult(ManageGeoipOutcome::UnknownCountryCode, countryCode: 'XX'), ['ADD'], 'geoip.unknown_country'];
        yield 'duplicate' => [new ManageGeoipResult(ManageGeoipOutcome::AlreadyExists, countryCode: 'ES'), ['ADD'], 'geoip.already_exists'];
        yield 'not found' => [new ManageGeoipResult(ManageGeoipOutcome::NotFound, countryCode: 'ES'), ['DEL'], 'geoip.not_found'];
        yield 'empty list' => [new ManageGeoipResult(ManageGeoipOutcome::ListEmpty), ['LIST'], 'geoip.list.empty'];
        yield 'deleted' => [new ManageGeoipResult(ManageGeoipOutcome::Deleted, countryCode: 'ES'), ['DEL'], 'geoip.del.done'];
    }

    #[Test]
    public function presentsTheAddedCountryWithItsReason(): void
    {
        $notifier = new RecordingGeoipCommandNotifier();
        $result = new ManageGeoipResult(ManageGeoipOutcome::Added, countryCode: 'ES', reason: 'Not allowed');

        new GeoipCommand(new RecordingGeoipCommandHandler($result))->execute($this->context(['ADD', 'ES'], $notifier));

        self::assertSame('geoip.add.done', $notifier->messages[0]);
        self::assertSame('ES', $notifier->translations->parameters['geoip.add.done']['%country%']);
        self::assertSame('Not allowed', $notifier->translations->parameters['geoip.add.done']['%reason%']);
    }

    #[Test]
    public function presentsTheBlockedCountries(): void
    {
        $notifier = new RecordingGeoipCommandNotifier();
        $result = new ManageGeoipResult(ManageGeoipOutcome::Listed, entries: [
            new GeoipCountryListEntry('ES', 'Not allowed', 'Oper', new DateTimeImmutable('2026-10-20T10:00:00+00:00')),
        ]);

        new GeoipCommand(new RecordingGeoipCommandHandler($result))->execute($this->context(['LIST'], $notifier));

        self::assertSame(['geoip.list.header', 'geoip.list.entry'], $notifier->messages);
        self::assertSame('1', $notifier->translations->parameters['geoip.list.header']['%count%']);
        self::assertSame('1', $notifier->translations->entryParameters[0]['%index%']);
        self::assertSame('ES', $notifier->translations->entryParameters[0]['%country%']);
        self::assertSame('Not allowed', $notifier->translations->entryParameters[0]['%reason%']);
        self::assertSame('Oper', $notifier->translations->entryParameters[0]['%nickname%']);
        self::assertSame('20/10/2026 10:00 UTC', $notifier->translations->entryParameters[0]['%registered%']);
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = new RecordingGeoipCommandHandler();
        $notifier = new RecordingGeoipCommandNotifier();

        new GeoipCommand($handler)->execute($this->context(['LIST'], $notifier, true));

        self::assertSame([], $handler->inputs);
        self::assertSame([], $notifier->messages);
    }

    /** @param list<string> $arguments */
    private function context(array $arguments, ?RecordingGeoipCommandNotifier $notifier = null, bool $withoutSender = false): OperServContext
    {
        $notifier ??= new RecordingGeoipCommandNotifier();

        return new OperServContext(
            $withoutSender ? null : new SenderView('001AAA', 'Oper', 'ident', 'host', 'cloak', 'ip', true, true),
            $withoutSender ? null : new NickAccountData(42, 'Oper', 'en'),
            'GEOIP',
            $arguments,
            $notifier,
            $notifier->translations,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            new ServiceNicknameRegistry([]),
            $this->createStub(OperatorAuthorizationQuery::class),
        );
    }
}

final class RecordingGeoipCommandHandler implements ManageGeoipHandlerInterface
{
    /** @var list<ManageGeoip> */
    public array $inputs = [];

    public function __construct(private readonly ManageGeoipResult $result = new ManageGeoipResult(ManageGeoipOutcome::UnknownAction)) {}

    public function handle(ManageGeoip $command): ManageGeoipResult
    {
        $this->inputs[] = $command;

        return $this->result;
    }
}

final class RecordingGeoipCommandTranslation implements TranslatorInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $parameters = [];

    /** @var list<array<string, mixed>> */
    public array $entryParameters = [];

    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if ('geoip.list.entry' === $id) {
            $this->entryParameters[] = $parameters;
        } else {
            $this->parameters[$id] = $parameters;
        }

        return $id;
    }

    public function getLocale(): string
    {
        return 'en';
    }
}

final class RecordingGeoipCommandNotifier implements OperServNotifierInterface
{
    /** @var list<string> */
    public array $messages = [];

    public RecordingGeoipCommandTranslation $translations;

    public function __construct()
    {
        $this->translations = new RecordingGeoipCommandTranslation();
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->messages[] = $message;
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        $this->messages[] = $message;
    }

    public function getNick(): string
    {
        return 'OperServ';
    }

    public function getUid(): string
    {
        return '001OS';
    }

    public function getServiceKey(): string
    {
        return 'operserv';
    }
}
