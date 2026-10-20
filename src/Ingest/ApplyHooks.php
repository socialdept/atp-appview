<?php

namespace SocialDept\AtpAppview\Ingest;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use SocialDept\AtpAppview\Ingest\Contracts\IngestHook;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;

/**
 * Offers records already indexed to the ingest hooks, as if they had just
 * arrived, and removes those a hook now rejects. A removal goes through the
 * pipeline as a delete at the stored rev, so everything kept about the
 * record (values, notifications, labels) goes with it, as it would for a
 * delete from the network.
 */
class ApplyHooks
{
    public function __construct(
        protected Container $container,
        protected DatabaseManager $db,
        protected Rejections $rejections,
    ) {
    }

    /**
     * @param  bool  $dryRun  Count what would be removed without removing it
     * @param  \Closure(int): void|null  $progress  Called with the records read so far
     * @return array<string, array<string, int>> Removed records, as hook => reason => count
     */
    public function run(?string $collection = null, bool $dryRun = false, ?\Closure $progress = null): array
    {
        $hooks = array_map(fn (string $class): IngestHook => $this->container->make($class), (array) config('atp-appview.ingest.hooks', []));
        $removed = [];

        if ($hooks === []) {
            return $removed;
        }

        $read = 0;

        $this->db->connection(config('atp-appview.index.connection'))->table('atp_records')
            ->when($collection !== null, fn ($query) => $query->where('collection', $collection))
            ->whereNull('deleted_at')
            ->select(['did', 'collection', 'rkey', 'cid', 'rev', 'record'])
            ->orderBy('uri')
            ->chunk(500, function ($rows) use ($hooks, $dryRun, $progress, &$removed, &$read) {
                $events = $rows->map(fn ($row) => new RecordEvent(
                    0,
                    $row->did,
                    $row->collection,
                    $row->rkey,
                    RecordAction::Update,
                    $row->cid,
                    (string) $row->rev,
                    $row->record === null ? null : json_decode((string) $row->record, true),
                    false,
                ))->all();
                $deletes = [];

                foreach ($hooks as $hook) {
                    $offered = array_values(array_filter($events, fn (RecordEvent $event) => ! isset($deletes[$event->uri()])));

                    if ($offered === []) {
                        break;
                    }

                    foreach ($hook->reject($offered) as $uri => $reason) {
                        $event = current(array_filter($offered, fn (RecordEvent $event) => $event->uri() === $uri));

                        if ($event === false) {
                            continue;
                        }

                        $deletes[$uri] = [$hook::class, $event, (string) $reason];
                        $removed[$hook::class][(string) $reason] = ($removed[$hook::class][(string) $reason] ?? 0) + 1;
                    }
                }

                if (! $dryRun && $deletes !== []) {
                    Pipeline::fromConfig($this->container)->run(new Batch(array_values(array_map(
                        fn (array $delete) => new RecordEvent(0, $delete[1]->did, $delete[1]->collection, $delete[1]->rkey, RecordAction::Delete, null, $delete[1]->rev, null, false),
                        $deletes,
                    ))));

                    foreach ($deletes as [$hook, $event, $reason]) {
                        $this->rejections->record($hook, $event, $reason);
                    }
                }

                $read += $rows->count();

                if ($progress !== null) {
                    $progress($read);
                }
            });

        return $removed;
    }
}
