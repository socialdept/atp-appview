<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Something done to an account: a ban, needs review, or a takedown on a PDS
 * the operator runs, by an operator or because of a label. Lifted actions
 * keep their row, so the table is also the record of moderation.
 *
 * @property int $id
 * @property string $did
 * @property string $action
 * @property string $source
 * @property string|null $reason
 * @property string|null $label_src
 * @property string|null $label_val
 * @property string|null $actor
 * @property string|null $pds
 * @property \Illuminate\Support\Carbon|null $prune_at
 * @property bool $prune_cancelled
 * @property \Illuminate\Support\Carbon|null $pruned_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $lifted_at
 * @property string|null $lifted_by
 *
 * @method static Builder<static> active()
 */
class AccountAction extends Model
{
    public const BAN = 'ban';

    public const REVIEW = 'review';

    public const TAKEDOWN = 'takedown';

    public const MANUAL = 'manual';

    public const LABEL = 'label';

    protected $table = 'atp_account_actions';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('atp-appview.index.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'prune_at' => 'datetime',
            'prune_cancelled' => 'boolean',
            'pruned_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * Actions in force.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('lifted_at');
    }
}
