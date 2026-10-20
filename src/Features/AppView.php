<?php

namespace SocialDept\AtpAppview\Features;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use SocialDept\AtpAppview\Features;
use SocialDept\AtpAppview\Xrpc\Auth;

/**
 * What the enabled features registered, read by the parts of the package
 * they plug into. Core asks this registrar instead of naming any feature.
 */
class AppView
{
    /** @var array<class-string<Feature>, Feature> */
    protected array $features = [];

    /** The feature registering now, for its options. */
    protected ?string $current = null;

    /** @var array<int, string> */
    protected array $migrations = [];

    /** @var array<int, class-string> */
    protected array $handlers = [];

    /** @var array<int, Closure> */
    protected array $afterWrite = [];

    /** @var array<int, array{collection: string, name: string, declared: string|array<string, mixed>}> */
    protected array $fields = [];

    /** @var array<string, array{handler: class-string, type: string, auth: Auth}> */
    protected array $xrpc = [];

    /** @var array<int, Closure> */
    protected array $routes = [];

    /** @var array<int, class-string> */
    protected array $commands = [];

    /** @var array<int, Closure> */
    protected array $readFilters = [];

    /** @var array<int, Closure> */
    protected array $schedules = [];

    /** @var array<int, array{0: string, 1: Closure|string}> */
    protected array $listeners = [];

    /**
     * Make and register each feature, after checking what they require.
     *
     * @param  array<int, string>  $classes
     */
    public function load(array $classes, Container $container): void
    {
        foreach ($classes as $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Feature::class)) {
                throw new InvalidArgumentException('Every entry in atp-appview.features must be a class implementing '.Feature::class.', and '.(is_string($class) ? $class : get_debug_type($class)).' is not.');
            }

            $this->features[$class] = $container->make($class);
        }

        foreach ($this->features as $class => $feature) {
            foreach ($feature->requires() as $required) {
                if (! isset($this->features[$required])) {
                    throw new InvalidArgumentException("{$class} needs {$required}, which is not in atp-appview.features.");
                }
            }
        }

        foreach ($this->features as $class => $feature) {
            $this->current = $class;
            $feature->register($this);
        }

        $this->current = null;
    }

    /**
     * @return array<class-string<Feature>, Feature>
     */
    public function features(): array
    {
        return $this->features;
    }

    /**
     * A migrations folder, run only while the feature is on.
     */
    public function migrations(string $path): static
    {
        $this->migrations[] = $path;

        return $this;
    }

    /**
     * The feature's config section, atp-appview.{section}: these defaults
     * under what the app set, and the options given to its Features helper
     * over both.
     *
     * @param  array<string, mixed>  $defaults
     */
    public function config(string $section, array $defaults = []): static
    {
        $config = app('config');

        if (! app()->configurationIsCached()) {
            $merged = array_merge($defaults, (array) $config->get("atp-appview.{$section}", []));
            $options = $this->current === null ? [] : (array) $config->get(Features::OPTIONS.'.'.$this->current, []);

            $config->set("atp-appview.{$section}", array_merge($merged, $options));
        }

        return $this;
    }

    /**
     * A batch handler, run after the package's own on every ingested batch.
     *
     * @param  class-string  $class
     */
    public function handler(string $class): static
    {
        $this->handlers[] = $class;

        return $this;
    }

    /**
     * Work on the rows the record indexer just wrote, in its transaction:
     * fn (Connection $connection, array $rows, string $now).
     */
    public function afterWrite(Closure $callback): static
    {
        $this->afterWrite[] = $callback;

        return $this;
    }

    /**
     * A field kept in atp_record_values, declared as in atp-appview.fields.
     *
     * @param  string|array<string, mixed>  $declared
     */
    public function field(string $collection, string $name, string|array $declared): static
    {
        $this->fields[] = ['collection' => $collection, 'name' => $name, 'declared' => $declared];

        return $this;
    }

    /**
     * A built-in XRPC method, served under this NSID.
     *
     * @param  class-string  $handler
     */
    public function xrpc(string $nsid, string $handler, string $type = 'query', Auth $auth = Auth::Required): static
    {
        if (! in_array($type, ['query', 'procedure'], true)) {
            throw new InvalidArgumentException("An XRPC method is a query or a procedure, not a {$type}.");
        }

        $this->xrpc[$nsid] = ['handler' => $handler, 'type' => $type, 'auth' => $auth];

        return $this;
    }

    /**
     * Routes, such as a webhook: fn (Router $router). Skipped while routes
     * are cached, which holds them already.
     */
    public function routes(Closure $callback): static
    {
        $this->routes[] = $callback;

        return $this;
    }

    /**
     * @param  array<int, class-string>  $commands
     */
    public function commands(array $commands): static
    {
        array_push($this->commands, ...$commands);

        return $this;
    }

    /**
     * Accounts a viewer should not see records from: fn (string $viewer):
     * array of DIDs. Applied by Record::visibleTo(), RecordQuery::visibleTo(),
     * the built-in XRPC lists and notifications.
     */
    public function readFilter(Closure $callback): static
    {
        $this->readFilters[] = $callback;

        return $this;
    }

    /**
     * What the feature recommends scheduling: fn (Schedule $schedule).
     * Registered only when the app calls Features::schedule().
     */
    public function schedule(Closure $callback): static
    {
        $this->schedules[] = $callback;

        return $this;
    }

    /**
     * A listener for an event, registered while the feature is on.
     */
    public function listen(string $event, Closure|string $listener): static
    {
        $this->listeners[] = [$event, $listener];

        return $this;
    }

    /**
     * Everyone whose records the viewer should not see, from every read
     * filter, such as the graph's blocks and mutes.
     *
     * @return array<int, string>
     */
    public function hidden(string $viewer): array
    {
        // Not kept between calls: this registrar lives as long as the app,
        // and a block or mute can change between requests.
        $hidden = [];

        foreach ($this->readFilters as $filter) {
            array_push($hidden, ...array_values(array_filter((array) $filter($viewer), 'is_string')));
        }

        return array_values(array_unique($hidden));
    }

    /** @return array<int, string> */
    public function migrationPaths(): array
    {
        return $this->migrations;
    }

    /** @return array<int, class-string> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    /** @return array<int, Closure> */
    public function afterWriteCallbacks(): array
    {
        return $this->afterWrite;
    }

    /** @return array<int, array{collection: string, name: string, declared: string|array<string, mixed>}> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return array<string, array{handler: class-string, type: string, auth: Auth}> */
    public function xrpcMethods(): array
    {
        return $this->xrpc;
    }

    /** @return array<int, Closure> */
    public function routeCallbacks(): array
    {
        return $this->routes;
    }

    /** @return array<int, class-string> */
    public function commandClasses(): array
    {
        return $this->commands;
    }

    /** @return array<int, array{0: string, 1: Closure|string}> */
    public function listeners(): array
    {
        return $this->listeners;
    }

    /** @return array<int, Closure> */
    public function scheduleCallbacks(): array
    {
        return $this->schedules;
    }
}
