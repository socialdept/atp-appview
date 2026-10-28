<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One repo in a backfill.
 *
 * @property int $backfill_id
 * @property string $did
 * @property string|null $method resync (kept what Tap sent), add (new to Tap), readd (removed and added, on a Tap without resync) or pds (read from its PDS, without Tap)
 * @property int $records Records of the collection Tap delivered
 * @property \Illuminate\Support\Carbon|null $delivered_at When the first arrived, or for pds, when it was read
 * @property string|null $error Why Tap or its PDS could not give it, if it never delivered
 */
class BackfillRepo extends Model
{
    public const RESYNC = 'resync';

    public const ADD = 'add';

    public const READD = 'readd';

    public const PDS = 'pds';

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
