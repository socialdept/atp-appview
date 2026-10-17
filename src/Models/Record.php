<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A record from the network, as the index last saw it.
 *
 * Deleted records stay as tombstones carrying the delete's revision, so an
 * older event replayed later cannot bring them back. Queries skip them unless
 * asked with withTrashed().
 *
 * @property string $uri
 * @property string $did
 * @property string $collection
 * @property string $rkey
 * @property string|null $cid
 * @property string $rev
 * @property array<string, mixed>|null $record
 * @property \Illuminate\Support\Carbon $indexed_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @method static Builder<static> collection(string $nsid)
 * @method static Builder<static> by(string $did)
 */
class Record extends Model
{
    use SoftDeletes;

    protected $table = 'atp_records';

    protected $primaryKey = 'uri';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('atp-appview.index.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'record' => 'array',
            'indexed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Records in one collection.
     *
     * @param  Builder<static>  $query
     */
    public function scopeCollection(Builder $query, string $nsid): void
    {
        $query->where('collection', $nsid);
    }

    /**
     * Records in one repo.
     *
     * @param  Builder<static>  $query
     */
    public function scopeBy(Builder $query, string $did): void
    {
        $query->where('did', $did);
    }

    /**
     * The repo's identity, when the index has seen it.
     */
    public function identity(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Identity::class, 'did', 'did');
    }
}
