<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A label in force on an account or a record, from a labeler or, for a
 * self-label, the record's author.
 *
 * @property int $id
 * @property string $src
 * @property string $uri
 * @property string $subject
 * @property string|null $cid
 * @property string $val
 * @property string $cts
 * @property \Illuminate\Support\Carbon|null $exp
 * @property bool $verified
 * @property bool $self
 *
 * @method static Builder<static> live()
 */
class Label extends Model
{
    protected $table = 'atp_labels';

    public $timestamps = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('atp-appview.index.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'exp' => 'datetime',
            'verified' => 'boolean',
            'self' => 'boolean',
        ];
    }

    /**
     * Labels that have not expired.
     *
     * @param  Builder<static>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('exp')->orWhere('exp', '>', Carbon::now('UTC')));
    }

    /**
     * The label in the shape atproto views carry it.
     *
     * @return array<string, mixed>
     */
    public function toView(): array
    {
        return array_filter([
            'src' => $this->src,
            'uri' => $this->uri,
            'cid' => $this->cid,
            'val' => $this->val,
            'cts' => $this->cts,
            'exp' => $this->exp?->toIso8601ZuluString(),
        ], fn ($value) => $value !== null);
    }
}
