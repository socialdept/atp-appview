<?php

namespace SocialDept\AtpAppview\Views;

use Illuminate\Support\Str;
use SocialDept\AtpAppview\Models\Record;
use SocialDept\AtpAppview\Views\Contracts\Part;

/**
 * How records of one collection are presented: the record, plus the parts
 * that hydrate it.
 *
 *     class StatusView extends View
 *     {
 *         public function collection(): string
 *         {
 *             return 'xyz.statusphere.status';
 *         }
 *
 *         public function parts(): array
 *         {
 *             return [
 *                 'author' => new Parts\Author(),
 *                 'likeCount' => new Parts\Count('com.example.like', 'subject'),
 *                 'viewer' => [
 *                     'like' => new Parts\ViewerRef('com.example.like', 'subject'),
 *                 ],
 *             ];
 *         }
 *     }
 */
abstract class View
{
    abstract public function collection(): string;

    /**
     * The parts, by the key they appear under. An array of parts nests them
     * under one key, such as `viewer`.
     *
     * @return array<string, Part|array<string, Part>>
     */
    abstract public function parts(): array;

    /**
     * The fields every view has, before its parts.
     *
     * @return array<string, mixed>
     */
    public function base(Record $record): array
    {
        return [
            'uri' => $record->uri,
            'cid' => $record->cid,
            'record' => $record->record,
            'indexedAt' => $record->indexed_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * The short form another view embeds. Its parts are not resolved, so an
     * embed costs one query for the whole page.
     *
     * @return array<string, mixed>
     */
    public function summary(Record $record): array
    {
        return ['uri' => $record->uri, 'cid' => $record->cid, 'record' => $record->record];
    }

    /**
     * The key a list of these views goes under: the collection's last segment,
     * plural, such as `documents` or `statuses`.
     */
    public function key(): string
    {
        return Str::plural(Str::afterLast($this->collection(), '.'));
    }
}
