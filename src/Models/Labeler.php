<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A labeler Tack follows, with its app.bsky.labeler.service declaration.
 *
 * @property string $did
 * @property string|null $endpoint
 * @property array<string, mixed>|null $declaration
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Labeler extends Model
{
    protected $table = 'atp_labelers';

    protected $primaryKey = 'did';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('atp-appview.index.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['declaration' => 'array'];
    }

    /**
     * The values this labeler declares.
     *
     * @return array<int, string>
     */
    public function values(): array
    {
        return array_values(array_filter((array) ($this->declaration['policies']['labelValues'] ?? []), 'is_string'));
    }

    /**
     * The declared definition of one value, if any.
     *
     * @return array<string, mixed>|null
     */
    public function definition(string $value): ?array
    {
        foreach ((array) ($this->declaration['policies']['labelValueDefinitions'] ?? []) as $definition) {
            if (is_array($definition) && ($definition['identifier'] ?? null) === $value) {
                return $definition;
            }
        }

        return null;
    }
}
