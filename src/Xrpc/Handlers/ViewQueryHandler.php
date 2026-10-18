<?php

namespace SocialDept\AtpAppview\Xrpc\Handlers;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Views\Hydrator;
use SocialDept\AtpAppview\Views\View;
use SocialDept\AtpAppview\Xrpc\XrpcRequest;

/**
 * Serves a query mapped in atp-appview.xrpc.views: the default query's paging,
 * repo filter and lookup by uri, with each record hydrated into its view.
 *
 * With `uri`, one view. Otherwise a page of them under the view's key, such
 * as {documents: [...], cursor}. A signed-in viewer, by session or service
 * auth, gets viewer state.
 */
class ViewQueryHandler extends DefaultQueryHandler
{
    protected View $view;

    protected ?string $viewer = null;

    public function __invoke(XrpcRequest $request): array
    {
        $this->view = app((string) $request->method->view);
        $this->viewer = $request->viewer()?->did;

        return parent::__invoke($request);
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
