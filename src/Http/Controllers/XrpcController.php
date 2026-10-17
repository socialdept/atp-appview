<?php

namespace SocialDept\AtpAppview\Http\Controllers;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonSerializable;
use SocialDept\AtpAppview\Auth\Viewer;
use SocialDept\AtpAppview\Xrpc\QueryParameters;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcMethod;
use SocialDept\AtpAppview\Xrpc\XrpcRegistry;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;
use SocialDept\AtpSchema\Exceptions\SchemaException;
use SocialDept\AtpSchema\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dispatches every registered XRPC method to its handler.
 */
class XrpcController
{
    public function __construct(
        protected XrpcRegistry $registry,
    ) {
    }

    public function handle(Request $request, string $nsid): Response
    {
        try {
            $method = $this->registry->find($nsid) ?? throw XrpcException::methodNotImplemented($nsid);

            return $this->respond(app()->call([app($method->handler), '__invoke'], [
                XrpcRequest::class => $this->xrpcRequest($method, $request),
                Viewer::class => $request->attributes->get(Viewer::class),
                Request::class => $request,
            ]), $request);
        } catch (XrpcException $e) {
            return $this->error($e);
        }
    }

    /**
     * Answers anything under the XRPC prefix that no route matched: an unknown
     * method, or a known one called with the wrong HTTP method.
     */
    public function fallback(Request $request, string $nsid): Response
    {
        $method = $this->registry->find($nsid);

        return $this->error($method === null
            ? XrpcException::methodNotImplemented($nsid)
            : XrpcException::invalidRequest("{$nsid} is a {$method->type}, call it with {$method->httpMethod()}."));
    }

    protected function xrpcRequest(XrpcMethod $method, Request $http): XrpcRequest
    {
        $definition = $method->lexicon?->getMainDefinition() ?? [];
        // The raw query string, not getQueryString(): Symfony's normalization
        // keeps only the last value of a repeated parameter, and XRPC repeats
        // a parameter for each item of an array.
        $params = QueryParameters::parse($http->server->get('QUERY_STRING'), $definition['parameters'] ?? null);
        $input = null;

        if (! $method->isQuery() && $http->getContent() !== '') {
            $input = json_decode($http->getContent(), true);

            if (! is_array($input)) {
                throw XrpcException::invalidRequest('The request body must be a JSON object.');
            }
        }

        if ($method->lexicon !== null) {
            try {
                $method->isQuery()
                    ? Schema::getValidator()->validateQuery($method->lexicon, $params)
                    : $this->validateInput($method, $definition['input'] ?? null, $input, $http);
            } catch (SchemaException $e) {
                throw XrpcException::invalidRequest($e->getMessage());
            }
        }

        return new XrpcRequest($method, $http, $params, $input);
    }

    /**
     * Validate a procedure body against its lexicon's input schema.
     *
     * @param  array<string, mixed>|null  $declared  The lexicon's input object
     * @param  array<string, mixed>|null  $input
     */
    protected function validateInput(XrpcMethod $method, ?array $declared, ?array $input, Request $http): void
    {
        if (($declared['encoding'] ?? null) === 'application/json' && $http->getContent() !== '' && ! $http->isJson()) {
            throw XrpcException::invalidRequest("{$method->nsid} expects an application/json body.");
        }

        Schema::getValidator()->validateProcedure($method->lexicon, $input ?? []);
    }

    protected function respond(mixed $result, Request $request): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            $result instanceof Responsable => $result->toResponse($request),
            $result === null => new Response('', 200),
            $result instanceof Arrayable => new JsonResponse($result->toArray()),
            $result instanceof JsonSerializable, is_array($result) => new JsonResponse($result),
            default => throw new \UnexpectedValueException('An XRPC handler must return an array, Arrayable, JsonSerializable, Responsable, Response or null.'),
        };
    }

    protected function error(XrpcException $e): JsonResponse
    {
        return new JsonResponse(['error' => $e->error, 'message' => $e->getMessage()], $e->status);
    }
}
