<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Application\UseCase\ManageGeoip\GeoipAction;
use App\OperServ\Application\UseCase\ManageGeoip\ManageGeoip;
use App\OperServ\Application\UseCase\ManageGeoip\ManageGeoipHandlerInterface;
use App\OperServ\Application\UseCase\ManageGeoip\ManageGeoipOutcome;
use App\OperServ\Application\UseCase\ManageGeoip\ManageGeoipResult;
use DateTimeImmutable;

use function array_slice;
use function count;
use function implode;
use function strtoupper;
use function trim;

/** GEOIP ADD|DEL|LIST; IRC parsing and presentation only. */
final readonly class GeoipCommand implements OperServCommandInterface
{
    public function __construct(private ManageGeoipHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'GEOIP';
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
        return 'geoip.syntax';
    }

    public function getHelpKey(): string
    {
        return 'geoip.help';
    }

    public function getExampleKey(): string
    {
        return 'geoip.example';
    }

    public function getOrder(): int
    {
        return 25;
    }

    public function getShortDescKey(): string
    {
        return 'geoip.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'geoip.add.short', 'help_key' => 'geoip.add.help', 'syntax_key' => 'geoip.add.syntax', 'example_key' => 'geoip.add.example'],
            ['name' => 'DEL', 'desc_key' => 'geoip.del.short', 'help_key' => 'geoip.del.help', 'syntax_key' => 'geoip.del.syntax', 'example_key' => 'geoip.del.example'],
            ['name' => 'LIST', 'desc_key' => 'geoip.list.short', 'help_key' => 'geoip.list.help', 'syntax_key' => 'geoip.list.syntax', 'example_key' => 'geoip.list.example'],
        ];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return 'operserv.geoip';
    }

    public function execute(OperServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $subcommand = strtoupper($context->args[0] ?? '');
        $action = match ($subcommand) {
            'ADD' => GeoipAction::Add,
            'DEL' => GeoipAction::Delete,
            'LIST' => GeoipAction::List,
            default => GeoipAction::Unknown,
        };
        $result = $this->handler->handle(new ManageGeoip(
            $action,
            $context->sender->nick,
            $context->senderAccountId(),
            new DateTimeImmutable(),
            GeoipAction::List === $action ? null : trim($context->args[1] ?? ''),
            GeoipAction::Add === $action ? $this->reason($context) : null,
        ));

        $this->present($context, $subcommand, $action, $result);
    }

    private function reason(OperServContext $context): string
    {
        $reason = trim(implode(' ', array_slice($context->args, 2)));

        return '' !== $reason ? $reason : $context->trans('geoip.default_reason');
    }

    private function present(OperServContext $context, string $subcommand, GeoipAction $action, ManageGeoipResult $result): void
    {
        match ($result->outcome) {
            ManageGeoipOutcome::UnknownAction => $context->reply('geoip.unknown_sub', ['%sub%' => $subcommand]),
            ManageGeoipOutcome::InvalidRequest => $context->reply('error.syntax', ['%syntax%' => $context->trans(GeoipAction::Delete === $action ? 'geoip.del.syntax' : 'geoip.add.syntax')]),
            ManageGeoipOutcome::UnknownCountryCode => $context->reply('geoip.unknown_country', ['%country%' => (string) $result->countryCode]),
            ManageGeoipOutcome::AlreadyExists => $context->reply('geoip.already_exists', ['%country%' => (string) $result->countryCode]),
            ManageGeoipOutcome::NotFound => $context->reply('geoip.not_found', ['%country%' => (string) $result->countryCode]),
            ManageGeoipOutcome::ListEmpty => $context->reply('geoip.list.empty'),
            ManageGeoipOutcome::Added => $context->reply('geoip.add.done', [
                '%country%' => (string) $result->countryCode,
                '%reason%' => (string) $result->reason,
            ]),
            ManageGeoipOutcome::Deleted => $context->reply('geoip.del.done', ['%country%' => (string) $result->countryCode]),
            ManageGeoipOutcome::Listed => $this->presentList($context, $result),
        };
    }

    private function presentList(OperServContext $context, ManageGeoipResult $result): void
    {
        $context->reply('geoip.list.header', ['%count%' => (string) count($result->entries)]);
        foreach ($result->entries as $index => $entry) {
            $context->reply('geoip.list.entry', [
                '%index%' => (string) ($index + 1),
                '%country%' => $entry->countryCode,
                '%reason%' => $entry->reason,
                '%nickname%' => $entry->registeredByNick,
                '%registered%' => $context->formatDate($entry->registeredAt),
            ]);
        }
    }
}
