<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account's handle and status, as Tap last reported them.
 *
 * @property string $did
 * @property string|null $handle
 * @property bool $active
 * @property string|null $status
 * @property string|null $pds The repo's PDS, resolved for host block rules
 * @property \Illuminate\Support\Carbon|null $pds_resolved_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Identity extends Model
{
    protected $table = 'atp_identities';

    protected $primaryKey = 'did';

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
            'active' => 'boolean',
            'updated_at' => 'datetime',
            'pds_resolved_at' => 'datetime',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class, 'did', 'did');
    }
}
