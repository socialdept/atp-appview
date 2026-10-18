<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One repo in a backfill.
 *
 * @property int $backfill_id
 * @property string $did
 * @property string|null $method resync (kept what Tap sent), add (new to Tap) or readd (removed and added, on a Tap without resync)
 * @property int $records Records of the collection Tap delivered
 * @property \Illuminate\Support\Carbon|null $delivered_at When the first arrived
 * @property string|null $error Why Tap could not fetch it, if it never delivered
 */
class BackfillRepo extends Model
{
    public const RESYNC = 'resync';

    public const ADD = 'add';

    public const READD = 'readd';

    protected $table = 'atp_backfill_repos';

    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('atp-appview.index.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'records' => 'integer'];
    }
}
