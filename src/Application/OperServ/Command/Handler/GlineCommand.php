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
use App\Application\Shared\Time\RelativeExpiryParser;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

use function array_slice;
use function count;
use function ctype_digit;
use function fnmatch;
use function implode;
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
        (function () use ($context): void {
            if (count($context->args) < 4) {
                $context->reply('error.syntax', ['%syntax%' => $context->trans('gline.add.syntax')]);

                return;
            }

            $mask = trim($context->args[1]);
            $expiryStr = trim($context->args[2]);
            $reason = trim(implode(' ', array_slice($context->args, 3)));

            $errorKey = $this->validateGlineAddBasics($context, $mask, $reason);
            if (null !== $errorKey) {
                return;
            }

            $resolvedMask = Gline::isNicknameMask($mask) ? $this->resolveNicknameToMask($mask, $context) : $mask;
            if (null === $resolvedMask) {
                return;
            }

            $errorKey = $this->validateGlineMaskSafety($context, $resolvedMask);
            if (null !== $errorKey) {
                return;
            }

            $expiresAt = RelativeExpiryParser::isPermanent($expiryStr) ? null : RelativeExpiryParser::parse($expiryStr);
            if (null === $expiresAt && !RelativeExpiryParser::isPermanent($expiryStr)) {
                $context->reply('gline.invalid_expiry');

                return;
            }

            $this->doAddGline($context, $resolvedMask, $expiresAt, $expiryStr, $reason);
        })();
    }

    private function validateGlineAddBasics(OperServContext $context, string $mask, string $reason): ?string
    {
        if ('' === $mask || '' === $reason) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('gline.add.syntax')]);

            return 'syntax';
        }

        if (!Gline::isValidMask($mask)) {
            $context->reply('gline.invalid_mask');

            return 'invalid_mask';
        }

        return null;
    }

    private function validateGlineMaskSafety(OperServContext $context, string $resolvedMask): ?string
    {
        $errorKey = null;
        $errorParams = ['%mask%' => $resolvedMask];

        if (Gline::isGlobalMask($resolvedMask)) {
            $errorKey = 'gline.global_mask';
        } elseif (!Gline::isSafeMask($resolvedMask)) {
            $errorKey = 'gline.dangerous_mask';
        } else {
            $protectedUser = $this->findProtectedUser($resolvedMask);
            if (null !== $protectedUser) {
                $errorKey = 'gline.protected_user';
                $errorParams = ['%nickname%' => $protectedUser];
            }
        }

        if (null !== $errorKey) {
            $context->reply($errorKey, $errorParams);
        }

        return $errorKey;
    }

    private function doAddGline(OperServContext $context, string $resolvedMask, ?DateTimeImmutable $expiresAt, string $expiryStr, string $reason): void
    {
        $existing = $this->glineRepository->findByMask($resolvedMask);
        if (null !== $existing) {
            if ($existing->isExpired()) {
                $this->glineRepository->remove($existing);
            } else {
                $context->reply('gline.already_exists', ['%mask%' => $resolvedMask]);

                return;
            }
        }

        $count = $this->glineRepository->countAll();
        if ($count >= $this->maxGlines) {
            $context->reply('gline.max_entries', ['%max%' => (string) $this->maxGlines]);

            return;
        }

        $creatorNickId = $context->senderAccount?->getId();
        $gline = Gline::create($resolvedMask, $creatorNickId, $reason, $expiresAt);
        $this->glineRepository->save($gline);

        $this->sendGlineToIrcd($resolvedMask, $expiresAt, $reason);

        $duration = null === $expiresAt
            ? $context->trans('gline.permanent')
            : $expiryStr;

        $this->auditData = new IrcopAuditData(
            target: $resolvedMask,
            reason: $reason,
            extra: ['duration' => $duration],
        );

        $context->reply('gline.add.done', [
            '%mask%' => $resolvedMask,
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
                '%nickname%' => $creatorName,
                '%expiration%' => $expires,
            ]);
            ++$num;
        }
    }

    private function resolveNicknameToMask(string $nickname, OperServContext $context): ?string
    {
        $user = $this->userLookup->findByNick($nickname);
        if (null === $user) {
            $context->reply('gline.user_not_found', ['%nickname%' => $nickname]);

            return null;
        }

        return $user->ident . '@' . $user->hostname;
    }

    private function findProtectedUser(string $mask): ?string
    {
        $rootNicks = $this->rootRegistry->getRootNicks();
        $matchingRoot = array_find($rootNicks, function (string $rootNick) use ($mask): bool {
            $users = $this->userLookup->findByNick($rootNick);
            if (null === $users) {
                return false;
            }

            return $this->glineMatchesUser($mask, strtolower($rootNick . '!' . $users->ident . '@' . $users->hostname));
        });
        if (null !== $matchingRoot) {
            return $matchingRoot;
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
