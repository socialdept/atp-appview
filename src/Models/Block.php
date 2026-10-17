<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpAppview\Blocklist\BlockKind;
use SocialDept\AtpAppview\Blocklist\Patterns;

/**
 * A rule that keeps matching records out of the index.
 *
 * @property int $id
 * @property BlockKind $kind
 * @property string $subject A DID, or a PDS host pattern such as *.spam.example
 * @property string $collection * for every collection, an NSID, or a prefix ending in .*
 * @property string|null $note
 * @property int $blocked_count Records dropped at ingest, approximately
 * @property \Illuminate\Support\Carbon|null $last_blocked_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Block extends Model
{
    public const EVERY_COLLECTION = '*';

    protected $table = 'atp_blocks';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('atp-appview.index.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'kind' => BlockKind::class,
            'blocked_count' => 'integer',
            'last_blocked_at' => 'datetime',
        ];
    }

    /**
     * Whether this rule covers every collection, rather than some.
     */
    public function isBlanket(): bool
    {
        return $this->collection === self::EVERY_COLLECTION;
    }

    public function coversCollection(string $collection): bool
    {
        return Patterns::collection($this->collection, $collection);
    }

    /**
     * Whether a repo hosted at this PDS URL falls under the rule.
     */
    public function coversPds(?string $pds): bool
    {
        return $this->kind === BlockKind::Host && Patterns::host($this->subject, $pds);
    }
}
