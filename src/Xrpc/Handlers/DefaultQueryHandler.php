<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use Illuminate\Database\Eloquent\Builder;
use SocialDept\AtpAppview\Models\Identity;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * Serves a query from the index for the collection atp-appview.xrpc.collections
 * maps it to, in the shapes com.atproto.repo.getRecord and listRecords use.
 *
 * With `uri`, one record: {uri, cid, value}. Otherwise a page of records,
 * newest first: {records: [{uri, cid, value}], cursor}.
 *
 * List parameters: limit, cursor, reverse, and did or actor to narrow to one
 * repo, where actor may be a handle.
 */
class DefaultQueryHandler
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(XrpcRequest $request): array
    {
        $collection = (string) $request->method->collection;
        $uri = $request->param('uri');

        return is_string($uri) && $uri !== ''
            ? $this->get($collection, $uri)
            : $this->list($request, $collection);
    }

    /**
     * @return array<string, mixed>
     */
    protected function get(string $collection, string $uri): array
    {
        // Only records in this method's collection, whatever else is indexed.
        $record = Record::collection($collection)->find($uri)
            ?? throw XrpcException::notFound('RecordNotFound', "Could not locate record: {$uri}");

        return $this->present($record);
    }

    /**
     * @return array<string, mixed>
     */
    protected function list(XrpcRequest $request, string $collection): array
    {
        $limit = $this->limit($request);
        $reverse = $request->param('reverse') === true;
        $direction = $reverse ? 'asc' : 'desc';

        $query = Record::collection($collection)
            ->orderBy('sort_at', $direction)
            ->orderBy('uri', $direction);

        if (($repo = $this->repo($request)) !== null) {
            $query->by($repo);
        }

        if (is_string($cursor = $request->param('cursor')) && $cursor !== '') {
            $this->after($query, $cursor, $reverse);
        }

        // One extra row tells whether another page exists, so the last page
        // carries no cursor leading to an empty one.
        $rows = $query->limit($limit + 1)->get();
        $page = $rows->take($limit);

        return array_filter([
            'records' => $page->map(fn (Record $record) => $this->present($record))->values()->all(),
            'cursor' => $rows->count() > $limit ? $this->cursorFor($page->last()) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * The page size: the lexicon's own default and maximum for `limit` when it
     * declares them, otherwise 50 and 100.
     */
    protected function limit(XrpcRequest $request): int
    {
        $declared = $request->method->lexicon?->getMainDefinition()['parameters']['properties']['limit'] ?? [];
        $default = (int) ($declared['default'] ?? self::DEFAULT_LIMIT);
        $maximum = (int) ($declared['maximum'] ?? self::MAX_LIMIT);

        return max(1, min((int) ($request->param('limit') ?? $default), $maximum));
    }

    /**
     * The repo to narrow to, from `did` or `actor`. A handle is resolved
     * through the identities index.
     */
    protected function repo(XrpcRequest $request): ?string
    {
        $actor = $request->param('did') ?? $request->param('actor');

        if (! is_string($actor) || $actor === '') {
            return null;
        }

        if (str_starts_with($actor, 'did:')) {
            return $actor;
        }

        return Identity::where('handle', strtolower($actor))->value('did')
            ?? throw XrpcException::invalidRequest("Could not find repo: {$actor}");
    }

    /**
     * Keyset pagination after the cursor's position, stable when records
     * arrive mid-scroll, unlike an offset.
     *
     * @param  Builder<Record>  $query
     */
    protected function after(Builder $query, string $cursor, bool $reverse): void
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = $decoded === false ? [] : explode('|', $decoded, 2);

        if (count($parts) !== 2 || $parts[0] === '' || ! str_starts_with($parts[1], 'at://')) {
            throw XrpcException::invalidRequest('Malformed cursor.');
        }

        [$sortAt, $uri] = $parts;
        $operator = $reverse ? '>' : '<';

        $query->where(function (Builder $query) use ($sortAt, $uri, $operator) {
            $query->where('sort_at', $operator, $sortAt)
                ->orWhere(fn (Builder $query) => $query->where('sort_at', $sortAt)->where('uri', $operator, $uri));
        });
    }

    protected function cursorFor(Record $record): string
    {
        // The stored value, unformatted, so the next page compares exactly.
        $position = $record->getRawOriginal('sort_at').'|'.$record->uri;

        return rtrim(strtr(base64_encode($position), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Record $record): array
    {
        return ['uri' => $record->uri, 'cid' => $record->cid, 'value' => $record->record];
    }
}
