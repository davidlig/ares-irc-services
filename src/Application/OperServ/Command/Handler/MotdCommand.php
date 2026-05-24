<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\OperServ\Entity\Motd;
use App\Domain\OperServ\Repository\MotdRepositoryInterface;
use DateTimeImmutable;

use function array_slice;
use function count;
use function ctype_digit;
use function implode;
use function sprintf;
use function strtoupper;

final class MotdCommand implements OperServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly MotdRepositoryInterface $motdRepository,
        private readonly ?ServiceDebugNotifierInterface $debugNotifier = null,
    ) {
    }

    public function getName(): string
    {
        return 'MOTD';
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
        return 'motd.syntax';
    }

    public function getHelpKey(): string
    {
        return 'motd.help';
    }

    public function getOrder(): int
    {
        return 39;
    }

    public function getShortDescKey(): string
    {
        return 'motd.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            [
                'name' => 'ADD',
                'desc_key' => 'motd.add.short',
                'help_key' => 'motd.add.help',
                'syntax_key' => 'motd.add.syntax',
            ],
            [
                'name' => 'DEL',
                'desc_key' => 'motd.del.short',
                'help_key' => 'motd.del.help',
                'syntax_key' => 'motd.del.syntax',
            ],
            [
                'name' => 'LIST',
                'desc_key' => 'motd.list.short',
                'help_key' => 'motd.list.help',
                'syntax_key' => 'motd.list.syntax',
            ],
            [
                'name' => 'CLEAN',
                'desc_key' => 'motd.clean.short',
                'help_key' => 'motd.clean.help',
                'syntax_key' => 'motd.clean.syntax',
            ],
        ];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return OperServPermission::MOTD;
    }

    public function execute(OperServContext $context): void
    {
        $sub = strtoupper($context->args[0] ?? '');

        match ($sub) {
            'ADD' => $this->doAdd($context),
            'DEL' => $this->doDel($context),
            'LIST' => $this->doList($context),
            'CLEAN' => $this->doClean($context),
            default => $context->reply('motd.unknown_sub'),
        };
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }

    private function doAdd(OperServContext $context): void
    {
        $args = $context->args;

        $errorKey = $this->validateMotdAdd($context, $args);
        if (null !== $errorKey) {
            return;
        }

        $botNickname = $args[1];
        $messageType = strtoupper($args[2]);
        $duration = $args[3];
        $expiresAt = '0' === $duration ? null : $this->parseDuration($duration);
        $text = implode(' ', array_slice($args, 4));

        $motd = Motd::create(
            text: $text,
            botNickname: $botNickname,
            messageType: $messageType,
            creatorNickId: $context->senderAccount?->getId(),
            expiresAt: $expiresAt,
        );

        $this->motdRepository->save($motd);

        $context->reply('motd.add.done', [
            '%id%' => $motd->getId(),
        ]);

        $this->auditData = new IrcopAuditData(
            target: $botNickname,
            reason: sprintf('MOTD ADD #%d: %s', $motd->getId(), $text),
        );
    }

    private function validateMotdAdd(OperServContext $context, array $args): ?string
    {
        return (function () use ($context, $args): ?string {
            if (count($args) < 5) {
                $context->reply('motd.add.syntax_hint', ['%syntax%' => $context->trans('motd.add.syntax')]);

                return 'syntax';
            }

            $messageType = strtoupper($args[2]);

            if (!Motd::isValidMessageType($messageType)) {
                $context->reply('motd.add.invalid_type');

                return 'invalid_type';
            }

            $duration = $args[3];
            if ('0' !== $duration && !$this->isDuration($duration)) {
                $context->reply('motd.add.invalid_expiry');

                return 'invalid_expiry';
            }

            $text = implode(' ', array_slice($args, 4));
            if ('' === $text) {
                $context->reply('motd.add.syntax_hint', ['%syntax%' => $context->trans('motd.add.syntax')]);

                return 'syntax';
            }

            return null;
        })();
    }

    private function doDel(OperServContext $context): void
    {
        if (count($context->args) < 2) {
            $context->reply('motd.del.syntax_hint', ['%syntax%' => $context->trans('motd.del.syntax')]);

            return;
        }

        $idArg = $context->args[1];

        if (!ctype_digit($idArg)) {
            $context->reply('motd.del.not_found');

            return;
        }

        $id = (int) $idArg;
        $motd = $this->motdRepository->findById($id);

        if (null === $motd) {
            $context->reply('motd.del.not_found');

            return;
        }

        $this->notifyFinalized($context, $motd);
        $this->motdRepository->remove($motd);

        $context->reply('motd.del.done', [
            '%id%' => $id,
        ]);

        $this->auditData = new IrcopAuditData(
            target: $motd->getBotNickname(),
            reason: sprintf('MOTD DEL #%d: %s', $id, $motd->getText()),
        );
    }

    private function doList(OperServContext $context): void
    {
        $entries = $this->motdRepository->findAll();

        if ([] === $entries) {
            $context->reply('motd.list.empty');

            return;
        }

        $context->reply('motd.list.header');

        foreach ($entries as $motd) {
            $status = $motd->isExpired()
                ? $context->trans('motd.list.expired')
                : ($motd->isEnabled()
                    ? $context->trans('motd.list.status_enabled')
                    : $context->trans('motd.list.status_disabled'));

            $botNickname = '' !== $motd->getBotNickname() ? $motd->getBotNickname() : $context->trans('motd.list.no_bot');
            $expiresAt = null !== $motd->getExpiresAt()
                ? $context->formatDate($motd->getExpiresAt())
                : $context->trans('motd.list.never');

            $context->replyRaw(sprintf(
                '#%d [%s] %s → %s | %s | %s | %s',
                $motd->getId(),
                $motd->getMessageType(),
                $botNickname,
                $motd->getText(),
                $status,
                $expiresAt,
                $context->trans('motd.list.shown_count', ['%count%' => (string) $motd->getShownCount()]),
            ));
        }
    }

    private function doClean(OperServContext $context): void
    {
        $expired = $this->motdRepository->findExpired();

        if ([] === $expired) {
            $context->reply('motd.clean.none');

            return;
        }

        $count = 0;
        foreach ($expired as $motd) {
            $this->notifyFinalized($context, $motd);
            $this->motdRepository->remove($motd);
            ++$count;
        }

        $context->reply('motd.clean.done', [
            '%count%' => $count,
        ]);

        $this->auditData = new IrcopAuditData(
            target: '',
            reason: sprintf('MOTD CLEAN: removed %d expired entries.', $count),
        );
    }

    private function isDuration(string $raw): bool
    {
        return (bool) preg_match('/^(\d+)([smhd])$/i', $raw);
    }

    private function parseDuration(string $raw): DateTimeImmutable
    {
        preg_match('/^(\d+)([smhd])$/i', $raw, $m);
        $number = (int) $m[1];
        $unit = strtolower($m[2]);

        $seconds = match ($unit) {
            's' => $number,
            'm' => $number * 60,
            'h' => $number * 3600,
            'd' => $number * 86400,
        };

        return (new DateTimeImmutable())->modify('+' . $seconds . ' seconds');
    }

    private function notifyFinalized(OperServContext $context, Motd $motd): void
    {
        $date = null !== $motd->getExpiresAt()
            ? $context->formatDate($motd->getExpiresAt())
            : $context->formatDate($motd->getCreatedAt());

        $this->debugNotifier?->notify($context->trans('motd.debug.finalized', [
            '%id%' => (string) $motd->getId(),
            '%type%' => $motd->getMessageType(),
            '%message%' => $motd->getText(),
            '%date%' => $date,
            '%shown_count%' => $context->trans('motd.list.shown_count', [
                '%count%' => (string) $motd->getShownCount(),
            ]),
        ]));
    }
}
