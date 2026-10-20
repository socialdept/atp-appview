<?php

namespace SocialDept\AtpAppview\Models\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A datetime that keeps its microseconds when turned into a string. A cursor
 * paginator stores each column it orders by as a string, and the default
 * cast prints whole seconds, so records sharing a second would be skipped
 * on the next page.
 *
 * @implements CastsAttributes<Carbon|null, DateTimeInterface|string|null>
 */
class PreciseDateTime implements CastsAttributes
{
    public const FORMAT = 'Y-m-d H:i:s.u';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC')->settings(['toStringFormat' => self::FORMAT]);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Carbon::parse($value instanceof DateTimeInterface ? $value->format(self::FORMAT) : $value, 'UTC')->format(self::FORMAT);
    }
}
