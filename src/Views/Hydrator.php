<?php

namespace SocialDept\AtpAppview\Views;

use Illuminate\Support\Collection;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Views\Contracts\Part;
use SocialDept\AtpAppview\Views\Contracts\ResolvesTogether;

/**
 * Turns a page of records into views: each part resolves the whole page,
 * then the results are put together per record, in the page's order.
 */
class Hydrator
{
    /**
     * @param  Collection<int, Record>  $records
     * @return array<int, array<string, mixed>>
     */
    public function hydrate(View $view, Collection $records, ?string $viewer = null): array
    {
        if ($records->isEmpty()) {
            return [];
        }

        $parts = $view->parts();
        $together = $this->resolveTogether($parts, $records, $viewer);
        $resolved = $this->resolve($parts, $records, $viewer, $together);

        return $records->map(fn (Record $record) => [
            ...$view->base($record),
            ...$this->assemble($parts, $resolved, $record->uri),
        ])->values()->all();
    }

    /**
     * One record's view.
     *
     * @return array<string, mixed>
     */
    public function one(View $view, Record $record, ?string $viewer = null): array
    {
        return $this->hydrate($view, new Collection([$record]), $viewer)[0];
    }

    /**
     * @param  array<string, Part|array<string, Part>>  $parts
     * @param  Collection<int, Record>  $records
     * @param  array<int, array<string, mixed>>  $together  Results already read for parts that share a query
     * @return array<string, mixed>
     */
    protected function resolve(array $parts, Collection $records, ?string $viewer, array $together): array
    {
        $resolved = [];

        foreach ($parts as $key => $part) {
            $resolved[$key] = match (true) {
                is_array($part) => $this->resolve($part, $records, $viewer, $together),
                isset($together[spl_object_id($part)]) => $together[spl_object_id($part)],
                default => $part->resolve($records, $viewer),
            };
        }

        return $resolved;
    }

    /**
     * Parts that can share a query, such as every count, read together: one
     * query per kind of part instead of one per part.
     *
     * @param  array<string, Part|array<string, Part>>  $parts
     * @param  Collection<int, Record>  $records
     * @return array<int, array<string, mixed>> By spl_object_id() of the part
     */
    protected function resolveTogether(array $parts, Collection $records, ?string $viewer): array
    {
        $groups = [];
        $walk = function (array $parts) use (&$walk, &$groups) {
            foreach ($parts as $part) {
                if (is_array($part)) {
                    $walk($part);
                } elseif ($part instanceof ResolvesTogether) {
                    $groups[$part::class][] = $part;
                }
            }
        };
        $walk($parts);

        $results = [];

        foreach ($groups as $class => $group) {
            $results += $class::resolveTogether($group, $records, $viewer);
        }

        return $results;
    }

    /**
     * @param  array<string, Part|array<string, Part>>  $parts
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    protected function assemble(array $parts, array $resolved, string $uri): array
    {
        $view = [];

        foreach ($parts as $key => $part) {
            $view[$key] = is_array($part)
                ? $this->assemble($part, $resolved[$key], $uri)
                : ($resolved[$key][$uri] ?? null);
        }

        return $view;
    }
}
