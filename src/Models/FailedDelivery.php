<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An event the handlers could not take.
 *
 * @property int $id
 * @property string $kind record or identity
 * @property int|null $event_id Tap's id for the delivery
 * @property string $did
 * @property string|null $collection
 * @property string|null $uri
 * @property array<string, mixed> $payload The event, to rebuild and retry
 * @property string $error_class
 * @property string $error
 * @property int $retries
 * @property \Illuminate\Support\Carbon $failed_at
 * @property \Illuminate\Support\Carbon|null $retried_at
 */
class FailedDelivery extends Model
{
    protected $table = 'atp_failed_deliveries';

    public $timestamps = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('atp-appview.index.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'retries' => 'integer',
            'failed_at' => 'datetime',
            'retried_at' => 'datetime',
        ];
    }
}
