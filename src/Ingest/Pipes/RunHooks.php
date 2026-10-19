<?php

namespace SocialDept\AtpAppview\Ingest\Pipes;

use Closure;
use Illuminate\Contracts\Container\Container;
use SocialDept\AtpAppview\Ingest\Batch;
use SocialDept\AtpAppview\Ingest\Contracts\IngestHook;
use SocialDept\AtpAppview\Ingest\Events\RecordAction;
use SocialDept\AtpAppview\Ingest\Events\RecordEvent;
use SocialDept\AtpAppview\Ingest\Rejections;

/**
 * Runs the ingest hooks in atp-appview.ingest.hooks, in order, and drops the
 * records they reject. Only creates and updates are offered, so a delete
 * always goes through. Rejections are tallied once the rest of the pipeline
 * has taken the batch, so a retried attempt counts nothing twice.
 */
class RunHooks
{
    public function __construct(
        protected Container $container,
        protected Rejections $rejections,
    ) {
    }

    public function handle(Batch $batch, Closure $next): mixed
    {
        $hooks = array_map(fn (string $class): IngestHook => $this->container->make($class), (array) config('atp-appview.ingest.hooks', []));

        if ($hooks === []) {
            return $next($batch);
        }

        $events = $batch->events;
        $rejected = [];

        foreach ($hooks as $hook) {
            $offered = array_values(array_filter($events, self::offered(...)));

            if ($offered === []) {
                break;
            }

            $reasons = $hook->reject($offered);

            if ($reasons === []) {
                continue;
            }

            $events = array_values(array_filter($events, function ($event) use ($reasons, $hook, &$rejected) {
                if (! self::offered($event) || ! isset($reasons[$event->uri()])) {
                    return true;
                }

                $rejected[] = [$hook::class, $event, (string) $reasons[$event->uri()]];

                return false;
            }));
        }

        $result = $next(new Batch($events));

        foreach ($rejected as [$hook, $event, $reason]) {
            $this->rejections->record($hook, $event, $reason);
        }

        return $result;
    }

    protected static function offered(mixed $event): bool
    {
        return $event instanceof RecordEvent && $event->action !== RecordAction::Delete;
    }
}
