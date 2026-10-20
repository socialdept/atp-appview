<?php

namespace SocialDept\AtpAppview\Ingest;

use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline as LaravelPipeline;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Features\AppView;
use SocialDept\AtpAppview\Features\Moderation as ModerationFeature;
use SocialDept\AtpAppview\Features\Notifications as NotificationsFeature;
use SocialDept\AtpAppview\Ingest\Contracts\BatchHandler;
use SocialDept\AtpAppview\Labels\LabelIndexer;
use SocialDept\AtpAppview\Notifications\NotificationIndexer;

/**
 * One attempt at a batch: it passes through the pipes in
 * atp-appview.ingest.pipes, by default the blocklist, the ingest repairs
 * and the ingest hooks, and every handler takes what is left.
 *
 * A pipe is a class with handle(Batch $batch, Closure $next), as in any
 * Laravel pipeline. Code after $next() runs once the handlers have taken
 * the batch.
 *
 * An attempt runs in one transaction on the index connection, so one that
 * fails leaves none of its writes behind, whichever handler failed. What a
 * handler does outside the database, such as calling a service, cannot be
 * undone, so handlers must take a batch twice without harm.
 */
class Pipeline
{
    /**
     * @param  array<int, BatchHandler>  $handlers
     * @param  array<int, class-string|object>|null  $pipes  Null for those in config
     */
    public function __construct(
        protected Container $container,
        protected array $handlers,
        protected ?array $pipes = null,
    ) {
    }

    /**
     * As configured in atp-appview.ingest.
     */
    public static function fromConfig(Container $container): self
    {
        return new self($container, self::handlers($container));
    }

    /**
     * The handlers in atp-appview.ingest.handlers, then those enabled
     * features add, such as moderation's LabelIndexer and notifications'
     * NotificationIndexer. RecordIndexer and LabelIndexer each ignore the
     * other's events. Feature handlers read what RecordIndexer stored, so
     * they run after the package's own.
     *
     * @return array<int, BatchHandler>
     */
    public static function handlers(Container $container): array
    {
        $classes = (array) config('atp-appview.ingest.handlers', []);

        // A config published before these were features lists their
        // indexers, which must not run while the feature is off.
        foreach ([ModerationFeature::class => LabelIndexer::class, NotificationsFeature::class => NotificationIndexer::class] as $feature => $indexer) {
            if (! Features::enabled($feature)) {
                $classes = array_values(array_diff($classes, [$indexer]));
            }
        }

        // Then what enabled features add, after the package's own.
        foreach ($container->bound(AppView::class) ? $container->make(AppView::class)->handlers() : [] as $class) {
            if (! in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        return array_map(fn (string $class) => $container->make($class), $classes);
    }

    /**
     * @throws \Throwable when a pipe or handler fails
     */
    public function run(Batch $batch): void
    {
        // Outside an application, in unit tests, there is no database to wrap.
        if (! $this->container->bound('db')) {
            $this->through($batch);

            return;
        }

        DB::connection(config('atp-appview.index.connection'))->transaction(fn () => $this->through($batch));
    }

    protected function through(Batch $batch): void
    {
        (new LaravelPipeline($this->container))
            ->send($batch)
            ->through($this->pipes ?? (array) config('atp-appview.ingest.pipes', []))
            ->then(function (Batch $batch) {
                if (! $batch->isEmpty()) {
                    foreach ($this->handlers as $handler) {
                        $handler->handle($batch);
                    }
                }

                return $batch;
            });
    }
}
