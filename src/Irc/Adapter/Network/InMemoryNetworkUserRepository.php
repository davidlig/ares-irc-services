<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Adapter\Network\Event\CaseMappingChangedEvent;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\CaseMapping;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function array_values;
use function count;

class InMemoryNetworkUserRepository implements NetworkUserRepositoryInterface, EventSubscriberInterface
{
    /** @var array<string, NetworkUser> keyed by UID string */
    private array $users = [];

    /** @var array<string, string> lowercase nick => UID (for O(1) findByNick) */
    private array $byNick = [];

    /** @var array<string, array<string, string>> serverSid => [uid => uid] */
    private array $byServerSid = [];

    public function __construct(private CaseMapping $caseMapping = CaseMapping::Ascii) {}

    public static function getSubscribedEvents(): array
    {
        return [CaseMappingChangedEvent::class => 'onCaseMappingChanged'];
    }

    public function onCaseMappingChanged(CaseMappingChangedEvent $event): void
    {
        $this->caseMapping = $event->mapping;
        $this->byNick = [];
        foreach ($this->users as $user) {
            $user->useCaseMapping($event->mapping);
            $this->byNick[$event->mapping->fold($user->getNick()->value)] = $user->uid->value;
        }
    }

    public function add(NetworkUser $user): void
    {
        $user->useCaseMapping($this->caseMapping);
        $existing = $this->users[$user->uid->value] ?? null;
        if (null !== $existing) {
            unset($this->byNick[$this->caseMapping->fold($existing->getNick()->value)]);
            unset($this->byServerSid[$existing->serverSid][$existing->uid->value]);
            if ([] === ($this->byServerSid[$existing->serverSid] ?? [])) {
                unset($this->byServerSid[$existing->serverSid]);
            }
        }

        $this->users[$user->uid->value] = $user;
        $this->byNick[$this->caseMapping->fold($user->getNick()->value)] = $user->uid->value;
        $this->byServerSid[$user->serverSid][$user->uid->value] = $user->uid->value;
    }

    public function removeByUid(Uid $uid): void
    {
        $user = $this->users[$uid->value] ?? null;
        if (null !== $user) {
            unset($this->byNick[$this->caseMapping->fold($user->getNick()->value)]);
            unset($this->byServerSid[$user->serverSid][$uid->value]);
            if ([] === ($this->byServerSid[$user->serverSid] ?? [])) {
                unset($this->byServerSid[$user->serverSid]);
            }
        }
        unset($this->users[$uid->value]);
    }

    public function updateNick(Uid $uid, Nick $oldNick, Nick $newNick): void
    {
        $user = $this->users[$uid->value] ?? null;
        if (null !== $user) {
            $user->changeNick($newNick);
        }
        unset($this->byNick[$this->caseMapping->fold($oldNick->value)]);
        $this->byNick[$this->caseMapping->fold($newNick->value)] = $uid->value;
    }

    public function findByUid(Uid $uid): ?NetworkUser
    {
        return $this->users[$uid->value] ?? null;
    }

    public function findByNick(Nick $nick): ?NetworkUser
    {
        $uid = $this->byNick[$this->caseMapping->fold($nick->value)] ?? null;
        if (null === $uid) {
            return null;
        }

        return $this->users[$uid] ?? null;
    }

    public function all(): array
    {
        return array_values($this->users);
    }

    public function count(): int
    {
        return count($this->users);
    }

    public function updateVirtualHost(Uid $uid, string $vhost): void
    {
        $user = $this->users[$uid->value] ?? null;
        if (null !== $user) {
            $user->updateVirtualHost($vhost);
        }
    }

    /**
     * @param string[] $serverSids
     *
     * @return NetworkUser[]
     */
    public function findByServerSids(array $serverSids): array
    {
        $result = [];
        foreach ($serverSids as $serverSid) {
            $uids = $this->byServerSid[$serverSid] ?? [];
            foreach ($uids as $uid) {
                if (isset($this->users[$uid])) {
                    $result[] = $this->users[$uid];
                }
            }
        }

        return $result;
    }
}
