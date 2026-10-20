<?php

namespace SocialDept\AtpAppview\Xrpc;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use JsonSerializable;
use SocialDept\AtpAppview\Auth\Viewer;
use SocialDept\AtpAppview\Auth\ViewerSource;
use SocialDept\AtpClient\Contracts\HasAtpSession;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpSchema\Exceptions\SchemaException;
use SocialDept\AtpSchema\Facades\Schema;
use SocialDept\AtpSupport\Facades\Resolver;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Calls XRPC methods as someone, the same way whoever answers them:
 *
 *     Xrpc::as($request->viewer())->query('com.example.listThings', ['limit' => 30]);
 *     Xrpc::as($did)->service('did:web:api.bsky.app#bsky_appview')->query('app.bsky.feed.getTimeline');
 *
 * This AppView's own methods are answered in-process, through the same
 * handler, auth and lexicon validation as over HTTP, with no request made.
 * Another service is called through the account's PDS with atproto-proxy,
 * which needs the session the server holds for them (backend mode). In
 * frontend mode only the browser holds tokens, so those calls belong to the
 * browser's xrpc helper. Without anyone to call as, another service is called
 * directly, unauthenticated.
 *
 * Results are the decoded JSON. Errors are thrown as XrpcException, whoever
 * answered.
 */
class XrpcClient
{
    protected ?Viewer $viewer = null;

    protected ?string $service = null;

    public function __construct(
        protected XrpcRegistry $registry,
    ) {
    }

    /**
     * Call as this viewer, DID or user. Null calls anonymously.
     */
    public function as(Viewer|Authenticatable|string|null $who): static
    {
        $clone = clone $this;
        $clone->viewer = match (true) {
            $who === null, $who instanceof Viewer => $who,
            is_string($who) => new Viewer($who, ViewerSource::Session),
            $who instanceof HasAtpSession && $who->getAtpDid() !== null => new Viewer($who->getAtpDid(), ViewerSource::Session, user: $who),
            default => null,
        };

        return $clone;
    }

