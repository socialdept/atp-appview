<?php

namespace SocialDept\AtpAppview\Views;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A page of records as views, hydrated together in a fixed number of
 * queries, for an API response or an infinite scroll:
 *
 *     Inertia::scroll(fn () => ViewPage::of(app(PostView::class), $query->cursorPaginate(30), $viewer));
 *
 * The paginator keeps the records themselves, since a cursor paginator reads
 * the next cursor from them. Hydrating them into arrays first breaks it.
 */
class ViewPage extends ResourceCollection
{
    protected ?Closure $present = null;

    public function __construct(
        mixed $resource,
        protected View $view,
        protected ?string $viewer = null,
    ) {
        parent::__construct($resource);
    }

    public static function of(View $view, mixed $resource, ?string $viewer = null): static
    {
        return new static($resource, $view, $viewer);
    }

    /**
     * Shape each hydrated view before it is sent, such as picking the fields
     * a page shows.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $present
     */
    public function present(Closure $present): static
    {
        $this->present = $present;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        $views = app(Hydrator::class)->hydrate($this->view, $this->collection, $this->viewer);

        return $this->present === null ? $views : array_map($this->present, $views);
    }
}
