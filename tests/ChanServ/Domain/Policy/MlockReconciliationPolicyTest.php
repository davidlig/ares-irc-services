<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Policy;

use App\ChanServ\Domain\Policy\MlockReconciliationPolicy;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeCapability;
use App\ChanServ\Domain\ValueObject\ModeChange;
use App\ChanServ\Domain\ValueObject\ModeChangeAction;
use App\ChanServ\Domain\ValueObject\ModeName;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MlockReconciliationPolicy::class)]
#[CoversClass(ChannelModeLock::class)]
#[CoversClass(ChannelSetting::class)]
#[CoversClass(ModeCapability::class)]
#[CoversClass(ModeChange::class)]
#[CoversClass(ModeChangeAction::class)]
#[CoversClass(ModeName::class)]
final class MlockReconciliationPolicyTest extends TestCase
{
    private MlockReconciliationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new MlockReconciliationPolicy();
    }

    #[Test]
    public function inactiveLockDoesNotReconcile(): void
    {
        self::assertSame([], $this->policy->reconcile(
            ChannelModeLock::inactive(),
            [$this->setting('invite_only')],
            [$this->capability('invite_only')],
        ));
    }

    #[Test]
    public function activeEmptyLockRemovesSupportedCurrentSettings(): void
    {
        self::assertSame(
            [['invite_only', 'remove', null]],
            $this->describe($this->policy->reconcile(
                ChannelModeLock::active(),
                [$this->setting('invite_only')],
                [$this->capability('invite_only')],
            )),
        );
    }

    #[Test]
    public function reconciliationRemovesThenAddsWithParametersRequiredByProtocolFacts(): void
    {
        $lock = ChannelModeLock::active([
            $this->setting('limit', '25'),
            $this->setting('invite_only'),
        ]);

        self::assertSame(
            [['key', 'remove', 'old-secret'], ['limit', 'add', '25']],
            $this->describe($this->policy->reconcile(
                $lock,
                [$this->setting('key', 'old-secret'), $this->setting('invite_only')],
                [
                    $this->capability('key', unsetParameter: true),
                    $this->capability('limit', setParameter: true),
                    $this->capability('invite_only'),
                ],
            )),
        );
    }

    #[Test]
    public function protectedAndUnsupportedSettingsAreNotRemovedOrAdded(): void
    {
        self::assertSame([], $this->policy->reconcile(
            ChannelModeLock::active([$this->setting('unknown')]),
            [$this->setting('registered'), $this->setting('unsupported_current')],
            [$this->capability('registered', protected: true)],
        ));
    }

    #[Test]
    public function modeNamesRemainCaseSensitive(): void
    {
        self::assertSame(
            [['moderated', 'remove', null], ['Moderated', 'add', null]],
            $this->describe($this->policy->reconcile(
                ChannelModeLock::active([$this->setting('Moderated')]),
                [$this->setting('moderated')],
                [$this->capability('moderated'), $this->capability('Moderated')],
            )),
        );
    }

    #[Test]
    public function duplicateLockSettingsUseLastSemanticSnapshot(): void
    {
        $lock = ChannelModeLock::active([$this->setting('limit', '10'), $this->setting('limit', '20')]);

        self::assertCount(1, $lock->settings);
        self::assertSame('20', $lock->setting(new ModeName('limit'))?->parameter);
    }

    #[Test]
    public function unsafeParameterizedChangesAreOmittedWhenSnapshotParameterIsMissing(): void
    {
        self::assertSame([], $this->policy->reconcile(
            ChannelModeLock::active([$this->setting('limit')]),
            [$this->setting('key')],
            [
                $this->capability('limit', setParameter: true),
                $this->capability('key', unsetParameter: true),
            ],
        ));
    }

    #[Test]
    public function modeNameCannotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModeName('');
    }

    private function setting(string $name, ?string $parameter = null): ChannelSetting
    {
        return new ChannelSetting(new ModeName($name), $parameter);
    }

    private function capability(
        string $name,
        bool $setParameter = false,
        bool $unsetParameter = false,
        bool $protected = false,
    ): ModeCapability {
        return new ModeCapability(new ModeName($name), $setParameter, $unsetParameter, $protected);
    }

    /**
     * @param list<ModeChange> $changes
     *
     * @return list<array{string, string, ?string}>
     */
    private function describe(array $changes): array
    {
        return array_map(
            static fn (ModeChange $change): array => [$change->mode->value, $change->action->value, $change->parameter],
            $changes,
        );
    }
}
