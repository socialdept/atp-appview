<?php

namespace SocialDept\AtpAppview\Graph;

use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpAppview\Index\Field;
use SocialDept\AtpAppview\Index\RecordValues;
use SocialDept\AtpAppview\Index\ValueHash;

/**
 * Who follows, blocks and mutes whom.
 *
 * Follows and blocks are records, in the collections atp-appview.graph
 * names, and read from the field values the index keeps for them, so "does
 * A follow B" and "who blocks B" are index lookups. Mutes are private, kept
 * in atp_mutes.
 *
 * Blocks apply both ways: neither account sees the other's records. Mutes
 * apply only to the muter. hidden() gives both at once, for leaving records
 * out of what a viewer reads.
 */
class Graph
{
    public const FOLLOWS = 'follows';

    public const BLOCKS = 'blocks';

    /** @var array<string, array<int, string>> */
    protected array $hidden = [];

    /**
     * The collection and field a relation is read from, or null when the app
     * has none.
     *
     * @return array{collection: string, path: string}|null
     */
    public static function relation(string $kind): ?array
    {
        $relation = (array) config("atp-appview.graph.{$kind}", []);

        // Before the graph had its own config, notifications named follows.
        if ($kind === self::FOLLOWS && ! isset($relation['collection'])) {
            $relation = (array) config('atp-appview.notifications.follows', []);
        }

        return isset($relation['collection'], $relation['path'])
            ? ['collection' => (string) $relation['collection'], 'path' => (string) $relation['path']]
            : null;
    }

    /**
     * Whether $did follows $subject.
     */
    public function follows(string $did, string $subject): bool
    {
        return $this->points(self::FOLLOWS, $did, $subject);
    }

    /**
     * Whether $did blocks $subject.
     */
    public function blocks(string $did, string $subject): bool
    {
        return $this->points(self::BLOCKS, $did, $subject);
    }

    /**
     * The accounts $did follows.
     *
     * @return array<int, string>
     */
    public function following(string $did): array
    {
        return $this->subjects(self::FOLLOWS, $did);
    }

    /**
     * The accounts that follow $did, newest first.
     *
     * @return array<int, string>
     */
    public function followers(string $did, int $limit = 100): array
    {
        return $this->pointers(self::FOLLOWS, $did, $limit);
    }

    /**
     * The accounts $did blocks.
     *
     * @return array<int, string>
     */
    public function blocking(string $did): array
    {
        return $this->subjects(self::BLOCKS, $did);
    }

    /**
     * The accounts that block $did.
     *
     * @return array<int, string>
     */
    public function blockedBy(string $did): array
    {
        return $this->pointers(self::BLOCKS, $did, null);
    }

    /**
     * The accounts $did muted.
     *
     * @return array<int, string>
     */
    public function muted(string $did): array
    {
        return $this->connection()->table('atp_mutes')->where('did', $did)->pluck('subject')->all();
    }

    public function mute(string $did, string $subject): void
    {
        if ($did === $subject) {
            return;
        }

        $this->connection()->table('atp_mutes')->insertOrIgnore([
            'did' => $did,
            'subject' => $subject,
            'created_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
        ]);

        unset($this->hidden[$did]);
    }

    public function unmute(string $did, string $subject): void
    {
        $this->connection()->table('atp_mutes')->where('did', $did)->where('subject', $subject)->delete();

        unset($this->hidden[$did]);
    }

    /**
     * Everyone whose records $viewer should not see: blocked either way, or
     * muted.
     *
     * @return array<int, string>
     */
    public function hidden(string $viewer): array
    {
        return $this->hidden[$viewer] ??= array_values(array_unique([
            ...$this->blocking($viewer),
            ...$this->blockedBy($viewer),
            ...$this->muted($viewer),
        ]));
    }

    /**
     * How $viewer stands with each of these accounts, for views.
     *
     * @param  array<int, string>  $dids
     * @return array<string, array{following: bool, followedBy: bool, blocking: bool, blockedBy: bool, muted: bool}>
     */
    public function relationships(string $viewer, array $dids): array
    {
        $dids = array_values(array_unique(array_filter($dids, fn ($did) => is_string($did) && $did !== $viewer)));

        if ($dids === []) {
            return [];
        }

        $following = array_flip($this->pointingFrom(self::FOLLOWS, $viewer, $dids));
        $followedBy = array_flip($this->pointingAt(self::FOLLOWS, $viewer, $dids));
        $blocking = array_flip($this->pointingFrom(self::BLOCKS, $viewer, $dids));
        $blockedBy = array_flip($this->pointingAt(self::BLOCKS, $viewer, $dids));
        $muted = array_flip($this->connection()->table('atp_mutes')->where('did', $viewer)->whereIn('subject', $dids)->pluck('subject')->all());

        $relationships = [];

        foreach ($dids as $did) {
            $relationships[$did] = [
                'following' => isset($following[$did]),
                'followedBy' => isset($followedBy[$did]),
                'blocking' => isset($blocking[$did]),
                'blockedBy' => isset($blockedBy[$did]),
                'muted' => isset($muted[$did]),
            ];
        }

        return $relationships;
    }

