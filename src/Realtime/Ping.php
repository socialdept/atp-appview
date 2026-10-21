<?php

namespace SocialDept\AtpAppview\Realtime;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * What a channel hears: that something changed, never what it holds. The
 * browser fetches through the app's own methods, as the viewer may see it.
 * Echo listens for it as ".ping".
 */
class Ping implements ShouldBroadcastNow
{
    /**
     * @param  string  $channel  The channel's full name, private- prefix included
     * @param  array{kind: string, collection: string|null, uri: string|null, action: string|null, count: int}  $data
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $data,
    ) {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [str_starts_with($this->channel, 'private-')
            ? new PrivateChannel(substr($this->channel, strlen('private-')))
            : new Channel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'ping';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->data;
    }
}
