<?php

namespace SocialDept\AtpAppview\Xrpc;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SocialDept\AtpAppview\Xrpc\Attributes\Xrpc;
use SocialDept\AtpSchema\Facades\Schema;

/**
 * Every XRPC method the application serves, keyed by NSID.
 *
 * Handlers come from atp-appview.xrpc.handlers and from classes carrying the
 * #[Xrpc] attribute in the discovery directory.
 */
class XrpcRegistry
{
    /**
     * @var array<string, XrpcMethod>|null
     */
    protected ?array $methods = null;

    /**
     * @return array<string, XrpcMethod>
     */
    public function all(): array
    {
        return $this->methods ??= $this->build();
    }

    public function find(string $nsid): ?XrpcMethod
    {
        return $this->all()[$nsid] ?? null;
    }

    /**
     * @return array<string, XrpcMethod>
     */
    protected function build(): array
    {
        $methods = [];

        foreach (array_unique([...config('atp-appview.xrpc.handlers', []), ...$this->discover()]) as $handler) {
            $method = $this->methodFor($handler);

            if (isset($methods[$method->nsid])) {
                throw new InvalidArgumentException("Two handlers serve {$method->nsid}: {$methods[$method->nsid]->handler} and {$handler}.");
            }

            $methods[$method->nsid] = $method;
        }

        ksort($methods);

        return $methods;
    }

    protected function methodFor(string $handler): XrpcMethod
    {
        $attribute = (new ReflectionClass($handler))->getAttributes(Xrpc::class)[0] ?? null;

        if ($attribute === null) {
            throw new InvalidArgumentException("{$handler} has no #[Xrpc] attribute.");
        }

        /** @var Xrpc $xrpc */
        $xrpc = $attribute->newInstance();
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
