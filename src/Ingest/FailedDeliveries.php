<?php

namespace SocialDept\AtpAppview\Ingest;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use SocialDept\AtpAppview\Index\LatestRecord;
use SocialDept\AtpAppview\Ingest\Events\IdentityEvent;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Models\FailedDelivery;
use Throwable;

/**
 * Events the handlers could not take, parked so the rest of their batch is
 * acknowledged and ingest moves on, then retried, reindexed from the PDS or
 * forgotten once the cause is fixed. Kept for atp-appview.failed_deliveries.keep_days, and at most `max`.
 */
class FailedDeliveries
{
    protected const DELIVERIES = 'atp-appview:failed-deliveries:deliveries:';

    public function __construct(
        protected Cache $cache,
        protected Container $container,
    ) {
    }

    public function park(RecordEvent|IdentityEvent $event, Throwable $error): FailedDelivery
    {
        return FailedDelivery::query()->create([
            'kind' => $event instanceof RecordEvent ? 'record' : 'identity',
            'event_id' => $event->id,
            'did' => $event->did,
            'collection' => $event instanceof RecordEvent ? $event->collection : null,
            'uri' => $event instanceof RecordEvent ? $event->uri() : null,
            'payload' => self::payload($event),
            'error_class' => $error::class,
            'error' => Str::limit($error->getMessage(), 4000),
            'failed_at' => Carbon::now('UTC'),
        ]);
    }

    /**
     * Count a failed delivery for each event, and return those that have now
     * failed in max_deliveries separate deliveries. An event that fails on
     * its own each time is parked even when nothing beside it succeeded.
     *
     * @param  array<int, RecordEvent|IdentityEvent>  $events
     * @return array<int, RecordEvent|IdentityEvent>
     */
    public function failedDelivery(array $events): array
    {
        $limit = max(1, (int) config('atp-appview.failed_deliveries.max_deliveries', 3));
        $due = [];

        foreach ($events as $event) {
            $key = self::DELIVERIES.$event->id;
            $this->cache->add($key, 0, 86400);

            if ((int) $this->cache->increment($key) >= $limit) {
                $due[] = $event;
                $this->cache->forget($key);
            }
        }

        return $due;
    }

    /**
     * Run a parked event through ingest again, as configured now. On success
     * it is gone, otherwise its error is updated.
     */
    public function retry(FailedDelivery $delivery): bool
    {
        return $this->attempt($delivery, fn () => self::rebuild($delivery->payload));
    }

    /**
     * @param  Closure(): (RecordEvent|IdentityEvent)  $event
     */
    protected function attempt(FailedDelivery $delivery, Closure $event): bool
    {
        try {
            Pipeline::fromConfig($this->container)->run(new Batch([$event()]));
        } catch (Throwable $e) {
            $delivery->update([
                'error_class' => $e::class,
                'error' => Str::limit($e->getMessage(), 4000),
                'retries' => $delivery->retries + 1,
                'retried_at' => Carbon::now('UTC'),
            ]);

            return false;
        }

        $delivery->delete();

        return true;
    }

    /**
     * Fetch the record from its author's PDS as it is now, and run that
     * through ingest instead of the parked event. For a record the author
     * has since fixed, or deleted, or that Tap delivered damaged. On success
     * it is gone, otherwise its error is updated.
     *
     * @throws LogicException for an identity event, which has no record to fetch
     */
    public function reindex(FailedDelivery $delivery): bool
    {
        if ($delivery->kind !== 'record') {
            throw new LogicException('Only records can be reindexed. Retry an identity event instead.');
        }

        $payload = $delivery->payload;

        return $this->attempt($delivery, fn () => $this->container->make(LatestRecord::class)
            ->fetch($payload['did'], $payload['collection'], $payload['rkey'], (int) $payload['id']));
    }

    /**
     * Drop failed deliveries older than keep_days, then the oldest beyond max.
     *
     * @return int How many were dropped
     */
    public function prune(): int
    {
        $dropped = 0;
        $days = config('atp-appview.failed_deliveries.keep_days');
        $max = config('atp-appview.failed_deliveries.max');

        if ($days !== null && $days !== '') {
            $dropped += FailedDelivery::query()->where('failed_at', '<', Carbon::now('UTC')->subDays((int) $days))->delete();
        }

        if ($max !== null && $max !== '') {
            $keepFrom = FailedDelivery::query()->orderByDesc('id')->skip((int) $max)->value('id');

            if ($keepFrom !== null) {
                $dropped += FailedDelivery::query()->where('id', '<=', $keepFrom)->delete();
            }
        }

        return $dropped;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(RecordEvent|IdentityEvent $event): array
    {
        return $event instanceof RecordEvent
            ? ['id' => $event->id, 'did' => $event->did, 'collection' => $event->collection, 'rkey' => $event->rkey, 'action' => $event->action->value, 'cid' => $event->cid, 'rev' => $event->rev, 'record' => $event->record, 'live' => $event->live]
            : ['id' => $event->id, 'did' => $event->did, 'handle' => $event->handle, 'active' => $event->active, 'status' => $event->status];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function rebuild(array $payload): RecordEvent|IdentityEvent
    {
        return isset($payload['collection'])
            ? new RecordEvent((int) $payload['id'], $payload['did'], $payload['collection'], $payload['rkey'], RecordAction::from($payload['action']), $payload['cid'], $payload['rev'], $payload['record'], (bool) $payload['live'])
            : new IdentityEvent((int) $payload['id'], $payload['did'], $payload['handle'], (bool) $payload['active'], $payload['status']);
    }
}
