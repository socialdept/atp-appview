<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use SocialDept\AtpAppview\Index\RecordQuery;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Views\Hydrator;
use SocialDept\AtpAppview\Views\View;
use SocialDept\AtpAppview\Xrpc\XrpcException;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * Serves a query mapped in atp-appview.xrpc.views: the default query's paging,
 * repo filter and lookup by uri, with each record hydrated into its view.
 *
 * With `uri`, one view. Otherwise a page of them under the view's key, such
 * as {documents: [...], cursor}. A signed-in viewer, by session or service
 * auth, gets viewer state.
 *
 * Mapped with options, a query also filters and sorts by indexed fields,
 * each a lexicon parameter:
 *
 *     'com.example.getPosts' => [
 *         'view' => PostView::class,
 *         'filters' => ['tag' => 'tags'],          // ?tag=php
 *         'sorts' => ['created' => 'createdAt'],   // ?sort=created
 *     ],
 */
class ViewQueryHandler extends DefaultQueryHandler
{
    protected View $view;

    protected ?string $viewer = null;

    /** @var array{filters?: array<string, string>, sorts?: array<string, string>} */
    protected array $options = [];

    public function __invoke(XrpcRequest $request): array
    {
        $this->view = app((string) $request->method->view);
        $this->viewer = $request->viewer()?->did;
        $mapped = ((array) config('atp-appview.xrpc.views', []))[$request->method->nsid] ?? [];
        $this->options = is_array($mapped) ? $mapped : [];

        return parent::__invoke($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function list(XrpcRequest $request, string $collection): array
    {
        // The filters this request uses, as parameter => field.
        $filters = array_filter(
            (array) ($this->options['filters'] ?? []),
            fn (string $field, string $param) => $request->param($param) !== null && $request->param($param) !== '',
            ARRAY_FILTER_USE_BOTH,
        );
        $sort = $request->param('sort');

        if ($filters === [] && ! is_string($sort)) {
            return parent::list($request, $collection);
        }

        $query = RecordQuery::in($collection)
            ->visibleTo($this->viewer)
            ->limit($this->limit($request))
            ->after($request->param('cursor'));

        foreach ($filters as $param => $field) {
            $query->where($field, $request->param($param));
        }

        if (is_string($sort) && $sort !== '') {
            $field = ($this->options['sorts'] ?? [])[$sort]
                ?? throw XrpcException::invalidRequest("Unknown sort: {$sort}");
            $query->orderBy($field, $request->param('reverse') === true ? 'asc' : 'desc');
        }

        if (($repo = $this->repo($request)) !== null) {
            $query->by($repo);
        }

        try {
            $page = $query->get();
        } catch (InvalidArgumentException $e) {
            throw XrpcException::invalidRequest($e->getMessage());
        }

        return array_filter([
            $this->listKey() => $this->presentPage($page['records']),
            'cursor' => $page['cursor'],
        ], fn ($value) => $value !== null);
    }

    protected function listKey(): string
    {
        return $this->view->key();
    }

    protected function presentPage(Collection $records): array
    {
        return app(Hydrator::class)->hydrate($this->view, $records, $this->viewer);
    }

    protected function presentOne(Record $record): array
    {
        return app(Hydrator::class)->one($this->view, $record, $this->viewer);
    }
}
