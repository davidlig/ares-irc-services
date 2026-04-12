<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\Port\ChannelModeSupportInterface;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

use function in_array;
use function sprintf;
use function strtoupper;

/**
 * LEVELS <#channel> LIST|SET|RESET [level] [value].
 *
 * Founder only. LIST/SET hide keys that depend on unsupported modes
 * (AUTOADMIN, AUTOHALFOP, ADMINDEADMIN, HALFOPDEHALFOP).
 */
final readonly class LevelsCommand implements ChanServCommandInterface
{
    /** Level keys that require admin mode (a). Hidden if !hasAdmin(). */
    private const array ADMIN_LEVEL_KEYS = [
        ChannelLevel::KEY_AUTOADMIN,
        ChannelLevel::KEY_ADMINDEADMIN,
    ];

    /** Level keys that require halfop mode (h). Hidden if !hasHalfOp(). */
    private const array HALFOP_LEVEL_KEYS = [
        ChannelLevel::KEY_AUTOHALFOP,
        ChannelLevel::KEY_HALFOPDEHALFOP,
    ];

    /** All level keys in display order (excluding mode-dependent when filtered). */
    private const array ALL_LEVEL_KEYS = [
        ChannelLevel::KEY_AUTOADMIN,
        ChannelLevel::KEY_AUTOOP,
        ChannelLevel::KEY_AUTOHALFOP,
        ChannelLevel::KEY_AUTOVOICE,
        ChannelLevel::KEY_SET,
        ChannelLevel::KEY_ADMINDEADMIN,
        ChannelLevel::KEY_OPDEOP,
        ChannelLevel::KEY_HALFOPDEHALFOP,
        ChannelLevel::KEY_VOICEDEVOICE,
        ChannelLevel::KEY_INVITE,
        ChannelLevel::KEY_ACCESSLIST,
        ChannelLevel::KEY_ACCESSCHANGE,
        ChannelLevel::KEY_MEMOREAD,
        ChannelLevel::KEY_MEMOCHANGE,
        ChannelLevel::KEY_AKICK,
        ChannelLevel::KEY_NOJOIN,
    ];

    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelLevelRepositoryInterface $levelRepository,
    ) {
    }

    public function getName(): string
    {
        return 'LEVELS';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'levels.syntax';
    }

    public function getHelpKey(): string
    {
        return 'levels.help';
    }

    public function getOrder(): int
    {
        return 9;
    }

    public function getShortDescKey(): string
    {
        return 'levels.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'LIST', 'desc_key' => 'levels.list.short', 'help_key' => 'levels.list.help', 'syntax_key' => 'levels.list.syntax'],
            ['name' => 'SET', 'desc_key' => 'levels.set.short', 'help_key' => 'levels.set.help', 'syntax_key' => 'levels.set.syntax'],
            ['name' => 'RESET', 'desc_key' => 'levels.reset.short', 'help_key' => 'levels.reset.help', 'syntax_key' => 'levels.reset.syntax'],
        ];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return 'IDENTIFIED';
    }

    public function allowsSuspendedChannel(): bool
    {
        return false;
    }

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function execute(ChanServContext $context): void
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($channelName);
        }

        $senderAccount = $context->senderAccount;
        if (null === $senderAccount) {
            $context->reply('error.not_identified');

            return;
        }

        if (!$context->isLevelFounder && !$channel->isFounder($senderAccount->getId())) {
            throw InsufficientAccessException::forOperation($channelName, 'LEVELS');
        }

        $sub = strtoupper($context->args[1] ?? '');
        $modeSupport = $context->getChannelModeSupport();

        switch ($sub) {
            case 'LIST':
                $this->doList($context, $channel, $modeSupport);
                break;
            case 'SET':
                $this->doSet($context, $channel, $channelName, $modeSupport);
                break;
            case 'RESET':
                $this->doReset($context, $channel);
                break;
            default:
                $context->reply('levels.unknown_sub', ['%sub%' => $sub]);
        }
    }

    /** @return list<string> */
    private function visibleLevelKeys(ChannelModeSupportInterface $modeSupport): array
    {
        $keys = [];
        foreach (self::ALL_LEVEL_KEYS as $key) {
            if (in_array($key, self::ADMIN_LEVEL_KEYS, true) && !$modeSupport->hasAdmin()) {
                continue;
            }
            if (in_array($key, self::HALFOP_LEVEL_KEYS, true) && !$modeSupport->hasHalfOp()) {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    private function doList(ChanServContext $context, \App\Domain\ChanServ\Entity\RegisteredChannel $channel, ChannelModeSupportInterface $modeSupport): void
    {
        $visibleKeys = $this->visibleLevelKeys($modeSupport);
        $byKey = [];
        foreach ($this->levelRepository->listByChannel($channel->getId()) as $level) {
            $byKey[$level->getLevelKey()] = $level->getValue();
        }

        $context->reply('levels.list.header');
        foreach ($visibleKeys as $key) {
            $value = $byKey[$key] ?? ChannelLevel::getDefault($key);
            $context->replyRaw(sprintf('  %s %s', $key, $value));
        }
    }

    private function doSet(ChanServContext $context, \App\Domain\ChanServ\Entity\RegisteredChannel $channel, string $channelName, ChannelModeSupportInterface $modeSupport): void
    {
        $levelKey = strtoupper(trim($context->args[2] ?? ''));
        $valueStr = trim($context->args[3] ?? '');
        if ('' === $levelKey || '' === $valueStr) {
            $context->reply('error.syntax', ['syntax' => $context->trans('levels.set.syntax')]);

            return;
        }

        $visibleKeys = $this->visibleLevelKeys($modeSupport);
        if (!in_array($levelKey, $visibleKeys, true)) {
            $context->reply('levels.unknown_key', ['%key%' => $levelKey]);

            return;
        }

        $value = (int) $valueStr;
        if ($value < ChannelLevel::LEVEL_MIN || $value > ChannelLevel::LEVEL_MAX) {
            $context->reply('levels.value_range', [
                '%min%' => (string) ChannelLevel::LEVEL_MIN,
                '%max%' => (string) ChannelLevel::LEVEL_MAX,
            ]);

            return;
        }

        $existing = $this->levelRepository->findByChannelAndKey($channel->getId(), $levelKey);
        if (null !== $existing) {
            $existing->updateLevelValue($value);
            $this->levelRepository->save($existing);
        } else {
            $level = new ChannelLevel($channel->getId(), $levelKey, $value);
            $this->levelRepository->save($level);
        }

        $context->reply('levels.set.done', ['%key%' => $levelKey, '%value%' => (string) $value]);
    }

    private function doReset(ChanServContext $context, \App\Domain\ChanServ\Entity\RegisteredChannel $channel): void
    {
        $this->levelRepository->removeAllForChannel($channel->getId());
        $context->reply('levels.reset.done');
    }
}