    /**
     * The service to call, as a DID with its service id, such as
     * did:web:api.bsky.app#bsky_appview. Null, or this AppView's own
     * audience, answers in-process.
     */
    public function service(?string $service): static
    {
        $clone = clone $this;
        $clone->service = $service;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function query(string $nsid, array $params = []): array
    {
        return $this->call('query', $nsid, $params, null);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function procedure(string $nsid, array $input = []): array
    {
        return $this->call('procedure', $nsid, [], $input);
    }

    /**
     * Whether calls are answered in-process: no service named, or this
     * AppView's own.
     */
    public function isLocal(): bool
    {
        if ($this->service === null) {
            return true;
        }

        $audience = (string) config('atp-client.service_auth.audience');

        return $audience !== '' && ($this->service === $audience || strtok($audience, '#') === $this->service);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>
     */
    protected function call(string $type, string $nsid, array $params, ?array $input): array
    {
        return $this->isLocal()
            ? $this->local($type, $nsid, $params, $input)
            : $this->remote($type, $nsid, $params, $input);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>
     */
    protected function local(string $type, string $nsid, array $params, ?array $input): array
    {
        $method = $this->registry->find($nsid) ?? throw XrpcException::methodNotImplemented($nsid);

        if ($method->type !== $type) {
            throw XrpcException::invalidRequest("{$nsid} is a {$method->type}, not a {$type}.");
        }

        if ($method->auth === Auth::Required && $this->viewer === null) {
            throw new XrpcException('AuthenticationRequired', 'Authentication required.', 401);
        }

        $params = array_filter($params, fn ($value) => $value !== null);

        if ($method->lexicon !== null) {
            try {
                $method->isQuery()
                    ? Schema::getValidator()->validateQuery($method->lexicon, $params)
                    : Schema::getValidator()->validateProcedure($method->lexicon, $input ?? []);
            } catch (SchemaException $e) {
                throw XrpcException::invalidRequest($e->getMessage());
            }
        }

        $http = Request::create('/'.trim((string) config('atp-appview.xrpc.prefix', 'xrpc'), '/')."/{$nsid}", $method->httpMethod(), $method->isQuery() ? $params : []);

        if ($this->viewer !== null) {
            $http->attributes->set(Viewer::class, $this->viewer);
            $http->setUserResolver(fn () => $this->viewer->user());
        }

        $result = app()->call([app($method->handler), '__invoke'], [
            XrpcRequest::class => new XrpcRequest($method, $http, $params, $method->isQuery() ? null : ($input ?? [])),
            Viewer::class => $this->viewer,
            Request::class => $http,
        ]);

        return self::decode($result);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>
     */
    protected function remote(string $type, string $nsid, array $params, ?array $input): array
    {
        $httpMethod = $type === 'query' ? 'GET' : 'POST';
        $query = self::queryString($params);

        if ($this->viewer === null) {
            $endpoint = $this->endpointOf((string) $this->service);
            $response = $httpMethod === 'GET'
                ? Http::acceptJson()->get(rtrim($endpoint, '/')."/xrpc/{$nsid}".($query !== '' ? "?{$query}" : ''))
                : Http::acceptJson()->post(rtrim($endpoint, '/')."/xrpc/{$nsid}", $input ?? []);
        } else {
            if (! $this->viewer->canActOnRepo()) {
                throw new XrpcException('SessionRequired', "The server holds no session for {$this->viewer->did}, so calls to {$this->service} as them are made from the browser.", 403);
            }

            $response = Atp::as($this->viewer->did)->client->forward(
                $httpMethod,
                $nsid,
                $query,
                $httpMethod === 'POST' ? json_encode($input ?? [], JSON_UNESCAPED_SLASHES) : null,
                ['atproto-proxy' => (string) $this->service, 'accept' => 'application/json', 'content-type' => 'application/json'],
            );
        }

        $body = json_decode($response->body(), true);

        if ($response->failed()) {
            throw new XrpcException(
                is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : 'UpstreamFailure',
                is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : "{$this->service} answered {$response->status()}.",
                $response->status(),
            );
        }

        return is_array($body) ? $body : [];
    }

    /**
     * Where a service is reached, from its DID document.
     */
    protected function endpointOf(string $service): string
    {
        [$did, $id] = array_pad(explode('#', $service, 2), 2, '');

        try {
            $document = Resolver::resolveDid($did);
        } catch (Throwable $e) {
            throw new XrpcException('ServiceNotFound', "Could not resolve {$did}: {$e->getMessage()}", 502);
        }

        foreach ($document->service as $entry) {
            if (in_array($entry['id'] ?? null, ["#{$id}", "{$did}#{$id}"], true) && is_string($entry['serviceEndpoint'] ?? null)) {
                return $entry['serviceEndpoint'];
            }
        }

        throw new XrpcException('ServiceNotFound', "{$did} publishes no #{$id} service.", 502);
    }

    /**
     * Parameters as XRPC sends them: an array repeats its name per item.
     *
     * @param  array<string, mixed>  $params
     */
    protected static function queryString(array $params): string
    {
        $parts = [];

        foreach ($params as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item !== null) {
                    $parts[] = rawurlencode((string) $name).'='.rawurlencode(is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
                }
            }
        }

        return implode('&', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decode(mixed $result): array
    {
        return match (true) {
            $result === null => [],
            is_array($result) => $result,
            $result instanceof Arrayable => $result->toArray(),
            $result instanceof JsonSerializable => (array) json_decode(json_encode($result), true),
            $result instanceof Response => (array) json_decode((string) $result->getContent(), true),
            default => throw new \UnexpectedValueException('An XRPC handler must return an array, Arrayable, JsonSerializable, Response or null.'),
        };
    }
}
