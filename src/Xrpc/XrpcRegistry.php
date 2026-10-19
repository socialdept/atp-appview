<?php

namespace SocialDept\AtpAppview\Xrpc;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SocialDept\AtpAppview\Views\View;
use SocialDept\AtpAppview\Xrpc\Attributes\Xrpc;
use SocialDept\AtpAppview\Xrpc\Handlers\DefaultProcedureHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\DefaultQueryHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\SyncRecordHandler;
use SocialDept\AtpAppview\Xrpc\Handlers\ViewQueryHandler;
use SocialDept\AtpSchema\Data\LexiconDocument;
use SocialDept\AtpSchema\Facades\Schema;

/**
 * Every XRPC method the application serves, keyed by NSID.
 *
 * Handlers come from atp-appview.xrpc.handlers and from classes carrying the
 * #[Xrpc] attribute in the discovery directory. Queries mapped to a collection
 * in atp-appview.xrpc.collections, without a handler of their own, are served
 * by DefaultQueryHandler.
 */
class XrpcRegistry
{
    /**
     * Every method's NSID, handler and auth, read without loading lexicons.
     *
     * @var array<string, array{handler: class-string, auth: Auth, collection: string|null, attribute: Xrpc|null, action?: string}>|null
     */
    protected ?array $routes = null;

    /**
     * Methods resolved so far, lexicon included.
     *
     * @var array<string, XrpcMethod>
     */
    protected array $methods = [];

    /**
     * What a route needs: the NSID, its handler and its auth. Nothing here
     * loads a lexicon, so registering routes at boot never reads the cache.
     * Loading lexicons at boot cost a cache read per method on every request,
     * and on a fresh install it failed before the cache table existed.
     *
     * @return array<string, array{handler: class-string, auth: Auth, collection: string|null, attribute: Xrpc|null}>
     */
    public function routes(): array
    {
        return $this->routes ??= $this->buildRoutes();
    }

    /**
     * Every method, each with its lexicon. Loads every lexicon.
     *
     * @return array<string, XrpcMethod>
     */
    public function all(): array
    {
        foreach (array_keys($this->routes()) as $nsid) {
            $this->find($nsid);
        }

        ksort($this->methods);

        return $this->methods;
    }

    /**
     * One method with its lexicon, loaded the first time it is asked for.
     */
    public function find(string $nsid): ?XrpcMethod
    {
        if (isset($this->methods[$nsid])) {
            return $this->methods[$nsid];
        }

        $route = $this->routes()[$nsid] ?? null;

        if ($route === null) {
            return null;
        }

        return $this->methods[$nsid] = match (true) {
            isset($route['view']) => $this->viewMethodFor($nsid, $route['view']),
            isset($route['action']) && $route['collection'] !== null => $this->defaultProcedureFor($nsid, $route['collection'], $route['action']),
            $route['handler'] === SyncRecordHandler::class => $this->builtInProcedureFor($nsid, SyncRecordHandler::class),
            $route['collection'] !== null => $this->defaultMethodFor($nsid, $route['collection']),
            default => $this->methodFor($route['handler'], $route['attribute']),
        };
    }

    /**
     * @return array<string, array{handler: class-string, auth: Auth, collection: string|null, attribute: Xrpc|null}>
     */
    protected function buildRoutes(): array
    {
        $routes = [];

        foreach (array_unique([...config('atp-appview.xrpc.handlers', []), ...$this->discover()]) as $handler) {
            $attribute = $this->attributeOf($handler);

            if (isset($routes[$attribute->nsid])) {
                throw new InvalidArgumentException("Two handlers serve {$attribute->nsid}: {$routes[$attribute->nsid]['handler']} and {$handler}.");
            }

            $routes[$attribute->nsid] = ['handler' => $handler, 'auth' => $attribute->auth, 'collection' => null, 'attribute' => $attribute];
        }

        foreach ((array) config('atp-appview.xrpc.collections', []) as $nsid => $collection) {
            // A handler class written for the method takes precedence.
            if (! isset($routes[$nsid])) {
                $routes[(string) $nsid] = ['handler' => DefaultQueryHandler::class, 'auth' => Auth::None, 'collection' => (string) $collection, 'attribute' => null];
            }
        }

        foreach ((array) config('atp-appview.xrpc.views', []) as $nsid => $view) {
            // A handler class written for the method takes precedence.
            if (! isset($routes[$nsid])) {
                $routes[(string) $nsid] = ['handler' => ViewQueryHandler::class, 'auth' => Auth::Optional, 'collection' => null, 'attribute' => null, 'view' => (string) (is_array($view) ? ($view['view'] ?? '') : $view)];
            }
        }

        foreach ((array) config('atp-appview.xrpc.procedures', []) as $nsid => $procedure) {
            $collection = $procedure['collection'] ?? null;
            $action = $procedure['action'] ?? null;

            if (! is_string($collection) || ! in_array($action, DefaultProcedureHandler::ACTIONS, true)) {
                throw new InvalidArgumentException("atp-appview.xrpc.procedures.{$nsid} needs a collection and an action: ".implode(', ', DefaultProcedureHandler::ACTIONS).'.');
            }

            if (! isset($routes[$nsid])) {
                $routes[(string) $nsid] = ['handler' => DefaultProcedureHandler::class, 'auth' => Auth::Required, 'collection' => $collection, 'attribute' => null, 'action' => $action];
            }
        }

        if (is_string($sync = config('atp-appview.xrpc.sync_record')) && $sync !== '' && ! isset($routes[$sync])) {
            $routes[$sync] = ['handler' => SyncRecordHandler::class, 'auth' => Auth::Required, 'collection' => null, 'attribute' => null];
        }

        ksort($routes);

        return $routes;
    }

