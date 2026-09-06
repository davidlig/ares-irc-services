<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\ValueObject\ForcedVhost;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\ForbiddenVhostRepositoryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\Service\VhostValidator;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\Event\NickVhostChangedEvent;

use function in_array;
use function strtoupper;
use function trim;

final readonly class SetVhostHandler implements SetOptionHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private VhostValidator $vhostValidator,
        private VhostDisplayResolver $displayResolver,
        private NetworkUserLookupPort $userLookup,
        private OperIrcopRepositoryInterface $ircopRepository,
        private ForbiddenVhostRepositoryInterface $forbiddenVhostRepository,
        private EventBusInterface $eventDispatcher,
    ) {}

    public function handle(NickServContext $context, RegisteredNick $account, string $value, bool $isIrcopMode = false): void
    {
        if ($this->hasForcedVhost($account->getId())) {
            $context->reply('set.vhost.forced');

            return;
        }

        $errorKey = $this->validateVhostInput($value, $account, $context, $isIrcopMode);
        if (null !== $errorKey) {
            if ('__cleared__' === $errorKey) {
                return;
            }

            $context->reply($errorKey);

            return;
        }

        $this->applyVhost($context, $account, $value, $isIrcopMode);
    }

    private function validateVhostInput(string $value, RegisteredNick $account, NickServContext $context, bool $isIrcopMode): ?string
    {
        $normalized = trim($value);
        $clearKeywords = ['OFF', ''];

        if ('' === $normalized || in_array(strtoupper($normalized), $clearKeywords, true)) {
            $account->changeVhost(null);
            $this->nickRepository->save($account);
            $this->applyClearVhost($context, $account, $isIrcopMode);
            $this->eventDispatcher->dispatch(new NickVhostChangedEvent($account->getId(), $account->getNickname(), null));
            $context->reply('set.vhost.cleared');

            return '__cleared__';
        }

        $normalized = $this->vhostValidator->normalize($normalized);

        $result = match (true) {
            null === $normalized => 'set.vhost.invalid',
            $this->isForbidden($normalized) => 'set.vhost.invalid',
            default => $this->validateVhostUniqueness($normalized, $account),
        };

        return $result;
    }

    private function validateVhostUniqueness(string $normalized, RegisteredNick $account): ?string
    {
        $existing = $this->nickRepository->findByVhost($normalized);

        return (null !== $existing && $existing->getId() !== $account->getId())
            ? 'set.vhost.taken'
            : null;
    }

    private function applyClearVhost(NickServContext $context, RegisteredNick $account, bool $isIrcopMode): void
    {
        if ($isIrcopMode) {
            $targetUser = $this->userLookup->findByNick($account->getNickname());
            if (null !== $targetUser) {
                $context->getNotifier()->setUserVhost($targetUser->uid, '', $targetUser->serverSid);
            }
        } elseif (null !== $context->sender) {
            $context->getNotifier()->setUserVhost($context->sender->uid, '', $context->sender->serverSid);
        }
    }

    private function applyVhost(NickServContext $context, RegisteredNick $account, string $value, bool $isIrcopMode): void
    {
        $normalized = $this->vhostValidator->normalize(trim($value));
        $account->changeVhost($normalized);
        $this->nickRepository->save($account);

        $displayVhost = $this->displayResolver->getDisplayVhost($normalized);

        if ($isIrcopMode) {
            $targetUser = $this->userLookup->findByNick($account->getNickname());
            if (null !== $targetUser && '' !== $displayVhost) {
                $context->getNotifier()->setUserVhost($targetUser->uid, $displayVhost, $targetUser->serverSid);
            }
        } elseif (null !== $context->sender && '' !== $displayVhost) {
            $context->getNotifier()->setUserVhost($context->sender->uid, $displayVhost, $context->sender->serverSid);
        }

        $this->eventDispatcher->dispatch(new NickVhostChangedEvent($account->getId(), $account->getNickname(), $normalized));

        $context->reply('set.vhost.success', ['vhost' => $displayVhost]);
    }

    private function hasForcedVhost(int $nickId): bool
    {
        $ircop = $this->ircopRepository->findByNickId($nickId);
        if (null === $ircop) {
            return false;
        }

        $pattern = $ircop->getRole()->getForcedVhostPattern();

        return null !== $pattern && '' !== $pattern && ForcedVhost::isValidPattern($pattern);
    }

    private function isForbidden(string $vhost): bool
    {
        $forbiddenList = $this->forbiddenVhostRepository->findAll();

        return array_any($forbiddenList, static fn ($forbidden) => $forbidden->matches($vhost));
    }
}
