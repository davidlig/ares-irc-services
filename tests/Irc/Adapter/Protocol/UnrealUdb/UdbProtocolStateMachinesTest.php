<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbReconciliationRound;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbRoundTimeout;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\SystemUdbClock;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbHelloBarrier;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbPeerAdvertisementChange;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbPeerSession;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutationQueue;
use App\Irc\Adapter\Protocol\UnrealUdb\Transfer\UdbOutboundTransferTracker;
use App\Irc\Adapter\Protocol\UnrealUdb\Transfer\UdbTransferAcknowledgement;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_repeat;

#[CoversClass(UdbHelloBarrier::class)]
#[CoversClass(UdbPeerSession::class)]
#[CoversClass(UdbReconciliationRound::class)]
#[CoversClass(UdbOutboundTransferTracker::class)]
#[CoversClass(UdbMutationQueue::class)]
#[CoversClass(SystemUdbClock::class)]
final class UdbProtocolStateMachinesTest extends TestCase
{
    #[Test]
    public function systemClockExposesAUnixTimestamp(): void
    {
        self::assertGreaterThan(0, new SystemUdbClock()->now());
    }

    #[Test]
    public function helBarrierConsumesOnlyPendingAcksAndResetsCompletely(): void
    {
        $barrier = new UdbHelloBarrier();
        self::assertNull($barrier->acknowledge());

        $ticket = $barrier->sent(100, 60);
        self::assertSame(160, $barrier->deadline());
        self::assertSame($ticket, $barrier->acknowledge());
        self::assertTrue($barrier->isConfirmed());
        self::assertFalse($barrier->hasPending());
        self::assertNull($barrier->acknowledge());

        $barrier->reset();
        self::assertFalse($barrier->isConfirmed());
        self::assertNull($barrier->deadline());
    }

    #[Test]
    public function helBarrierCanAbandonRoundTicketsWithoutLosingPeerConfirmation(): void
    {
        $barrier = new UdbHelloBarrier();
        $barrier->sent(100, 60);
        $barrier->acknowledge();
        $barrier->sent(200, 60);

        $barrier->abandonPending();

        self::assertTrue($barrier->isConfirmed());
        self::assertFalse($barrier->hasPending());
        self::assertNull($barrier->deadline());
    }

    #[Test]
    public function peerSessionRejectsIncompleteAdvertisementsAndDetectsEpochChanges(): void
    {
        $peer = new UdbPeerSession();
        $peer->setOwnName('services.example.net');
        self::assertSame('services.example.net', $peer->ownName());
        $peer->captureRemote('001', 'ircd.example.net');

        $invalid = new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: 'services.example.net');
        self::assertSame(UdbPeerAdvertisementChange::Invalid, $peer->observeAdvertisement($invalid, false));
        self::assertFalse($peer->isAuthorized());

        self::assertSame(UdbPeerAdvertisementChange::SameInstance, $peer->observeAdvertisement($this->hel(), false));
        self::assertTrue($peer->isAuthorized());
        self::assertTrue($peer->isFromPeer($this->hel()));
        self::assertTrue($peer->isDirectFromPeer($this->hel(), '002'));
        self::assertTrue($peer->isBroadcastFromPeer(new UdbFrame(UdbFrameKind::Ins, '001', '*')));
        self::assertFalse($peer->isBroadcastFromPeer(new UdbFrame(UdbFrameKind::Ins, '999', '*')));

        self::assertSame(
            UdbPeerAdvertisementChange::NewInstance,
            $peer->observeAdvertisement($this->hel(epoch: '2222222222222222'), false),
        );

