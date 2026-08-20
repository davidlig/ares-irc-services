<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\PasswordMigrationStateInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Event\NickPasswordProvidedEvent;
use App\Domain\NickServ\Event\NickVhostChangedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Event\OperIrcopChangedEvent;
use App\Domain\OperServ\Event\OperRoleForcedVhostChangedEvent;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\ValueObject\ForcedVhost;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordReceivedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;
use function explode;
use function hash;
use function sprintf;
use function trim;

final readonly class UdbNickSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ActiveConnectionHolderInterface $connectionHolder,
        private RegisteredNickRepositoryInterface $nickRepository,
        private PasswordMigrationStateInterface $migrationState,
        private OperIrcopRepositoryInterface $ircopRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickPasswordProvidedEvent::class => 'onPasswordProvided',
            NickVhostChangedEvent::class => 'onVhostChanged',
            NickDropEvent::class => 'onNickDrop',
            UdbSyncRequestedEvent::class => 'onSyncRequested',
            UdbRecordReceivedEvent::class => 'onRecordReceived',
            OperRoleForcedVhostChangedEvent::class => 'onOperRoleForcedVhostChanged',
            OperIrcopChangedEvent::class => 'onOperIrcopChanged',
        ];
    }

    public function onNickDrop(NickDropEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $this->connectionHolder->writeLine(sprintf('DB * DEL N::%s', $event->nickname));
    }

    public function onPasswordProvided(NickPasswordProvidedEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $this->migrationState->markAsMigrated($event->nickname);

        $hash = hash('sha256', $event->plaintextPassword);
        $this->connectionHolder->writeLine(sprintf('DB * INS N::%s::pass sha256:%s', $event->nickname, $hash));

        if (null !== $event->nickId) {
            $nick = $this->nickRepository->findById($event->nickId);
            if (null !== $nick) {
                $effectiveVhost = $this->resolveEffectiveVhost($nick);
                if (null !== $effectiveVhost) {
                    $this->connectionHolder->writeLine(sprintf('DB * INS N::%s::vhost %s', $event->nickname, $effectiveVhost));
                }
            }
        }
    }

    public function onVhostChanged(NickVhostChangedEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $account = $this->nickRepository->findById($event->nickId);
        if (null === $account) {
            return;
        }

        $effectiveVhost = $this->resolveEffectiveVhost($account);
        if (null !== $effectiveVhost) {
            $this->connectionHolder->writeLine(sprintf('DB * INS N::%s::vhost %s', $event->nickname, $effectiveVhost));
        } else {
            $this->connectionHolder->writeLine(sprintf('DB * DEL N::%s::vhost', $event->nickname));
        }
    }

    public function onSyncRequested(UdbSyncRequestedEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        if ('N' === $event->block) {
            $this->connectionHolder->writeLine('DB * REQ N');
        }
    }

    public function onRecordReceived(UdbRecordReceivedEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        // Example key: N::davidlig::pass
        $parts = explode('::', $event->key);
        if (count($parts) < 3 || 'N' !== $parts[0]) {
            return;
        }

        $nickname = $parts[1];
        $property = $parts[2];

        $account = $this->nickRepository->findByNick($nickname);
        if (null === $account) {
            // Delete inconsistencies
            $this->connectionHolder->writeLine(sprintf('DB * DEL N::%s', $nickname));

            return;
        }

        $effectiveVhost = $this->resolveEffectiveVhost($account);

        if ('pass' === $property) {
            $this->migrationState->markAsMigrated($nickname);
            if (null !== $effectiveVhost) {
                $this->connectionHolder->writeLine(sprintf('DB * INS N::%s::vhost %s', $nickname, $effectiveVhost));
            }
        } elseif ('vhost' === $property) {
            if (null === $effectiveVhost) {
                $this->connectionHolder->writeLine(sprintf('DB * DEL N::%s::vhost', $nickname));
            } elseif ($effectiveVhost !== $event->value) {
                $this->connectionHolder->writeLine(sprintf('DB * INS N::%s::vhost %s', $nickname, $effectiveVhost));
            }
        }
    }

    public function onOperRoleForcedVhostChanged(OperRoleForcedVhostChangedEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $ircops = $this->ircopRepository->findByRoleId($event->roleId);
        foreach ($ircops as $ircop) {
            $account = $this->nickRepository->findById($ircop->getNickId());
            if (null === $account) {
                continue;
            }

            $effectiveVhost = $this->resolveEffectiveVhost($account);
            if (null !== $effectiveVhost) {
                $this->connectionHolder->writeLine(sprintf('DB * INS N::%s::vhost %s', $account->getNickname(), $effectiveVhost));
            } else {
                $this->connectionHolder->writeLine(sprintf('DB * DEL N::%s::vhost', $account->getNickname()));
            }
        }
    }

    public function onOperIrcopChanged(OperIrcopChangedEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $account = $this->nickRepository->findById($event->nickId);
        if (null === $account) {
            return;
        }

        $effectiveVhost = $this->resolveEffectiveVhost($account);
        if (null !== $effectiveVhost) {
            $this->connectionHolder->writeLine(sprintf('DB * INS N::%s::vhost %s', $event->nickname, $effectiveVhost));
        } else {
            $this->connectionHolder->writeLine(sprintf('DB * DEL N::%s::vhost', $event->nickname));
        }
    }

    private function resolveEffectiveVhost(RegisteredNick $account): ?string
    {
        $ircop = $this->ircopRepository->findByNickId($account->getId());
        if (null !== $ircop) {
            $role = $ircop->getRole();
            $forcedPattern = $role->getForcedVhostPattern();
            if (null !== $forcedPattern && '' !== $forcedPattern && ForcedVhost::isValidPattern($forcedPattern)) {
                return ForcedVhost::fromPattern($forcedPattern)->generateVhost($account->getNickname());
            }
        }

        $personalVhost = $account->getVhost();
        if (null !== $personalVhost && '' !== trim($personalVhost)) {
            return trim($personalVhost);
        }

        return null;
    }
}