    /**
     * Whether $did has a record of this kind pointing at $subject.
     */
    protected function points(string $kind, string $did, string $subject): bool
    {
        $key = $this->key($kind);

        return $key !== null && app(RecordValues::class)->visible()
            ->where('did_hash', ValueHash::of($did))
            ->where('key_id', $key)
            ->where('value_hash', ValueHash::of($subject))
            ->exists();
    }

    /**
     * What $did's records of this kind point at.
     *
     * @return array<int, string>
     */
    protected function subjects(string $kind, string $did): array
    {
        $relation = self::relation($kind);

        if ($relation === null) {
            return [];
        }

        $subjects = [];

        // Read from the records, which hold the DIDs the values only hash.
        $this->connection()->table('atp_records')
            ->where('did', $did)
            ->where('collection', $relation['collection'])
            ->whereNull('deleted_at')
            ->whereNull('hidden_at')
            ->whereNull('moderated_at')
            ->orderBy('uri')
            ->select('record')
            ->chunk(1000, function ($rows) use ($relation, &$subjects) {
                foreach ($rows as $row) {
                    $value = is_array($row->record) ? $row->record : (array) json_decode((string) $row->record, true);
                    $subject = data_get($value, $relation['path']);

                    if (is_string($subject) && str_starts_with($subject = trim($subject), 'did:')) {
                        $subjects[$subject] = true;
                    }
                }
            });

        return array_keys($subjects);
    }

    /**
     * The accounts with a record of this kind pointing at $did, newest first.
     *
     * @return array<int, string>
     */
    protected function pointers(string $kind, string $did, ?int $limit): array
    {
        $key = $this->key($kind);

        if ($key === null) {
            return [];
        }

        return app(RecordValues::class)->visibleAs('v')
            ->join('atp_records as r', 'r.id', '=', 'v.record_id')
            ->where('v.key_id', $key)
            ->where('v.value_hash', ValueHash::of($did))
            ->orderByDesc('v.sort_at')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->pluck('r.did')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Which of $dids $viewer's records of this kind point at.
     *
     * @param  array<int, string>  $dids
     * @return array<int, string>
     */
    protected function pointingFrom(string $kind, string $viewer, array $dids): array
    {
        $key = $this->key($kind);

        if ($key === null) {
            return [];
        }

        $byHash = [];

        foreach ($dids as $did) {
            $byHash[ValueHash::of($did)] = $did;
        }

        return app(RecordValues::class)->visible()
            ->where('did_hash', ValueHash::of($viewer))
            ->where('key_id', $key)
            ->whereIn('value_hash', array_keys($byHash))
            ->pluck('value_hash')
            ->map(fn ($hash) => $byHash[(int) $hash])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Which of $dids have a record of this kind pointing at $viewer.
     *
     * @param  array<int, string>  $dids
     * @return array<int, string>
     */
    protected function pointingAt(string $kind, string $viewer, array $dids): array
    {
        $key = $this->key($kind);

        if ($key === null) {
            return [];
        }

        $hashes = array_map(fn (string $did) => ValueHash::of($did), $dids);

        return app(RecordValues::class)->visibleAs('v')
            ->join('atp_records as r', 'r.id', '=', 'v.record_id')
            ->where('v.key_id', $key)
            ->where('v.value_hash', ValueHash::of($viewer))
            ->whereIn('v.did_hash', $hashes)
            ->pluck('r.did')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The index key of a relation's field, or null when the app has none.
     */
    protected function key(string $kind): ?int
    {
        $relation = self::relation($kind);

        if ($relation === null) {
            return null;
        }

        $values = app(RecordValues::class);
        $field = $values->field($relation['collection'], $relation['path']);

        return $field instanceof Field ? $values->keyId($field) : null;
    }

    protected function connection(): Connection
    {
        return DB::connection(config('atp-appview.index.connection'));
    }
}
