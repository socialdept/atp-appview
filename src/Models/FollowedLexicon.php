<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A published lexicon the index follows (atp-appview.lexicons.follow), with
 * the revision in use and any newer version held back for breaking it.
 *
 * @property string $nsid
 * @property string $did The authority's repo
 * @property string $uri Its com.atproto.lexicon.schema record
 * @property string|null $cid
 * @property array<string, mixed> $schema The version in use
 * @property int $revision
 * @property string|null $pending_cid
 * @property array<string, mixed>|null $pending_schema A newer version held back
 * @property array<int, array{nsid: string, level: string, name: string, message: string}>|null $pending_issues The breaking changes that held it back
 * @property \Illuminate\Support\Carbon|null $fetched_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class FollowedLexicon extends Model
{
    protected $table = 'atp_lexicons';

    protected $primaryKey = 'nsid';

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
            'schema' => 'array',
            'pending_schema' => 'array',
            'pending_issues' => 'array',
            'revision' => 'integer',
            'fetched_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Whether a newer version is waiting on a decision.
     */
    public function heldBack(): bool
    {
        return $this->pending_schema !== null;
    }
}
