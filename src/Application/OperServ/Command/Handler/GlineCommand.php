<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

use function array_slice;
use function count;
use function ctype_digit;
use function fnmatch;
use function implode;
use function preg_match;
use function sprintf;
use function strtolower;
use function strtoupper;
use function trim;

final class GlineCommand implements OperServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly GlineRepositoryInterface $glineRepository,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly OperIrcopRepositoryInterface $ircopRepo,
        private readonly RootUserRegistry $rootRegistry,
        private readonly IrcopAccessHelper $accessHelper,
        private readonly ActiveConnectionHolderInterface $connectionHolder,
        private readonly LoggerInterface $logger,
        private readonly int $maxGlines = 1000,
    ) {
    }

    public function getName(): string
    {
        return 'GLINE';
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
        return 'gline.syntax';
    }

    public function getHelpKey(): string
    {
        return 'gline.help';
    }

    public function getOrder(): int
    {
        return 20;
    }

    public function getShortDescKey(): string
    {
        return 'gline.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'gline.add.short', 'help_key' => 'gline.add.help', 'syntax_key' => 'gline.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'gline.del.short', 'help_key' => 'gline.del.help', 'syntax_key' => 'gline.del.syntax'],
            ['name' => 'LIST', 'desc_key' => 'gline.list.short', 'help_key' => 'gline.list.help', 'syntax_key' => 'gline.list.syntax'],
        ];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return OperServPermission::GLINE;
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }

    public function execute(OperServContext $context): void
    {
        $sender = $context->getSender();
        if (null === $sender) {
            return;
        }

        $sub = strtoupper($context->args[0] ?? '');
        switch ($sub) {
            case 'ADD':
                $this->doAdd($context);
                break;
            case 'DEL':
                $this->doDel($context);
                break;
            case 'LIST':
                $this->doList($context);
                break;
            default:
                $context->reply('gline.unknown_sub', ['%sub%' => $sub]);
        }
    }

    private function doAdd(OperServContext $context): void
    {
        if (count($context->args) < 4) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('gline.add.syntax')]);

            return;
        }

        $mask = trim($context->args[1]);
        if ('' === $mask) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('gline.add.syntax')]);

            return;
        }

        if (!Gline::isValidMask($mask)) {
            $context->reply('gline.invalid_mask');

            return;
        }

        // If mask is a nickname, resolve it to user@host
        $originalMask = $mask;
        if (Gline::isNicknameMask($mask)) {
            $resolved = $this->resolveNicknameToMask($mask, $context);
            if (null === $resolved) {
                return;
            }
            $mask = $resolved;
        }

        if (Gline::isGlobalMask($mask)) {
            $context->reply('gline.global_mask', ['%mask%' => $mask]);

            return;
        }

        if (!Gline::isSafeMask($mask)) {
            $context->reply('gline.dangerous_mask', ['%mask%' => $mask]);

            return;
        }

        $protectedUser = $this->findProtectedUser($mask);
        if (null !== $protectedUser) {
            $context->reply('gline.protected_user', ['%nick%' => $protectedUser]);

            return;
        }

        $expiryStr = trim($context->args[2]);
        $expiresAt = $this->parseExpiry($expiryStr);
        if (null === $expiresAt && '0' !== strtolower($expiryStr)) {
            $context->reply('gline.invalid_expiry');

            return;
        }

        $reasonParts = array_slice($context->args, 3);
        $reason = trim(implode(' ', $reasonParts));
        if ('' === $reason) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('gline.add.syntax')]);

            return;
        }

        $existing = $this->glineRepository->findByMask($mask);
        if (null !== $existing) {
            if ($existing->isExpired()) {
                $this->glineRepository->remove($existing);
            } else {
                $context->reply('gline.already_exists', ['%mask%' => $mask]);

                return;
            }
        }

        $count = $this->glineRepository->countAll();
        if ($count >= $this->maxGlines) {
            $context->reply('gline.max_entries', ['%max%' => (string) $this->maxGlines]);

            return;
        }

        $creatorNickId = $context->senderAccount?->getId();
        $gline = Gline::create($mask, $creatorNickId, $reason, $expiresAt);
        $this->glineRepository->save($gline);

        $this->sendGlineToIrcd($mask, $expiresAt, $reason);

        $duration = null === $expiresAt
            ? $context->trans('gline.permanent')
            : $expiryStr;

        $this->auditData = new IrcopAuditData(
            target: $mask,
            reason: $reason,
            extra: ['duration' => $duration],
        );

        $context->reply('gline.add.done', [
            '%mask%' => $mask,
            '%duration%' => $duration,
            '%reason%' => $reason,
        ]);
    }

    private function doDel(OperServContext $context): void
    {
        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('gline.del.syntax')]);

            return;
        }

        $item = trim($context->args[1]);
        if ('' === $item) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('gline.del.syntax')]);

            return;
        }

        $gline = $this->findGlineByItem($item);

        if (null === $gline) {
            $context->reply('gline.not_found', ['%mask%' => $item]);

            return;
        }

        $mask = $gline->getMask();
        $this->glineRepository->remove($gline);

        $this->removeGlineFromIrcd($mask);

        $this->auditData = new IrcopAuditData(
            target: $mask,
        );

        $context->reply('gline.del.done', ['%mask%' => $mask]);
    }

    private function doList(OperServContext $context): void
    {
        $pattern = count($context->args) >= 2 ? trim($context->args[1]) : null;

        $glines = null !== $pattern && '' !== $pattern
            ? $this->glineRepository->findByMaskPattern($pattern)
            : $this->glineRepository->findAll();

        if ([] === $glines) {
            $context->reply('gline.list.empty');

            return;
        }

        $context->reply('gline.list.header', ['%count%' => (string) count($glines)]);

        $num = 1;
        foreach ($glines as $gline) {
            $creatorName = $this->resolveCreatorName($gline->getCreatorNickId(), $context);
            $expires = null !== $gline->getExpiresAt()
                ? $context->formatDate($gline->getExpiresAt())
                : $context->trans('gline.list.never_expires');

            $context->reply('gline.list.entry', [
                '%index%' => (string) $num,
                '%mask%' => sprintf("\x0304%s\x03", $gline->getMask()),
                '%reason%' => $gline->getReason() ?? $context->trans('gline.list.no_reason'),
                '%nick%' => $creatorName,
                '%expiration%' => $expires,
            ]);
            ++$num;
        }
    }

    private function resolveNicknameToMask(string $nickname, OperServContext $context): ?string
    {
        $user = $this->userLookup->findByNick($nickname);
        if (null === $user) {
            $context->reply('gline.user_not_found', ['%nick%' => $nickname]);

            return null;
        }

        return $user->ident . '@' . $user->hostname;
    }

    private function findProtectedUser(string $mask): ?string
    {
        $rootNicks = $this->rootRegistry->getRootNicks();
        foreach ($rootNicks as $rootNick) {
            // Roots are always protected - check if the nick is online
            $users = $this->userLookup->findByNick($rootNick);
            if (null === $users) {
                continue;
            }

            $userMask = strtolower($rootNick . '!' . $users->ident . '@' . $users->hostname);
            if ($this->glineMatchesUser($mask, $userMask)) {
                return $rootNick;
            }
        }

        $allIrcops = $this->ircopRepo->findAll();
        foreach ($allIrcops as $ircop) {
            $nick = $this->nickRepository->findById($ircop->getNickId());
            if (null === $nick) {
                continue;
            }

            $users = $this->userLookup->findByNick($nick->getNickname());
            if (null === $users) {
                continue;
            }

            $userMask = strtolower($nick->getNickname() . '!' . $users->ident . '@' . $users->hostname);
            if ($this->glineMatchesUser($mask, $userMask)) {
                return $nick->getNickname();
            }
        }

        return null;
    }

    private function glineMatchesUser(string $glineMask, string $userMask): bool
    {
        $glineLower = strtolower($glineMask);
        $userLower = strtolower($userMask);

        $atPos = strpos($glineLower, '@');
        if (false === $atPos) {
            return false;
        }

        $glineUser = substr($glineLower, 0, $atPos);
        $glineHost = substr($glineLower, $atPos + 1);

        $exPos = strpos($userLower, '!');
        $userAtPos = strrpos($userLower, '@');
        if (false === $userAtPos || false === $exPos) {
            return false;
        }

        $userIdentPart = substr($userLower, $exPos + 1, $userAtPos - $exPos - 1);
        $userHostPart = substr($userLower, $userAtPos + 1);

        $userMatches = fnmatch($glineUser, $userIdentPart);
        $hostMatches = fnmatch($glineHost, $userHostPart);

        return $userMatches && $hostMatches;
    }

    private function findGlineByItem(string $item): ?Gline
    {
        if (ctype_digit($item)) {
            $num = (int) $item;
            if ($num < 1) {
                return null;
            }
            $glines = $this->glineRepository->findAll();
            $idx = $num - 1;

            return $glines[$idx] ?? null;
        }

        return $this->glineRepository->findByMask($item);
    }

    private function parseExpiry(string $expiryStr): ?DateTimeImmutable
    {
        $expiryStr = strtolower(trim($expiryStr));

        if ('0' === $expiryStr) {
            return null;
        }

        $matches = [];
        if (!preg_match('/^(\d+)([dhm])$/', $expiryStr, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        $unit = $matches[2];

        $intervalSpec = match ($unit) {
            'd' => "P{$value}D",
            'h' => "PT{$value}H",
            'm' => "PT{$value}M",
        };

        return (new DateTimeImmutable())->add(new DateInterval($intervalSpec));
    }

    private function resolveCreatorName(?int $creatorNickId, OperServContext $context): string
    {
        if (null === $creatorNickId) {
            return $context->trans('gline.list.unknown_creator');
        }

        $creator = $this->nickRepository->findById($creatorNickId);

        return null !== $creator ? $creator->getNickname() : $context->trans('gline.list.unknown_creator');
    }

    private function sendGlineToIrcd(string $mask, ?DateTimeImmutable $expiresAt, string $reason): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->error('GLINE: no active protocol module');

            return;
        }

        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            $this->logger->error('GLINE: no server SID');

            return;
        }

        $parts = Gline::parseUserHost($mask);
        $duration = null === $expiresAt ? 0 : max(0, $expiresAt->getTimestamp() - time());

        $module->getServiceActions()->addGline(
            $serverSid,
            $parts['user'],
            $parts['host'],
            $duration,
            $reason,
        );
    }

    private function removeGlineFromIrcd(string $mask): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->error('GLINE DEL: no active protocol module');

            return;
        }

        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            $this->logger->error('GLINE DEL: no server SID');

            return;
        }

        $parts = Gline::parseUserHost($mask);

        $module->getServiceActions()->removeGline(
            $serverSid,
            $parts['user'],
            $parts['host'],
        );
    }
}
