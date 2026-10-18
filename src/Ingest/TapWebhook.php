<?php

namespace SocialDept\AtpAppview\Ingest;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpAppview\Blocklist\Blocklist;
use SocialDept\AtpAppview\Ingest\Events\TapEvents;
use SocialDept\AtpAppview\Ingest\Exceptions\BatchFailed;
use SocialDept\AtpAppview\Ingest\Exceptions\InvalidTapEvent;

/**
 * Handles events Tap delivers by webhook instead of over the websocket, so an
 * app needs no long-running appview:consume.
 *
 * A request holds one batch, {"events": [...]}, from a Tap with batched
 * webhooks, or a single event from one without. Either way the events go
 * through the same blocklist, handlers and stats as appview:consume, and the
 * request only succeeds once every handler has committed them. Tap retries a
 * request that fails, and acknowledges every event in one that succeeds.
 */
class TapWebhook
{
    public function __construct(protected Container $container)
    {
    }

    /**
     * @param  array<string, mixed>  $payload  The decoded request body
     * @return int Events handed to the handlers
     *
     * @throws BatchFailed when the handlers still fail after the last attempt
     */
    public function handle(array $payload): int
    {
        $messages = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : [$payload];

        $buffer = new IngestBuffer(
            handlers: array_map(fn (string $class) => $this->container->make($class), (array) config('atp-appview.ingest.handlers', [])),
            ack: fn (int $id) => null,
            batchSize: max(1, count($messages)),
            maxAttempts: (int) config('atp-appview.ingest.max_attempts', 3),
            blocklist: $this->container->make(Blocklist::class),
            stats: $this->container->make(IngestStats::class),
        );

        $handled = 0;

        foreach ($messages as $message) {
            if (! is_array($message) || ($id = TapEvents::id($message)) === null) {
                Log::warning('[atp-appview] Ignoring an unreadable Tap webhook event');

                continue;
            }

            try {
                $event = TapEvents::parse($message);
            } catch (InvalidTapEvent $e) {
                // Accepted anyway: an event that can never parse would
                // otherwise make Tap retry the whole batch forever.
                Log::warning('[atp-appview] Skipping a malformed Tap event', ['id' => $id, 'error' => $e->getMessage()]);
                $event = null;
            }

            $buffer->push($id, $event);
            $handled += $event === null ? 0 : 1;
        }

        $buffer->flush();

        return $handled;
    }
}
