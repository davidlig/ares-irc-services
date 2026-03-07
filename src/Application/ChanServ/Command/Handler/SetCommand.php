<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

use function array_slice;
use function implode;
use function strtoupper;

/**
 * SET <#channel> <option> <value>.
 *
 * Option handlers: FOUNDER, SUCCESSOR, DESC, URL, EMAIL, ENTRYMSG,
 * TOPICLOCK, MLOCK, SECURE. FOUNDER and SUCCESSOR require founder; others require SET level.
 */
final readonly class SetCommand implements ChanServCommandInterface
{
    private const array SUPPORTED_OPTIONS = [
        'FOUNDER', 'SUCCESSOR', 'DESC', 'URL', 'EMAIL', 'ENTRYMSG',
        'TOPICLOCK', 'MLOCK', 'SECURE',
    ];

    /** @var array<string, SetOptionHandlerInterface> */
    private array $handlers;

    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanServAccessHelper $accessHelper,
        SetFounderHandler $setFounderHandler,
        SetSuccessorHandler $setSuccessorHandler,
        SetDescHandler $setDescHandler,
        SetUrlHandler $setUrlHandler,
        SetEmailHandler $setEmailHandler,
        SetEntrymsgHandler $setEntrymsgHandler,
        SetTopiclockHandler $setTopiclockHandler,
        SetMlockHandler $setMlockHandler,
        SetSecureHandler $setSecureHandler,
    ) {
        $this->handlers = [
            'FOUNDER' => $setFounderHandler,
            'SUCCESSOR' => $setSuccessorHandler,
            'DESC' => $setDescHandler,
            'URL' => $setUrlHandler,
            'EMAIL' => $setEmailHandler,
            'ENTRYMSG' => $setEntrymsgHandler,
            'TOPICLOCK' => $setTopiclockHandler,
            'MLOCK' => $setMlockHandler,
            'SECURE' => $setSecureHandler,
        ];
    }

    public function getName(): string
    {
        return 'SET';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 3;
    }

    public function getSyntaxKey(): string
    {
        return 'set.syntax';
    }

    public function getHelpKey(): string
    {
        return 'set.help';
    }

    public function getOrder(): int
    {
        return 4;
    }

    public function getShortDescKey(): string
    {
        return 'set.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'FOUNDER', 'desc_key' => 'set.founder.short', 'help_key' => 'set.founder.help', 'syntax_key' => 'set.founder.syntax'],
            ['name' => 'SUCCESSOR', 'desc_key' => 'set.successor.short', 'help_key' => 'set.successor.help', 'syntax_key' => 'set.successor.syntax'],
            ['name' => 'DESC', 'desc_key' => 'set.desc.short', 'help_key' => 'set.desc.help', 'syntax_key' => 'set.desc.syntax'],
            ['name' => 'URL', 'desc_key' => 'set.url.short', 'help_key' => 'set.url.help', 'syntax_key' => 'set.url.syntax'],
            ['name' => 'EMAIL', 'desc_key' => 'set.email.short', 'help_key' => 'set.email.help', 'syntax_key' => 'set.email.syntax'],
            ['name' => 'ENTRYMSG', 'desc_key' => 'set.entrymsg.short', 'help_key' => 'set.entrymsg.help', 'syntax_key' => 'set.entrymsg.syntax'],
            ['name' => 'TOPICLOCK', 'desc_key' => 'set.topiclock.short', 'help_key' => 'set.topiclock.help', 'syntax_key' => 'set.topiclock.syntax'],
            ['name' => 'MLOCK', 'desc_key' => 'set.mlock.short', 'help_key' => 'set.mlock.help', 'syntax_key' => 'set.mlock.syntax'],
            ['name' => 'SECURE', 'desc_key' => 'set.secure.short', 'help_key' => 'set.secure.help', 'syntax_key' => 'set.secure.syntax'],
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

        $option = strtoupper($context->args[1] ?? '');
        $handler = $this->handlers[$option] ?? null;

        if (null === $handler) {
            $context->reply('set.unknown_option', [
                '%option%' => $option,
                '%options%' => implode(', ', self::SUPPORTED_OPTIONS),
            ]);

            return;
        }

        if ('FOUNDER' === $option || 'SUCCESSOR' === $option) {
            if (!$channel->isFounder($senderAccount->getId())) {
                throw InsufficientAccessException::forOperation($channelName, 'SET ' . $option);
            }
        } else {
            $this->accessHelper->requireLevel($channel, $senderAccount->getId(), ChannelLevel::KEY_SET, $channelName, 'SET');
        }

        $value = 'FOUNDER' === $option
            ? (trim($context->args[2] ?? ''))
            : implode(' ', array_slice($context->args, 2));

        $handler->handle($context, $channel, $value);
    }
}
