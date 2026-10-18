<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Backfilling a collection the index started ingesting: the repos the relay
 * says hold it, and how it ended.
 *
 * @property int $id
 * @property string $collection
 * @property string $source Where the repos were discovered
 * @property string $status running, finished or failed
 * @property string|null $error Why it failed
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class Backfill extends Model
{
    public const RUNNING = 'running';

    public const FINISHED = 'finished';

    public const FAILED = 'failed';

    protected $table = 'atp_backfills';

    public $timestamps = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('atp-appview.index.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /**
     * @return HasMany<BackfillRepo, $this>
     */
    public function repos(): HasMany
    {
        return $this->hasMany(BackfillRepo::class);
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }
}