        $peer->reset();
        self::assertNull($peer->remoteSid());
        self::assertNull($peer->remoteServerName());
        self::assertFalse($peer->isAuthorized());
    }

    #[Test]
    public function malformedConstructedAdvertisementsDoNotMutatePeerStateEvenDuringBootstrap(): void
    {
        foreach ([false, true] as $bootstrap) {
            $peer = new UdbPeerSession();
            $peer->setOwnName('services.example.net');
            $peer->captureRemote('001', 'ircd.example.net');
            $peer->observeAdvertisement($bootstrap ? $this->hel(propagator: 'upstream.example.net') : $this->hel(), $bootstrap);
            foreach (['', 'no-dot', 'bad/name', 'bad@name.example', str_repeat('a', 62) . '.x', "bad\xff.example", 'bad name.example'] as $selector) {
                $frame = new UdbFrame(UdbFrameKind::HelAck, '001', '002', propagator: $selector, epoch: '2222222222222222', capabilities: ['OCL']);
                self::assertSame(UdbPeerAdvertisementChange::Invalid, $peer->observeAdvertisement($frame, $bootstrap));
                self::assertSame('1111111111111111', $peer->remoteEpoch());
                self::assertTrue($peer->isAuthorized());
            }
        }
    }

    #[Test]
    public function peerSelectionDistinguishesApprovedDownstreamFromAuthorizedUpstreamBootstrap(): void
    {
        $peer = new UdbPeerSession();
        $peer->setOwnName('services.example.net');
        foreach (['?' => true, 'SERVICES.EXAMPLE.NET' => true, '-' => false, 'other.example.net' => false] as $selector => $authorized) {
            $frame = new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: $selector, epoch: '1111111111111111', capabilities: ['OCL']);
            self::assertSame(UdbPeerAdvertisementChange::SameInstance, $peer->observeAdvertisement($frame, false));
            self::assertSame($authorized, $peer->isAuthorized());
            self::assertSame('SERVICES.EXAMPLE.NET' === $selector ? UdbPeerAdvertisementChange::Invalid : UdbPeerAdvertisementChange::SameInstance, $peer->observeAdvertisement($frame, true));
            self::assertTrue($peer->isAuthorized());
        }
    }

    #[Test]
    public function reconciliationTracksOnlyCorrelatedProgressAndHasAnAbsoluteDeadline(): void
    {
        $round = new UdbReconciliationRound(inactivityTimeout: 10, absoluteTimeout: 30);
        self::assertNull($round->id());
        self::assertNull($round->nextDeadline());
        self::assertSame(UdbRoundTimeout::None, $round->timeoutAt(100));
        $round->expectBarrier(1);
        self::assertFalse($round->acknowledgeBarrier(1, 100));
        self::assertFalse($round->acknowledgeTransfer(7, UdbBlock::Ips, 100));

        $round->start(7, 100);

        self::assertSame('7', (string) $round->id());
        self::assertFalse($round->acknowledgeBarrier(1, 101));
        self::assertFalse($round->acknowledgeTransfer(7, UdbBlock::Ips, 101));
        self::assertFalse($round->acceptRes(6, UdbBlock::Ips, 105));
        self::assertTrue($round->acceptRes(7, UdbBlock::Ips, 105));
        self::assertTrue($round->acknowledgeTransfer(7, UdbBlock::Ips, 106));
        self::assertFalse($round->acceptRes(7, UdbBlock::Ips, 109));
        self::assertSame(116, $round->nextDeadline());
        self::assertSame(UdbRoundTimeout::Inactivity, $round->timeoutAt(116));

        $round->start(8, 200);
        self::assertTrue($round->acceptRes(8, UdbBlock::Ips, 209));
        $round->expectBarrier(3);
        self::assertTrue($round->acknowledgeBarrier(3, 218));
        self::assertSame(UdbRoundTimeout::Absolute, $round->timeoutAt(230));
        self::assertFalse($round->completeIfSettled(false));

        $round->start(9, 300);
        $round->expectBarrier(4);
        self::assertTrue($round->acknowledgeBarrier(4, 301));
        self::assertTrue($round->completeIfSettled(true));
        self::assertTrue($round->isCompleted());
        self::assertFalse($round->isActive());

        $round->reset();
        self::assertFalse($round->isCompleted());
    }

    #[Test]
    public function transfersRequireExactRoundTxidAndDigestAndExpireAbsolutely(): void
    {
        $tracker = new UdbOutboundTransferTracker(inactivityTimeout: 10, absoluteTimeout: 30);
        self::assertTrue($tracker->track(UdbBlock::Ips, 7, 'tx-1', 'ABCDEF12', UdbUnsignedDecimal::fromInt(0), 100));
        self::assertTrue($tracker->has(UdbBlock::Ips));
        self::assertSame(110, $tracker->nextDeadline());
        self::assertNull($tracker->firstExpired(109));
        $expired = $tracker->firstExpired(110);
        self::assertNotNull($expired);
        self::assertSame(['block' => 'I', 'timeout' => 'inactivity'], ['block' => $expired['block'], 'timeout' => $expired['timeout']]);
        self::assertSame('7', (string) $expired['roundId']);
        self::assertFalse($tracker->track(UdbBlock::Ips, 7, 'tx-2', 'ABCDEF12', UdbUnsignedDecimal::fromInt(0), 101));

        self::assertSame(
            UdbTransferAcknowledgement::Mismatched,
            $tracker->acknowledge($this->ack(roundId: 8)),
        );
        self::assertSame(
            UdbTransferAcknowledgement::Mismatched,
            $tracker->acknowledge(new UdbFrame(
                UdbFrameKind::Ack,
                '001',
                '002',
                roundId: 7,
                block: UdbBlock::Ips,
                txid: 'tx-1',
                checksum: 'ABCDEF12',
            )),
        );
        self::assertSame(UdbTransferAcknowledgement::Accepted, $tracker->acknowledge($this->ack()));
        self::assertSame(UdbTransferAcknowledgement::Unknown, $tracker->acknowledge($this->ack()));

        self::assertTrue($tracker->track(UdbBlock::Ips, 9, 'tx-1', 'ABCDEF12', UdbUnsignedDecimal::fromInt(0), 200));
        $expired = $tracker->firstExpired(230);
        self::assertNotNull($expired);
        self::assertSame('absolute', $expired['timeout']);
        self::assertSame('9', (string) $expired['roundId']);
        $tracker->reset();
        self::assertTrue($tracker->isEmpty());
        self::assertNull($tracker->nextDeadline());
    }

    #[Test]
    public function roundAndTransferCorrelationPreserveUnsignedValuesAbovePhpIntMax(): void
    {
        $roundId = UdbUnsignedDecimal::parse('18446744073709551615');
        $sameRoundId = UdbUnsignedDecimal::parse('18446744073709551615');
        self::assertNotNull($roundId);
        self::assertNotNull($sameRoundId);

        $round = new UdbReconciliationRound();
        $round->start($roundId, 100);
        self::assertTrue($round->acceptRes($sameRoundId, UdbBlock::Ips, 101));

        $tracker = new UdbOutboundTransferTracker();
        self::assertTrue($tracker->track(UdbBlock::Ips, $roundId, 'tx-1', 'ABCDEF12', UdbUnsignedDecimal::fromInt(42), 100));
        self::assertSame(UdbTransferAcknowledgement::Accepted, $tracker->acknowledge(new UdbFrame(
            UdbFrameKind::Ack,
            '001',
            '002',
            roundId: $sameRoundId,
            block: UdbBlock::Ips,
            txid: 'tx-1',
            checksum: 'ABCDEF12',
            watermark: 42,
        )));
    }

    #[Test]
    public function mutationQueueDropsOnlyTheOldestAndDrainIsAtomic(): void
    {
        $queue = new UdbMutationQueue(2);
        self::assertFalse($queue->enqueue($this->mutation('one', '1', 1)));
        self::assertFalse($queue->enqueue($this->mutation('two', '2', 2)));
        self::assertTrue($queue->enqueue($this->mutation('three', '3', 3)));

        self::assertSame(['two', 'three'], array_map(
            static fn (UdbMutation $mutation): string => $mutation->encodedPath,
            $queue->drain(),
        ));
        self::assertSame(0, $queue->count());
    }

    #[Test]
    public function mutationQueueDiscardsOnlySequencedMutationsThroughTheSnapshotWatermark(): void
    {
        $queue = new UdbMutationQueue();
        $queue->enqueue(new UdbMutation('N', 'unsequenced', UdbValue::string('0')));
        $queue->enqueue($this->mutation('before', '1', 4));
        $queue->enqueue($this->mutation('at-watermark', '2', 5));
        $queue->enqueue($this->mutation('after', '3', 6));

        $queue->discardThrough(UdbUnsignedDecimal::fromInt(5));

        self::assertSame(['unsequenced', 'after'], array_map(
            static fn (UdbMutation $mutation): string => $mutation->encodedPath,
            $queue->drain(),
        ));
    }

    private function hel(string $epoch = '1111111111111111', string $propagator = 'services.example.net'): UdbFrame
    {
        return new UdbFrame(
            UdbFrameKind::Hel,
            '001',
            '002',
            propagator: $propagator,
            epoch: $epoch,
            capabilities: ['OCL', 'OCLG'],
        );
    }

    private function ack(int $roundId = 7): UdbFrame
    {
        return new UdbFrame(
            UdbFrameKind::Ack,
            '001',
            '002',
            roundId: $roundId,
            block: UdbBlock::Ips,
            txid: 'tx-1',
            checksum: 'ABCDEF12',
            watermark: 0,
        );
    }

    private function mutation(string $path, string $value, int $sequence): UdbMutation
    {
        return new UdbMutation('N', $path, UdbValue::string($value))->assigned(
            UdbUnsignedDecimal::fromInt($sequence),
            new UdbStreamOrigin('002', '0123456789abcdef'),
        );
    }
}
