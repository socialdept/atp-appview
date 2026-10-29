<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use SocialDept\AtpAppview\Models\Casts\PreciseDateTime;

/**
 * A record in a followed space, as the last sync saw it. Read it through
 * SpaceRecordQuery, which asks SpaceAccess whether the viewer may, and not
 * on its own.
 *
 * Deleted records stay as tombstones carrying the delete's revision, as in
 * atp_records. Queries skip them unless asked with withTrashed().
 *
 * @property string $uri  The space record URI
 * @property string $space
 * @property string $did
 * @property string $collection
 * @property string $rkey
 * @property string|null $cid
 * @property string $rev
 * @property array<string, mixed>|null $record
 * @property array{by: array<int, class-string>, original: array<string, mixed>}|null $repair  When an ingest repair rewrote the record
 * @property \Illuminate\Support\Carbon $indexed_at
 * @property \Illuminate\Support\Carbon|null $sort_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class SpaceRecord extends Model
{
    use SoftDeletes;

    protected $table = 'atp_space_records';

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
            'repair' => 'array',
            'indexed_at' => 'datetime',
            'sort_at' => PreciseDateTime::class,
            'deleted_at' => 'datetime',
        ];
    }
}
