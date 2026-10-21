<?php

namespace SocialDept\AtpAppview\Feeds;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SocialDept\AtpAppview\Features\Feeds as FeedsFeature;

/**
 * Each viewer's saved feeds, in their order, and which are pinned, as
 * Bluesky's savedFeedsPrefV2 has them. Saved means listed among the
 * viewer's feeds, pinned means saved and shown where the app shows its
 * tabs. An entry is a type and a value: a feed's AT-URI under the type
 * "feed", or anything the app defines, such as a built-in timeline.
 *
 * A viewer who never changed theirs has atp-appview.feeds.saved, kept only
 * once they change something.
 */
class SavedFeeds
{
    public const FEED = 'feed';

    /**
     * The row kept for a viewer who removed every saved feed, so they are
     * not handed the defaults again. Never read as an entry.
     */
    protected const EMPTIED = '#emptied';

    /**
     * The viewer's saved feeds: pinned first, each part in its order.
     *
     * @return array<int, array{type: string, value: string, pinned: bool}>
     */
    public function for(string $viewer): array
    {
        if (! FeedsFeature::on()) {
            return [];
        }

        $rows = $this->connection()->table('atp_saved_feeds')
            ->where('viewer_did', $viewer)
            ->orderBy('position')
            ->get(['type', 'value', 'pinned']);

        $entries = $rows->isEmpty()
            ? self::defaults()
            : $rows->reject(fn ($row) => $row->type === self::EMPTIED)
                ->map(fn ($row) => ['type' => (string) $row->type, 'value' => (string) $row->value, 'pinned' => (bool) $row->pinned])
                ->values()->all();

        return self::ordered($entries);
    }

    /**
     * The pinned ones, in order.
     *
     * @return array<int, array{type: string, value: string, pinned: bool}>
     */
    public function pinned(string $viewer): array
    {
        return array_values(array_filter($this->for($viewer), fn (array $entry) => $entry['pinned']));
    }

    /**
     * Replace the viewer's saved feeds with these, in this order. A repeated
     * entry is kept once, where it first appears.
     *
     * @param  array<int, array{type: string, value: string, pinned?: bool}>  $entries
     *
     * @throws InvalidArgumentException for an entry without a type or value
     */
    public function replace(string $viewer, array $entries): void
    {
        FeedsFeature::ensureOn();

        $rows = [];

        foreach (self::ordered(array_map(fn (array $entry) => [
            'type' => (string) ($entry['type'] ?? ''),
            'value' => (string) ($entry['value'] ?? ''),
            'pinned' => (bool) ($entry['pinned'] ?? false),
        ], $entries)) as $entry) {
            if ($entry['type'] === '' || $entry['type'] === self::EMPTIED || $entry['value'] === '' || strlen($entry['type']) > 32 || strlen($entry['value']) > 512) {
                throw new InvalidArgumentException('A saved feed needs a type of up to 32 characters and a value of up to 512.');
            }

            $rows[$entry['type']."\n".$entry['value']] ??= ['viewer_did' => $viewer, ...$entry, 'position' => count($rows)];
        }

        if ($rows === []) {
            $rows[] = ['viewer_did' => $viewer, 'type' => self::EMPTIED, 'value' => self::EMPTIED, 'pinned' => false, 'position' => 0];
        }

        $this->connection()->transaction(function () use ($viewer, $rows) {
            $this->connection()->table('atp_saved_feeds')->where('viewer_did', $viewer)->delete();

            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                $this->connection()->table('atp_saved_feeds')->insert($chunk);
            }
        });
    }

    /**
     * Save a feed, pinned or not. One already saved keeps its place, and is
     * pinned when asked.
     */
    public function save(string $viewer, string $value, bool $pinned = false, string $type = self::FEED): void
    {
        $entries = $this->for($viewer);
        $found = false;

        foreach ($entries as &$entry) {
            if ($entry['type'] === $type && $entry['value'] === $value) {
                $entry['pinned'] = $entry['pinned'] || $pinned;
                $found = true;
            }
        }
        unset($entry);

        if (! $found) {
            $entries[] = ['type' => $type, 'value' => $value, 'pinned' => $pinned];
        }

        $this->replace($viewer, $entries);
    }

    public function pin(string $viewer, string $value, string $type = self::FEED): void
    {
        $this->save($viewer, $value, true, $type);
    }

    /**
     * Unpin a feed, keeping it saved.
     */
    public function unpin(string $viewer, string $value, string $type = self::FEED): void
    {
        $this->replace($viewer, array_map(
            fn (array $entry) => $entry['type'] === $type && $entry['value'] === $value ? [...$entry, 'pinned' => false] : $entry,
            $this->for($viewer),
        ));
    }

    /**
     * Forget a saved feed.
     */
    public function remove(string $viewer, string $value, string $type = self::FEED): void
    {
        $this->replace($viewer, array_values(array_filter(
            $this->for($viewer),
            fn (array $entry) => ! ($entry['type'] === $type && $entry['value'] === $value),
        )));
    }

    /**
     * What a viewer who never changed theirs has, from
     * atp-appview.feeds.saved. A feed may be named by its rkey, for the
     * feeds this service runs.
     *
     * @return array<int, array{type: string, value: string, pinned: bool}>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach ((array) config('atp-appview.feeds.saved', []) as $entry) {
            $type = (string) ($entry['type'] ?? self::FEED);
            $value = (string) ($entry['value'] ?? '');

            if ($type === self::FEED && ! str_starts_with($value, 'at://')) {
                $value = (string) Feeds::uri($value);
            }

            if ($value !== '') {
                $defaults[] = ['type' => $type, 'value' => $value, 'pinned' => (bool) ($entry['pinned'] ?? true)];
            }
        }

        return $defaults;
    }

    /**
     * Pinned first, each part keeping its order.
     *
     * @param  array<int, array{type: string, value: string, pinned: bool}>  $entries
     * @return array<int, array{type: string, value: string, pinned: bool}>
     */
    protected static function ordered(array $entries): array
    {
        return [
            ...array_values(array_filter($entries, fn (array $entry) => $entry['pinned'])),
            ...array_values(array_filter($entries, fn (array $entry) => ! $entry['pinned'])),
        ];
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
