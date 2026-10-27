<?php

namespace SocialDept\AtpAppview\Ingest\Concerns;

use React\EventLoop\LoopInterface;
use SocialDept\AtpAppview\Ingest\Heartbeats;

/**
 * A websocket source saying it is up, for the status page. The using class
 * defines HEARTBEAT, the process name, and heartbeatState().
 */
trait BeatsHeart
{
    protected ?Heartbeats $heartbeats = null;

    /**
     * Write a heartbeat every few seconds while running.
     */
    public function withHeartbeat(Heartbeats $heartbeats): static
    {
        $this->heartbeats = $heartbeats;

        return $this;
    }

    /**
     * Write one heartbeat now. Public so tests can ask for one.
     */
    public function heartbeat(): void
    {
        $this->heartbeats?->beat(self::HEARTBEAT, $this->heartbeatState());
    }

    protected function startHeartbeat(LoopInterface $loop): void
    {
        if ($this->heartbeats === null) {
            return;
        }

        $this->heartbeat();
        $loop->addPeriodicTimer(Heartbeats::INTERVAL, fn () => $this->heartbeat());
    }

    /**
     * @return array<string, mixed>
     */
    protected function heartbeatState(): array
    {
        return [];
    }
}
