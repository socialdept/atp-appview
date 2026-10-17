<?php

namespace SocialDept\AtpAppview\Xrpc;

use Illuminate\Http\Request;
use SocialDept\AtpAppview\Auth\Viewer;

/**
 * What a handler receives: validated parameters, validated input and the
 * viewer, whatever the auth mode.
 */
final class XrpcRequest
{
    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $input
     */
    public function __construct(
        public readonly XrpcMethod $method,
        public readonly Request $http,
        protected readonly array $params = [],
        protected readonly ?array $input = null,
    ) {
    }

    /**
     * Query parameters, typed as the lexicon declares them.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * The decoded JSON body of a procedure.
     *
     * @return array<string, mixed>|null
     */
    public function input(): ?array
    {
        return $this->input;
    }

    public function viewer(): ?Viewer
    {
        return $this->http->attributes->get(Viewer::class);
    }

    public function nsid(): string
    {
        return $this->method->nsid;
    }
}
