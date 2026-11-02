<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Set;

use App\NickServ\Application\Model\NickOperationActor;
use App\NickServ\Application\Port\Out\AutomaticModeCatalog;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickAutomaticModesChangedEvent;
use App\NickServ\Application\Service\NickProtectabilityStatus;
use App\NickServ\Application\Service\NickTargetValidator;
use App\NickServ\Domain\ValueObject\AutomaticModeProfile;

use function array_flip;
use function array_unique;
use function array_values;
use function implode;
use function preg_match;
use function str_split;

final readonly class SetAutomaticModesHandler implements SetAutomaticModesHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nicks,
        private NickTargetValidator $targets,
        private AutomaticModeCatalog $catalog,
        private NickNetworkUserLookup $users,
        private NickServEventPublisher $events,
        private Clock $clock,
    ) {}

    public function handle(NickOperationActor $actor, string $nickname, string $modes): SetAutomaticModesResult
    {
        $target = $this->targets->validate($nickname);
        if (!$target->isAllowed()) {
            return new SetAutomaticModesResult(match ($target->status) {
                NickProtectabilityStatus::IsRoot => SetAutomaticModesOutcome::Root,
                NickProtectabilityStatus::IsIrcop => SetAutomaticModesOutcome::Ircop,
                default => SetAutomaticModesOutcome::Service,
            });
        }
        if ($this->users->findByNick($nickname)?->isOper) {
            return new SetAutomaticModesResult(SetAutomaticModesOutcome::Ircop);
        }
        $account = $target->account;
        if (null === $account) {
            return new SetAutomaticModesResult(SetAutomaticModesOutcome::NotRegistered);
        }
        $intentions = [];
        if ('' !== $modes) {
            if (1 !== preg_match('/^[A-Za-z]{1,64}\\z/', $modes)) {
                return new SetAutomaticModesResult(SetAutomaticModesOutcome::InvalidModes);
            }
            $available = $this->catalog->available();
            if (null === $available) {
                return new SetAutomaticModesResult(SetAutomaticModesOutcome::Unavailable);
            }
            $reverse = array_flip($available);
            foreach (str_split($modes) as $letter) {
                if (!isset($reverse[$letter])) {
                    return new SetAutomaticModesResult(SetAutomaticModesOutcome::InvalidModes);
                }
                $intentions[] = $reverse[$letter];
            }
        }
        $profile = new AutomaticModeProfile($intentions);
        $before = $account->getAutomaticModes()->intentions;
        if ($before === $profile->intentions) {
            return new SetAutomaticModesResult(SetAutomaticModesOutcome::Unchanged, $modes);
        }
        $account->changeAutomaticModes($profile);
        $this->nicks->save($account);
        $this->events->publish(new NickAutomaticModesChangedEvent($account->getId(), $account->getNickname(), $before, $profile->intentions, $actor->nickname, $actor->accountId, $actor->ip, $actor->host, $this->clock->now()));

        return new SetAutomaticModesResult(SetAutomaticModesOutcome::Changed, implode('', array_values(array_unique(str_split($modes)))));
    }
}
