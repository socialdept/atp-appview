<?php

namespace SocialDept\AtpAppview\Ingest\Pipes;

use Closure;
use Illuminate\Contracts\Container\Container;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\IngestRepair;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Repairs;

/**
 * Runs the repairs in atp-appview.ingest.repairs, in order, on every create
 * and update, each seeing the record as the one before left it. Repairs are
 * tallied by repair and collection once the rest of the pipeline has taken
 * the batch, so a retried attempt counts nothing twice.
 */
class RunRepairs
{
    public function __construct(
        protected Container $container,
        protected Repairs $repairs,
    ) {
    }

    public function handle(Batch $batch, Closure $next): mixed
    {
        $repairs = array_map(fn (string $class): IngestRepair => $this->container->make($class), (array) config('atp-appview.ingest.repairs', []));

        if ($repairs === []) {
            return $next($batch);
        }

        $repaired = [];

        $events = array_map(function ($event) use ($repairs, &$repaired) {
            if (! $event instanceof RecordEvent || $event->action === RecordAction::Delete || $event->record === null) {
                return $event;
            }

            foreach ($repairs as $repair) {
                $record = $repair->repair($event);

                if ($record !== null && $record !== $event->record) {
                    $event = $event->repaired($record, $repair::class);
                    $key = $repair::class.'|'.$event->collection;
                    $repaired[$key] = [$repair::class, $event, ($repaired[$key][2] ?? 0) + 1];
                }
            }

            return $event;
        }, $batch->events);

        $result = $next(new Batch($events));

        foreach ($repaired as [$repair, $event, $times]) {
            $this->repairs->record($repair, $event, $times);
        }

        return $result;
    }
}