    protected function attributeOf(string $handler): Xrpc
    {
        $attribute = (new ReflectionClass($handler))->getAttributes(Xrpc::class)[0] ?? null;

        if ($attribute === null) {
            throw new InvalidArgumentException("{$handler} has no #[Xrpc] attribute.");
        }

        return $attribute->newInstance();
    }

    /**
     * A procedure that writes one collection through the viewer's server-held
     * session. A lexicon is optional, since the record is validated against
     * its collection's own lexicon, but one that exists must be a procedure.
     */
    protected function defaultProcedureFor(string $nsid, string $collection, string $action): XrpcMethod
    {
        $lexicon = $this->procedureLexicon($nsid);

        return new XrpcMethod($nsid, DefaultProcedureHandler::class, 'procedure', Auth::Required, $lexicon, $collection, $action);
    }

    /**
     * @param  class-string  $handler
     */
    protected function builtInProcedureFor(string $nsid, string $handler): XrpcMethod
    {
        return new XrpcMethod($nsid, $handler, 'procedure', Auth::Required, $this->procedureLexicon($nsid));
    }

    protected function procedureLexicon(string $nsid): ?LexiconDocument
    {
        $lexicon = Schema::find($nsid);

        if ($lexicon !== null && ! $lexicon->isProcedure()) {
            throw new InvalidArgumentException("{$nsid} is a {$lexicon->getLexiconType()}, but it is configured as a procedure.");
        }

        return $lexicon;
    }

    /**
     * A query served straight from the index for one collection.
     */
    protected function defaultMethodFor(string $nsid, string $collection): XrpcMethod
    {
        $lexicon = Schema::find($nsid)
            ?? throw new InvalidArgumentException("No lexicon found for {$nsid}, which atp-appview.xrpc.collections maps to {$collection}.");

        if (! $lexicon->isQuery()) {
            throw new InvalidArgumentException("{$nsid} is a {$lexicon->getLexiconType()}. Default handlers only serve queries, so write a handler class for it.");
        }

        return new XrpcMethod($nsid, DefaultQueryHandler::class, 'query', Auth::None, $lexicon, $collection);
    }

    /**
     * A query that hydrates one collection's records into a view, mapped in
     * atp-appview.xrpc.views. Auth is optional, so a signed-in viewer gets
     * viewer state.
     *
     * @param  class-string<View>  $view
     */
    protected function viewMethodFor(string $nsid, string $view): XrpcMethod
    {
        if (! is_subclass_of($view, View::class)) {
            throw new InvalidArgumentException("atp-appview.xrpc.views maps {$nsid} to {$view}, which does not extend ".View::class.'.');
        }

        $lexicon = Schema::find($nsid)
            ?? throw new InvalidArgumentException("No lexicon found for {$nsid}, which atp-appview.xrpc.views maps to {$view}.");

        if (! $lexicon->isQuery()) {
            throw new InvalidArgumentException("{$nsid} is a {$lexicon->getLexiconType()}. Views are served by queries.");
        }

        return new XrpcMethod($nsid, ViewQueryHandler::class, 'query', Auth::Optional, $lexicon, app($view)->collection(), view: $view);
    }

    protected function methodFor(string $handler, Xrpc $xrpc): XrpcMethod
    {
        $lexicon = Schema::find($xrpc->nsid);

        $type = match (true) {
            $lexicon?->isQuery() => 'query',
            $lexicon?->isProcedure() => 'procedure',
            $lexicon !== null => throw new InvalidArgumentException("{$xrpc->nsid} is a {$lexicon->getLexiconType()}, not a query or procedure."),
            in_array($xrpc->type, ['query', 'procedure'], true) => $xrpc->type,
            default => throw new InvalidArgumentException("No lexicon found for {$xrpc->nsid}. Add one to your lexicon sources, or set type: 'query' or 'procedure' on the attribute."),
        };

        return new XrpcMethod($xrpc->nsid, $handler, $type, $xrpc->auth, $lexicon);
    }

    /**
     * Classes with the #[Xrpc] attribute under the discovery path.
     *
     * @return array<int, class-string>
     */
    protected function discover(): array
    {
        $path = config('atp-appview.xrpc.discover.path');
        $namespace = trim((string) config('atp-appview.xrpc.discover.namespace'), '\\');

        if (! is_string($path) || ! is_dir($path)) {
            return [];
        }

        $handlers = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(rtrim($path, '/')) + 1, -4);
            $class = $namespace.'\\'.str_replace('/', '\\', $relative);

            if (class_exists($class) && (new ReflectionClass($class))->getAttributes(Xrpc::class) !== []) {
                $handlers[] = $class;
            }
        }

        return $handlers;
    }
}
