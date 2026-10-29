<?php

namespace SocialDept\AtpAppview\Views;

use Closure;
use SocialDept\AtpAppview\Graph\Graph;
use SocialDept\AtpAppview\Index\ColdRecords;

/**
 * A thread as a tree, from records that point at their parent: the anchor,
 * the parents above it, and the replies below, each as its view. What makes
 * a reply, where its parent lives and how replies sort are the app's, so it
 * hands over the parent of each record it found, usually every record
 * sharing a root through a queryable field.
 *
 * Views come in one batch for the whole tree. A parent the viewer cannot see
 * stands as a placeholder, blocked when its author is blocked either way and
 * not found otherwise, as Bluesky's threads have them. A reply the viewer
 * cannot see is left out with everything under it.
 */
class ReplyTree
{
    public const RECORD = 'record';

    public const NOT_FOUND = 'notFound';

    public const BLOCKED = 'blocked';

    /**
     * Each record's parent, from a path into it, such as reply.parent.uri.
     *
     * @param  iterable<object>  $records  Records with uri and record, such as atp_records rows
     * @return array<string, string|null> URI => parent URI, null for one without
     */
    public static function parents(iterable $records, string $path): array
    {
        $parents = [];
        // Rows selected with hollowed_at get cold bodies back, all at once.
        $records = $records instanceof \Traversable ? iterator_to_array($records, false) : $records;
        app(ColdRecords::class)->thaw($records);

        foreach ($records as $record) {
            $data = is_array($record->record) ? $record->record : (array) json_decode((string) $record->record, true);
            $parent = data_get($data, $path);
            $parents[(string) $record->uri] = is_string($parent) && $parent !== '' ? $parent : null;
        }

        return $parents;
    }

    /**
     * The anchor's node, with its parents up to $height above it and its
     * replies down to $depth below. Replies keep the order of $parents
     * unless $order compares two nodes.
     *
     * @param  array<string, string|null>  $parents  URI => parent URI, for every record the thread holds
     * @param  Closure(array<string, mixed>, array<string, mixed>): int|null  $order
     * @return array<string, mixed>
     */
    public function build(string $anchor, array $parents, ?string $viewer, int $depth = 6, int $height = 80, ?Closure $order = null): array
    {
        $children = [];

        foreach ($parents as $uri => $parent) {
            if ($parent !== null && $parent !== $uri) {
                $children[$parent][] = (string) $uri;
            }
        }

        $chain = $this->chain($anchor, $parents, $height);
        $below = $this->below($anchor, $children, $depth);
        $views = app(UriViews::class)->views(array_values(array_unique([$anchor, ...$chain, ...$below])), $viewer);
        $blocked = $this->blockedAuthors($viewer);

        $node = $this->node($anchor, $views, $blocked);

        if ($node['type'] === self::RECORD) {
            $node['replies'] = $this->replies($anchor, $children, $views, $order);
        }

        // Parents from the nearest up, each holding the next as its own.
        $child = &$node;

        foreach ($chain as $uri) {
            $child['parent'] = $this->node($uri, $views, $blocked);
            $child = &$child['parent'];
        }

        return $node;
    }

    /**
     * The anchor's parents, nearest first, stopping at a loop or $height.
     *
     * @param  array<string, string|null>  $parents
     * @return array<int, string>
     */
    protected function chain(string $anchor, array $parents, int $height): array
    {
        $chain = [];
        $seen = [$anchor => true];
        $uri = $parents[$anchor] ?? null;

        while ($uri !== null && count($chain) < $height && ! isset($seen[$uri])) {
            $chain[] = $uri;
            $seen[$uri] = true;
            $uri = $parents[$uri] ?? null;
        }

        return $chain;
    }

    /**
     * Every URI under the anchor down to $depth.
     *
     * @param  array<string, array<int, string>>  $children
     * @return array<int, string>
     */
    protected function below(string $anchor, array $children, int $depth): array
    {
        $found = [];
        $level = [$anchor];

        for ($i = 0; $i < $depth && $level !== []; $i++) {
            $next = [];

            foreach ($level as $uri) {
                foreach ($children[$uri] ?? [] as $child) {
                    if (! isset($found[$child]) && $child !== $anchor) {
                        $found[$child] = true;
                        $next[] = $child;
                    }
                }
            }

            $level = $next;
        }

        return array_keys($found);
    }

    /**
     * The replies under a URI that have views. Views were fetched only down
     * to the depth asked for, so that is where the tree ends.
     *
     * @param  array<string, array<int, string>>  $children
     * @param  array<string, array<string, mixed>>  $views
     * @param  array<string, true>  $seen
     * @return array<int, array<string, mixed>>
     */
    protected function replies(string $uri, array $children, array $views, ?Closure $order, array $seen = []): array
    {
        $seen[$uri] = true;
        $replies = [];

        foreach ($children[$uri] ?? [] as $child) {
            if (isset($seen[$child]) || ! isset($views[$child])) {
                continue;
            }

            $replies[] = [
                'type' => self::RECORD,
                'uri' => $child,
                'view' => $views[$child],
                'replies' => $this->replies($child, $children, $views, $order, $seen),
            ];
        }

        if ($order !== null) {
            usort($replies, $order);
        }

        return $replies;
    }

    /**
     * @param  array<string, array<string, mixed>>  $views
     * @param  array<string, true>  $blocked
     * @return array<string, mixed>
     */
    protected function node(string $uri, array $views, array $blocked): array
    {
        if (isset($views[$uri])) {
            return ['type' => self::RECORD, 'uri' => $uri, 'view' => $views[$uri]];
        }

        $author = explode('/', $uri)[2] ?? '';

        return isset($blocked[$author])
            ? ['type' => self::BLOCKED, 'uri' => $uri, 'author' => $author]
            : ['type' => self::NOT_FOUND, 'uri' => $uri];
    }

    /**
     * Authors the viewer blocks or is blocked by.
     *
     * @return array<string, true>
     */
    protected function blockedAuthors(?string $viewer): array
    {
        if ($viewer === null || ! Graph::on()) {
            return [];
        }

        $graph = app(Graph::class);

        return array_fill_keys([...$graph->blocking($viewer), ...$graph->blockedBy($viewer)], true);
    }
}
